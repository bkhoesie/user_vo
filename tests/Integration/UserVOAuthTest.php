<?php
namespace OCA\UserVO\Tests\Integration;

use OCA\UserVO\Service\ApiClient;
use OCA\UserVO\UserVOAuth;
use OCP\IDBConnection;
use OCP\IGroupManager;
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

	private const GROUP_PREFIX = 'test_uva_';

	private IDBConnection $connection;
	private IUserManager $userManager;
	private IGroupManager $groupManager;

	protected function setUp(): void {
		parent::setUp();
		$this->connection = \OC::$server->get(IDBConnection::class);
		$this->userManager = \OC::$server->get(IUserManager::class);
		$this->groupManager = \OC::$server->get(IGroupManager::class);
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

		$qb = $this->connection->getQueryBuilder();
		$qb->delete('user_vo_groups')
			->where($qb->expr()->like('vo_group_id', $qb->createNamedParameter(self::GROUP_PREFIX . '%')))
			->executeStatement();

		foreach ($this->groupManager->search('uservo_' . self::GROUP_PREFIX) as $group) {
			$group->delete();
		}
	}

	private function createManagedGroupWithRealNcGroup(string $voGroupId): string {
		$ncGroupId = 'uservo_' . $voGroupId;
		$this->groupManager->createGroup($ncGroupId);
		$qb = $this->connection->getQueryBuilder();
		$qb->insert('user_vo_groups')
			->values([
				'vo_group_id' => $qb->createNamedParameter($voGroupId),
				'vo_group_name' => $qb->createNamedParameter('Test UserVOAuth Group'),
				'nc_group_id' => $qb->createNamedParameter($ncGroupId),
				'deleted_in_vo' => $qb->createNamedParameter(0, \PDO::PARAM_INT),
			])
			->executeStatement();
		return $ncGroupId;
	}

	/** @return array{0: int, 1: int} [dirty_seq, clean_seq] */
	private function readSeqs(string $voGroupId): array {
		$qb = $this->connection->getQueryBuilder();
		$row = $qb->select('dirty_seq', 'clean_seq')
			->from('user_vo_groups')
			->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter($voGroupId)))
			->executeQuery()->fetch();
		return [(int)$row['dirty_seq'], (int)$row['clean_seq']];
	}

	private function isUserInNcGroup(string $uid, string $gid): bool {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('uid')->from('group_user')
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('gid', $qb->createNamedParameter($gid)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row !== false;
	}

	private function invokeSyncUserGroupsOnLogin(UserVOAuth $auth, string $uid, array $voUserData): void {
		$ref = new \ReflectionMethod(UserVOAuth::class, 'syncUserGroupsOnLogin');
		$ref->setAccessible(true);
		$ref->invoke($auth, $uid, $voUserData);
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

	// --- syncUserData() / updateVOMetadata() failure propagation ---

	/**
	 * updateVOMetadata() catches its own DB failures and reports them via
	 * its bool return rather than throwing - syncUserData() must propagate
	 * that into its own 'success', not hardcode true just because nothing
	 * threw. See UserSyncService::processSyncLoop()'s use of 'success'.
	 */
	public function testSyncUserDataReportsFailureWhenMetadataWriteFails(): void {
		$uid = self::UID_PREFIX . 'metadatawritefails';
		$this->userManager->createUser($uid, 'irrelevant-password-123!');

		$auth = $this->getMockBuilder(UserVOAuth::class)
			->setConstructorArgs(['https://vo.test/org', 'apiuser', 'apipass'])
			->onlyMethods(['updateVOMetadata'])
			->getMock();
		$auth->method('updateVOMetadata')->willReturn(false);

		$result = $auth->syncUserData($uid, ['id' => '999', 'username' => $uid, 'firstname' => 'Test', 'lastname' => 'User']);

		$this->assertFalse($result['success'], 'success must reflect the metadata write outcome, not just "did syncUserData() throw"');
		$this->assertFalse($result['nc_user_missing'] ?? false, 'the NC account does exist here - this must not be misreported as the other failure mode');
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

	// --- syncUserGroupsOnLogin(): diff-only, and the self-heal it enables ---

	/**
	 * $oldVoGroupIds is derived from the user's actual current NC group
	 * memberships, not a cached VO snapshot - when nothing has changed on
	 * either side, the symmetric diff against $newVoGroupIds is empty, and
	 * the whole group-sync batch (a live VO call per group) must be skipped
	 * entirely, not just produce a no-op result. Asserted via the mocked
	 * ApiClient never being called at all, rather than indirectly via the
	 * ledger - see UserVOAuthDirtyMarkingTest for that side's own coverage.
	 */
	public function testLoginGroupSyncSkipsAllWorkWhenMembershipUnchanged(): void {
		$voGroupId = self::GROUP_PREFIX . 'diffonly_nochange';
		$ncGroupId = $this->createManagedGroupWithRealNcGroup($voGroupId);

		$uid = self::UID_PREFIX . 'diffonly_nochange';
		$this->userManager->createUser($uid, 'irrelevant-password-123!');
		$this->groupManager->get($ncGroupId)->addUser($this->userManager->get($uid));
		$this->assertTrue($this->isUserInNcGroup($uid, $ncGroupId), 'Precondition: user is already a member');

		$apiClient = $this->getMockBuilder(ApiClient::class)->disableOriginalConstructor()->getMock();
		$apiClient->expects($this->never())->method('makeRequest');
		$auth = new UserVOAuth('https://vo.test/org', 'apiuser', 'apipass');
		$ref = new \ReflectionProperty(UserVOAuth::class, 'apiClient');
		$ref->setAccessible(true);
		$ref->setValue($auth, $apiClient);

		// Same group membership VO already reports - nothing changed.
		$this->invokeSyncUserGroupsOnLogin($auth, $uid, ['group_ids' => $voGroupId]);

		[$dirty, $clean] = $this->readSeqs($voGroupId);
		$this->assertSame($dirty, $clean, 'No group sync must have run at all - nothing should have been dirtied either');
	}

	/**
	 * $oldVoGroupIds being derived from *live* NC group membership (not a
	 * cached value) is specifically what makes a manually-edited NC
	 * membership self-heal on the affected user's next login: VO's own
	 * group_ids didn't change, but the diff against actual NC membership
	 * still comes out non-empty, so the group still gets synced and the
	 * manual edit gets corrected. Diffing against a cached snapshot instead
	 * would silently break this property - see the class-level docblock on
	 * syncUserGroupsOnLogin() in lib/UserVOAuth.php.
	 */
	public function testLoginGroupSyncSelfHealsAfterManualNcGroupRemoval(): void {
		$voGroupId = self::GROUP_PREFIX . 'selfheal';
		$ncGroupId = $this->createManagedGroupWithRealNcGroup($voGroupId);

		$uid = self::UID_PREFIX . 'selfheal';
		$voUserId = 'vo_selfheal_user';
		$this->userManager->createUser($uid, 'irrelevant-password-123!');
		// VO's own record already links this uid to a VO member id - as if
		// set up by a prior real login - but NC membership was manually
		// removed since (an admin edit VO never saw).
		$qb = $this->connection->getQueryBuilder();
		$qb->insert('user_vo')->values([
			'uid' => $qb->createNamedParameter($uid),
			'backend' => $qb->createNamedParameter('user_vo'),
			'vo_user_id' => $qb->createNamedParameter($voUserId),
		])->executeStatement();
		$this->assertFalse($this->isUserInNcGroup($uid, $ncGroupId), 'Precondition: user is NOT currently an NC member of the group');

		$auth = $this->authWithMockedApiClient(function (string $url) use ($voGroupId, $voUserId) {
			if (str_contains($url, 'GetGroups')) {
				return [['id' => $voGroupId, 'name' => 'Test UserVOAuth Group', 'parentid' => null, 'pos' => 1]];
			}
			if (str_contains($url, 'GetMembers')) {
				return [['id' => $voUserId, 'name' => 'Self, Heal']];
			}
			return null;
		});

		// VO still reports this group as membership for this user.
		$this->invokeSyncUserGroupsOnLogin($auth, $uid, ['group_ids' => $voGroupId]);

		$this->assertTrue($this->isUserInNcGroup($uid, $ncGroupId), 'The manually-removed membership must have been restored by this login\'s sync');
	}
}
