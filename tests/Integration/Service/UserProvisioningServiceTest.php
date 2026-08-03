<?php
namespace OCA\UserVO\Tests\Integration\Service;

use OCA\UserVO\Service\AuditLogService;
use OCA\UserVO\Service\UserProvisioningService;
use OCA\UserVO\UserVOAuth;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use Test\TestCase;

/**
 * Integration tests for UserProvisioningService - real database, VO API
 * mocked via a partial UserVOAuth mock (only fetchAllMembers/
 * fetchUserDataFromVO stubbed; storeUser/syncUserData run for real, so
 * account-creation side effects are genuinely verified, not just the
 * returned array).
 *
 * @group DB
 */
class UserProvisioningServiceTest extends TestCase {
	private const UID_PREFIX = 'zzz_test_provisioning_';

	private UserProvisioningService $service;
	private IDBConnection $connection;
	private IUserManager $userManager;

	protected function setUp(): void {
		parent::setUp();

		$this->connection = \OC::$server->get(IDBConnection::class);
		$this->userManager = \OC::$server->get(IUserManager::class);
		$this->service = new UserProvisioningService(
			$this->connection,
			\OC::$server->get(IGroupManager::class),
			\OC::$server->get(\Psr\Log\LoggerInterface::class),
			\OC::$server->get(AuditLogService::class)
		);

		$this->cleanupTestData();
	}

	protected function tearDown(): void {
		$this->cleanupTestData();
		parent::tearDown();
	}

	private function cleanupTestData(): void {
		// Match regardless of 'backend' value - disableOriginalConstructor() means
		// Base::$backend keeps its class-default '' rather than 'user_vo' unless a
		// test explicitly sets it via reflection, so real inserts from storeUser()
		// may land under either value.
		$qb = $this->connection->getQueryBuilder();
		$qb->delete('user_vo')
			->where($qb->expr()->like('uid', $qb->createNamedParameter(self::UID_PREFIX . '%')))
			->executeStatement();

		foreach ($this->userManager->search(self::UID_PREFIX) as $user) {
			$user->delete();
		}
	}

	/**
	 * Partial mock: only the VO-API-calling methods are stubbed, everything else
	 * (storeUser, syncUserData) is real. The constructor is disabled, so
	 * Base::$backend never gets set to 'user_vo' by UserVOAuth's real constructor -
	 * set it via reflection so storeUser()'s real inserts use the right value.
	 */
	private function backendMock(): UserVOAuth {
		$backend = $this->getMockBuilder(UserVOAuth::class)
			->disableOriginalConstructor()
			->onlyMethods(['fetchAllMembers', 'fetchUserDataFromVO'])
			->getMock();

		$ref = new \ReflectionProperty(\OCA\UserVO\Base::class, 'backend');
		$ref->setAccessible(true);
		$ref->setValue($backend, 'user_vo');

		return $backend;
	}

	// --- searchVOUsers() ---

	public function testSearchVOUsersReturnsErrorWhenMemberListFetchFails(): void {
		$backend = $this->backendMock();
		$backend->method('fetchAllMembers')->willReturn(null);

		$result = $this->service->searchVOUsers('', $backend);

		$this->assertFalse($result['success']);
	}

	public function testSearchVOUsersFiltersBySearchTermIncludingDotToSpaceConversion(): void {
		$backend = $this->backendMock();
		$backend->method('fetchAllMembers')->willReturn([
			['id' => '1', 'name' => 'Doe, John'],
			['id' => '2', 'name' => 'Smith, Jane'],
		]);
		$backend->method('fetchUserDataFromVO')->willReturnCallback(fn($id) => [
			'id' => $id,
			'username' => $id === '1' ? 'john.doe' : 'jane.smith',
			'firstname' => $id === '1' ? 'John' : 'Jane',
			'lastname' => $id === '1' ? 'Doe' : 'Smith',
		]);

		// "john.doe" (dots) must match "Doe, John" via the space-converted fallback.
		$result = $this->service->searchVOUsers('john.doe', $backend);

		$this->assertCount(1, $result['users']);
		$this->assertEquals('john.doe', $result['users'][0]['vo_username']);
	}

	public function testSearchVOUsersSkipsMembersWithoutLoginCredentials(): void {
		$backend = $this->backendMock();
		$backend->method('fetchAllMembers')->willReturn([['id' => '1', 'name' => 'No, Login']]);
		$backend->method('fetchUserDataFromVO')->willReturn(['id' => '1', 'username' => '']);

		$result = $this->service->searchVOUsers('', $backend);

		$this->assertCount(0, $result['users']);
	}

	public function testSearchVOUsersSkipsDeletedMembers(): void {
		$backend = $this->backendMock();
		$backend->method('fetchAllMembers')->willReturn([['id' => '1', 'name' => 'Gone, User']]);
		$backend->method('fetchUserDataFromVO')->willReturn([
			'id' => '1', 'username' => 'gone.user', '_deleted' => true,
		]);

		$result = $this->service->searchVOUsers('', $backend);

		$this->assertCount(0, $result['users']);
	}

	public function testSearchVOUsersReportsExistingNcAccount(): void {
		$uid = self::UID_PREFIX . 'existing_search';
		$qb = $this->connection->getQueryBuilder();
		// A user_vo row alone is enough for the (real, registered) user_vo backend
		// to make this uid resolvable via IUserManager::get() - no need to also
		// create a real NC account, which would in fact fail: UserVOAuth::userExists()
		// would already claim this uid via the row below, and createUser() checks all
		// registered backends before allowing a new account under the same login.
		$qb->insert('user_vo')->values([
			'uid' => $qb->createNamedParameter($uid),
			'backend' => $qb->createNamedParameter('user_vo'),
			'vo_username' => $qb->createNamedParameter('vo.existing'),
		])->executeStatement();

		$backend = $this->backendMock();
		$backend->method('fetchAllMembers')->willReturn([['id' => '1', 'name' => 'Existing, VO']]);
		$backend->method('fetchUserDataFromVO')->willReturn(['id' => '1', 'username' => 'vo.existing']);

		$result = $this->service->searchVOUsers('', $backend);

		$this->assertTrue($result['users'][0]['nc_account_exists']);
		$this->assertEquals($uid, $result['users'][0]['nc_username']);
	}

	public function testSearchVOUsersReportsBackendConflictForUnprovisionedCollidingUsername(): void {
		$uid = self::UID_PREFIX . 'conflict_search';
		// A real local account under a different backend, never touched by user_vo.
		$this->userManager->createUser($uid, 'irrelevant-password-123!');

		$backend = $this->backendMock();
		$backend->method('fetchAllMembers')->willReturn([['id' => '1', 'name' => 'Conflict, User']]);
		$backend->method('fetchUserDataFromVO')->willReturn(['id' => '1', 'username' => $uid]);

		$result = $this->service->searchVOUsers('', $backend);

		$this->assertFalse($result['users'][0]['nc_account_exists'], 'Not a user_vo account - must not be reported as one');
		$this->assertTrue($result['users'][0]['backend_conflict']);
	}

	public function testSearchVOUsersDoesNotReportConflictForANeverProvisionedNonCollidingUser(): void {
		$backend = $this->backendMock();
		$backend->method('fetchAllMembers')->willReturn([['id' => '1', 'name' => 'Fresh, User']]);
		$backend->method('fetchUserDataFromVO')->willReturn(['id' => '1', 'username' => self::UID_PREFIX . 'fresh_nocollision']);

		$result = $this->service->searchVOUsers('', $backend);

		$this->assertFalse($result['users'][0]['nc_account_exists']);
		$this->assertFalse($result['users'][0]['backend_conflict']);
	}

	public function testSearchVOUsersDoesNotReportConflictForAnAlreadyProvisionedUser(): void {
		// Same setup as testSearchVOUsersReportsExistingNcAccount - an
		// already-provisioned user_vo account resolves via user_vo's own
		// backend, so it can never itself be a "different backend" conflict.
		$uid = self::UID_PREFIX . 'existing_no_conflict';
		$qb = $this->connection->getQueryBuilder();
		$qb->insert('user_vo')->values([
			'uid' => $qb->createNamedParameter($uid),
			'backend' => $qb->createNamedParameter('user_vo'),
			'vo_username' => $qb->createNamedParameter('vo.existingnoconflict'),
		])->executeStatement();

		$backend = $this->backendMock();
		$backend->method('fetchAllMembers')->willReturn([['id' => '1', 'name' => 'Existing, NoConflict']]);
		$backend->method('fetchUserDataFromVO')->willReturn(['id' => '1', 'username' => 'vo.existingnoconflict']);

		$result = $this->service->searchVOUsers('', $backend);

		$this->assertTrue($result['users'][0]['nc_account_exists']);
		$this->assertFalse($result['users'][0]['backend_conflict']);
	}

	// --- createAccountFromVO() ---

	public function testCreateAccountFromVOFailsWhenMemberFetchFails(): void {
		$backend = $this->backendMock();
		$backend->method('fetchUserDataFromVO')->willReturn(null);

		$result = $this->service->createAccountFromVO('1', $backend);

		$this->assertFalse($result['success']);
	}

	public function testCreateAccountFromVOFailsWithoutLoginCredentials(): void {
		$backend = $this->backendMock();
		$backend->method('fetchUserDataFromVO')->willReturn(['id' => '1', 'username' => '']);

		$result = $this->service->createAccountFromVO('1', $backend);

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('does not have login', $result['error']);
	}

	public function testCreateAccountFromVOFailsWhenDeleted(): void {
		$backend = $this->backendMock();
		$backend->method('fetchUserDataFromVO')->willReturn([
			'id' => '1', 'username' => self::UID_PREFIX . 'deleted', '_deleted' => true,
		]);

		$result = $this->service->createAccountFromVO('1', $backend);

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('marked as deleted', $result['error']);
	}

	public function testCreateAccountFromVOFailsWhenAccountAlreadyExists(): void {
		$uid = self::UID_PREFIX . 'existing_create';
		$this->userManager->createUser($uid, 'irrelevant-password-123!');

		$backend = $this->backendMock();
		$backend->method('fetchUserDataFromVO')->willReturn(['id' => '1', 'username' => $uid]);

		$result = $this->service->createAccountFromVO('1', $backend);

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('already exists', $result['error']);
	}

	/**
	 * A username colliding with a real account under a *different* backend
	 * (the Database backend here, via createUser()) is a distinct, more
	 * specific situation than "already exists" - the error should say so,
	 * since it's not recoverable by choosing a different VO account (this VO
	 * user genuinely cannot be provisioned under this username at all).
	 */
	public function testCreateAccountFromVOReportsBackendConflictDistinctly(): void {
		$uid = self::UID_PREFIX . 'conflict_create';
		$this->userManager->createUser($uid, 'irrelevant-password-123!');

		$backend = $this->backendMock();
		$backend->method('fetchUserDataFromVO')->willReturn(['id' => '1', 'username' => $uid]);

		$result = $this->service->createAccountFromVO('1', $backend);

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('different authentication backend', $result['error']);
		$this->assertTrue($result['backend_conflict']);
		$this->assertEquals('Database', $result['conflicting_backend']);
	}

	public function testCreateAccountFromVOSucceedsAndStoresUser(): void {
		$uid = self::UID_PREFIX . 'newaccount';
		$backend = $this->backendMock();
		$backend->method('fetchUserDataFromVO')->willReturn([
			'id' => '1', 'username' => $uid, 'firstname' => 'New', 'lastname' => 'Account', 'group_ids' => '',
		]);

		$result = $this->service->createAccountFromVO('1', $backend);

		$this->assertTrue($result['success']);
		$this->assertEquals($uid, $result['username']);
		$this->assertEquals(0, $result['groups_synced']);

		$qb = $this->connection->getQueryBuilder();
		$qb->select('uid')->from('user_vo')
			->where($qb->expr()->eq('backend', $qb->createNamedParameter('user_vo')))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		$this->assertNotFalse($qb->executeQuery()->fetch(), 'storeUser() must have actually inserted the row (real method, not mocked)');
	}

	// --- bulkCreateAccounts() ---

	/**
	 * "existing" here is a real different-backend account (created via
	 * IUserManager::createUser(), the Database backend) - through this
	 * partial-mock harness there's no way to fabricate a genuine same-backend
	 * "already exists, no conflict" account (that requires a prior real VO
	 * provisioning/login), so this is unavoidably a backend_conflict and
	 * belongs in 'errors', not 'skipped'. See
	 * testBulkCreateAccountsReportsBackendConflictAsAnErrorNotASkip below for
	 * the dedicated regression coverage of that distinction.
	 */
	public function testBulkCreateAccountsCategorizesResults(): void {
		$existingUid = self::UID_PREFIX . 'existing_bulk';
		$this->userManager->createUser($existingUid, 'irrelevant-password-123!');

		$backend = $this->backendMock();
		$backend->method('fetchUserDataFromVO')->willReturnCallback(function ($id) use ($existingUid) {
			return match ($id) {
				'new' => ['id' => 'new', 'username' => self::UID_PREFIX . 'bulknew', 'group_ids' => ''],
				'existing' => ['id' => 'existing', 'username' => $existingUid],
				'nologin' => ['id' => 'nologin', 'username' => ''],
				default => null,
			};
		});

		$results = $this->service->bulkCreateAccounts(['new', 'existing', 'nologin'], $backend);

		$this->assertCount(1, $results['created']);
		$this->assertCount(0, $results['skipped']);
		$this->assertCount(2, $results['errors']);
	}

	/**
	 * Regression test: a backend_conflict result (a VO username colliding
	 * with an existing non-VO NC account) must be reported as a distinctly
	 * flagged 'errors' entry, not silently bucketed into 'skipped' -
	 * bulkCreateAccounts()'s "already exists" string match used to catch this
	 * too, hiding exactly the identity conflict this release's conflict
	 * detection was built to surface from an admin bulk-provisioning users.
	 */
	public function testBulkCreateAccountsReportsBackendConflictAsAnErrorNotASkip(): void {
		$conflictUid = self::UID_PREFIX . 'conflict_bulk';
		$this->userManager->createUser($conflictUid, 'irrelevant-password-123!');

		$backend = $this->backendMock();
		$backend->method('fetchUserDataFromVO')->willReturn(['id' => '1', 'username' => $conflictUid]);

		$results = $this->service->bulkCreateAccounts(['1'], $backend);

		$this->assertCount(0, $results['skipped'], 'Must not be silently bucketed as a benign skip');
		$this->assertCount(1, $results['errors']);
		$this->assertTrue($results['errors'][0]['backend_conflict']);
		$this->assertStringContainsString('different authentication backend', $results['errors'][0]['error']);
	}
}
