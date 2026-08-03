<?php
namespace OCA\UserVO\Tests\Integration\Cron;

use OCA\UserVO\Cron\GroupSyncSweepJob;
use OCA\UserVO\Service\ApiClient;
use OCA\UserVO\Service\AuditLogService;
use OCA\UserVO\Service\ConfigService;
use OCA\UserVO\Service\GroupNameHarmonizer;
use OCA\UserVO\Service\GroupSyncLedgerService;
use OCA\UserVO\Service\GroupSyncLockService;
use OCA\UserVO\Service\GroupSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * End-to-end integration tests for GroupSyncSweepJob: real database, real
 * GroupSyncService/GroupSyncLedgerService/GroupSyncLockService, only the
 * external VereinOnline API (ApiClient) mocked - via the NC service
 * container override, since the job builds its own UserVOAuth backend
 * internally (same pattern as UserVOAuthGroupCacheTest).
 *
 * @group DB
 */
class GroupSyncSweepJobTest extends TestCase {
	private const VO_GROUP_ID = 'test_sweep_group';
	private const NC_GROUP_ID = 'uservo_test_sweep_group';
	private const UID = 'zzz_test_sweep_user';

	private ?ApiClient $originalApiClient = null;
	private IDBConnection $connection;
	private IGroupManager $groupManager;
	private IUserManager $userManager;
	private GroupSyncLedgerService $ledgerService;
	private GroupSyncLockService $lockService;
	private GroupSyncSweepJob $job;

	protected function setUp(): void {
		parent::setUp();
		$this->connection = \OC::$server->get(IDBConnection::class);
		$this->groupManager = \OC::$server->get(IGroupManager::class);
		$this->userManager = \OC::$server->get(IUserManager::class);
		$this->ledgerService = new GroupSyncLedgerService($this->connection, $this->createMock(LoggerInterface::class));
		$this->lockService = new GroupSyncLockService($this->connection);

		$groupSyncService = new GroupSyncService(
			$this->connection,
			$this->groupManager,
			$this->userManager,
			new GroupNameHarmonizer(),
			$this->lockService,
			$this->ledgerService,
			\OC::$server->get(AuditLogService::class)
		);

		$configService = $this->createMock(ConfigService::class);
		$configService->method('loadConfiguration')->willReturn([
			'api_url' => 'https://vereinonline.org/test-sweep-org',
			'api_username' => 'apiuser',
			'api_password' => 'apipass',
		]);

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			fn ($app, $key, $default = '') => $key === 'enable_group_sync_sweep' ? 'true' : $default
		);

		$this->job = new GroupSyncSweepJob(
			$this->createMock(ITimeFactory::class),
			$config,
			$configService,
			$groupSyncService,
			$this->ledgerService
		);

		$this->cleanupTestData();
	}

	protected function tearDown(): void {
		if ($this->originalApiClient !== null) {
			$original = $this->originalApiClient;
			\OC::$server->registerService(ApiClient::class, fn () => $original);
			$this->originalApiClient = null;
		}
		$this->cleanupTestData();
		parent::tearDown();
	}

	private function cleanupTestData(): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->delete('user_vo_groups')
			->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter(self::VO_GROUP_ID)))
			->executeStatement();

		$qb = $this->connection->getQueryBuilder();
		$qb->delete('user_vo')
			->where($qb->expr()->eq('uid', $qb->createNamedParameter(self::UID)))
			->executeStatement();

		if ($this->groupManager->groupExists(self::NC_GROUP_ID)) {
			$this->groupManager->get(self::NC_GROUP_ID)?->delete();
		}
		if ($this->userManager->userExists(self::UID)) {
			$this->userManager->get(self::UID)?->delete();
		}
	}

	private function mockApiClient(): ApiClient {
		if ($this->originalApiClient === null) {
			$this->originalApiClient = \OC::$server->get(ApiClient::class);
		}
		$mock = $this->createMock(ApiClient::class);
		\OC::$server->registerService(ApiClient::class, fn () => $mock);
		return $mock;
	}

	private function createTestGroupInDB(): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->insert('user_vo_groups')
			->values([
				'vo_group_id' => $qb->createNamedParameter(self::VO_GROUP_ID),
				'vo_group_name' => $qb->createNamedParameter('Test Sweep Group'),
				'nc_group_id' => $qb->createNamedParameter(self::NC_GROUP_ID),
				'deleted_in_vo' => $qb->createNamedParameter(0, \PDO::PARAM_INT),
			])
			->executeStatement();
	}

	/** @return array{0: int, 1: int} [dirty_seq, clean_seq] */
	private function readSeqs(): array {
		$qb = $this->connection->getQueryBuilder();
		$row = $qb->select('dirty_seq', 'clean_seq')
			->from('user_vo_groups')
			->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter(self::VO_GROUP_ID)))
			->executeQuery()->fetch();
		return [(int)$row['dirty_seq'], (int)$row['clean_seq']];
	}

	private function runJob(): void {
		$ref = new \ReflectionMethod(GroupSyncSweepJob::class, 'run');
		$ref->setAccessible(true);
		$ref->invoke($this->job, null);
	}

	/**
	 * Direct group_user table read, deliberately bypassing IGroup::getUsers()
	 * - it caches membership in-process on the Group object, and stable28's
	 * Group::addUser() has a cache-staleness quirk (`if ($this->users)`
	 * treats a freshly-created group's empty array as falsy and skips
	 * updating the cache) that a getUsers() call can observe as a stale
	 * empty membership list despite the row already being written.
	 */
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

	/**
	 * The whole point of the sweep, in one test: a group left dirty (e.g. by
	 * a login-triggered sync that was skipped due to lock contention) is
	 * actually converged - membership ends correct, and the ledger ends
	 * clean - not just re-flagged and left for someone else.
	 */
	public function testSweepRepairsAGroupLeftDirtyByASkippedSync(): void {
		$this->groupManager->createGroup(self::NC_GROUP_ID);
		$this->createTestGroupInDB();
		if (!$this->userManager->userExists(self::UID)) {
			$this->userManager->createUser(self::UID, 'ATestPassword123!');
		}
		// user_vo says this user belongs to the group, but NC membership was
		// never applied - exactly what a skipped/lock-contended sync leaves
		// behind.
		$qb = $this->connection->getQueryBuilder();
		$qb->insert('user_vo')->values([
			'uid' => $qb->createNamedParameter(self::UID),
			'backend' => $qb->createNamedParameter('user_vo'),
			'vo_group_ids' => $qb->createNamedParameter(self::VO_GROUP_ID),
		])->executeStatement();

		$this->ledgerService->markDirty([self::VO_GROUP_ID]);

		$apiClient = $this->mockApiClient();
		$apiClient->method('makeRequest')->willReturn([
			['id' => self::VO_GROUP_ID, 'name' => 'Test Sweep Group', 'parentid' => null, 'pos' => 1],
		]);

		$this->runJob();

		$this->assertTrue($this->isUserInNcGroup(self::UID, self::NC_GROUP_ID), 'The sweep must actually apply the missed membership change');

		[$dirty, $clean] = $this->readSeqs();
		$this->assertSame($dirty, $clean, 'A successfully repaired group must end up clean, not still flagged');
	}

	public function testSweepLeavesGroupDirtyWhenLeaseContended(): void {
		$this->groupManager->createGroup(self::NC_GROUP_ID);
		$this->createTestGroupInDB();
		$this->ledgerService->markDirty([self::VO_GROUP_ID]);

		$token = $this->lockService->tryAcquire(self::VO_GROUP_ID);
		$this->assertNotNull($token);

		try {
			$this->runJob();
		} finally {
			$this->lockService->release(self::VO_GROUP_ID, $token);
		}

		[$dirty, $clean] = $this->readSeqs();
		$this->assertGreaterThan($clean, $dirty, 'A lease-contended group must stay dirty for the next tick, not get falsely cleared by a failed attempt');
	}

	public function testDoesNothingWhenNothingIsDirty(): void {
		$this->groupManager->createGroup(self::NC_GROUP_ID);
		$this->createTestGroupInDB();

		// No ApiClient mock registered at all - if the job tried to build a
		// backend and call the API, this would either error or hang.
		$this->runJob();

		[$dirty, $clean] = $this->readSeqs();
		$this->assertSame(0, $dirty);
		$this->assertSame(0, $clean);
	}
}
