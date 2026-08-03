<?php
namespace OCA\UserVO\Tests\Integration;

use OCA\UserVO\Service\ApiClient;
use OCA\UserVO\UserVOAuth;
use OCP\IDBConnection;
use OCP\IUserManager;
use Test\TestCase;

/**
 * Integration tests for UserVOAuth's login flow (checkCanonicalPassword, via
 * the public checkPassword() it inherits from Base) - real database, mocked
 * VereinOnline API. Uses a distinctive uid prefix since UserVOAuth hardcodes
 * backend='user_vo', the same bucket real synced VO users live in when run
 * against a configured environment (e.g. stable33) - never touch unprefixed rows.
 *
 * @group DB
 */
class UserVOAuthTest extends TestCase {
	private const UID_PREFIX = 'zzz_test_uservoauth_';

	private IDBConnection $connection;
	private IUserManager $userManager;

	protected function setUp(): void {
		parent::setUp();
		$this->connection = \OC::$server->get(IDBConnection::class);
		$this->userManager = \OC::$server->get(IUserManager::class);
		$this->cleanupTestData();
	}

	protected function tearDown(): void {
		$this->cleanupTestData();
		parent::tearDown();
	}

	private function cleanupTestData(): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->delete('user_vo')
			->where($qb->expr()->eq('backend', $qb->createNamedParameter('user_vo')))
			->andWhere($qb->expr()->like('uid', $qb->createNamedParameter(self::UID_PREFIX . '%')))
			->executeStatement();

		foreach ($this->userManager->search(self::UID_PREFIX) as $user) {
			$user->delete();
		}
	}

	private function authWithMockedApiClient(callable $makeRequestCallback): UserVOAuth {
		$auth = new UserVOAuth('https://vo.test/org', 'apiuser', 'apipass');

		$apiClient = $this->getMockBuilder(ApiClient::class)
			->disableOriginalConstructor()
			->getMock();
		$apiClient->method('makeRequest')->willReturnCallback($makeRequestCallback);

		$ref = new \ReflectionProperty(UserVOAuth::class, 'apiClient');
		$ref->setAccessible(true);
		$ref->setValue($auth, $apiClient);

		return $auth;
	}

	private function userExistsInDb(string $uid): bool {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('uid')->from('user_vo')
			->where($qb->expr()->eq('backend', $qb->createNamedParameter('user_vo')))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		return $qb->executeQuery()->fetch() !== false;
	}

	public function testSuccessfulLoginReturnsUidAndStoresUser(): void {
		$uid = self::UID_PREFIX . 'success';

		$auth = $this->authWithMockedApiClient(function ($url, $data) use ($uid) {
			if (str_contains($url, 'VerifyLogin')) {
				return ['999'];
			}
			// GetMember lookup during syncUserData/fetchUserDataFromVO
			return ['id' => '999', 'userlogin' => $uid, 'vorname' => 'Test', 'nachname' => 'User'];
		});

		$result = $auth->checkPassword($uid, 'anypassword');

		$this->assertEquals($uid, $result);
		$this->assertTrue($this->userExistsInDb($uid));
	}

	public function testFailedLoginNoResponseReturnsFalseAndDoesNotStoreUser(): void {
		$uid = self::UID_PREFIX . 'noresponse';

		$auth = $this->authWithMockedApiClient(fn() => null);

		$result = $auth->checkPassword($uid, 'wrongpassword');

		$this->assertFalse($result);
		$this->assertFalse($this->userExistsInDb($uid));
	}

	public function testFailedLoginErrorResponseReturnsFalse(): void {
		$uid = self::UID_PREFIX . 'apierror';

		$auth = $this->authWithMockedApiClient(
			fn() => ['error' => 'Invalid credentials']
		);

		$result = $auth->checkPassword($uid, 'wrongpassword');

		$this->assertFalse($result);
		$this->assertFalse($this->userExistsInDb($uid));
	}

	public function testFailedLoginEmptyIdInResponseReturnsFalse(): void {
		// VerifyLogin's documented failure shape is an array with an empty-string id.
		$uid = self::UID_PREFIX . 'emptyid';

		$auth = $this->authWithMockedApiClient(fn() => ['']);

		$result = $auth->checkPassword($uid, 'wrongpassword');

		$this->assertFalse($result);
	}

	public function testLoginSucceedsEvenWhenPostLoginMemberFetchFails(): void {
		// Authentication itself succeeded via VerifyLogin; a subsequent failure
		// fetching extended profile data must not fail the login.
		$uid = self::UID_PREFIX . 'memberfetchfails';

		$auth = $this->authWithMockedApiClient(function ($url) {
			if (str_contains($url, 'VerifyLogin')) {
				return ['999'];
			}
			return null; // GetMember fails
		});

		$result = $auth->checkPassword($uid, 'anypassword');

		$this->assertEquals($uid, $result, 'A successful VerifyLogin must succeed even if the follow-up GetMember call fails');
		$this->assertTrue($this->userExistsInDb($uid));
	}

	// --- hasBackendConflict() ---

	public function testHasBackendConflictFalseWhenNoAccountExistsAtAll(): void {
		$auth = new UserVOAuth('https://vo.test/org', 'apiuser', 'apipass');
		$this->assertFalse($auth->hasBackendConflict(self::UID_PREFIX . 'nobody'));
	}

	public function testHasBackendConflictFalseForAnAlreadyProvisionedUserVoAccount(): void {
		$uid = self::UID_PREFIX . 'ownaccount';
		$qb = $this->connection->getQueryBuilder();
		$qb->insert('user_vo')->values([
			'uid' => $qb->createNamedParameter($uid),
			'backend' => $qb->createNamedParameter('user_vo'),
		])->executeStatement();

		$auth = new UserVOAuth('https://vo.test/org', 'apiuser', 'apipass');
		$this->assertFalse($auth->hasBackendConflict($uid), 'A uid this app\'s own backend already owns is not a conflict');
	}

	public function testHasBackendConflictTrueForARealAccountUnderADifferentBackend(): void {
		$uid = self::UID_PREFIX . 'otherbackend';
		$this->userManager->createUser($uid, 'irrelevant-password-123!');

		$auth = new UserVOAuth('https://vo.test/org', 'apiuser', 'apipass');
		$this->assertTrue($auth->hasBackendConflict($uid));
	}

	// --- login-time conflict guard (checkCanonicalPassword) ---

	/**
	 * VerifyLogin succeeding must not be enough to proceed if $uid already
	 * belongs to a different backend's real account - see
	 * hasBackendConflict()'s doc-comment for why this is more than cosmetic
	 * (NC core tries every registered backend's checkPassword() regardless
	 * of which one already "owns" a uid).
	 */
	public function testLoginFailsWhenUidBelongsToADifferentBackend(): void {
		$uid = self::UID_PREFIX . 'loginconflict';
		$this->userManager->createUser($uid, 'irrelevant-password-123!');

		$auth = $this->authWithMockedApiClient(fn() => ['999']); // VerifyLogin succeeds

		$result = $auth->checkPassword($uid, 'anypassword');

		$this->assertFalse($result, 'Login must be refused despite valid VO credentials');
		$this->assertFalse($this->userExistsInDb($uid), 'Must not create a user_vo row for a uid this app does not own');
	}

	public function testLoginStillSucceedsForANonConflictingUid(): void {
		// Negative control for the test above - a uid with no pre-existing
		// account at all must still be able to log in normally.
		$uid = self::UID_PREFIX . 'nonconflict';

		$auth = $this->authWithMockedApiClient(function ($url) {
			if (str_contains($url, 'VerifyLogin')) {
				return ['999'];
			}
			return null;
		});

		$result = $auth->checkPassword($uid, 'anypassword');

		$this->assertEquals($uid, $result);
		$this->assertTrue($this->userExistsInDb($uid));
	}
}
