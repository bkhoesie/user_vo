<?php
namespace OCA\UserVO\Tests\Integration\Service;

use OCA\UserVO\Service\UserSyncService;
use OCA\UserVO\UserVOAuth;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * Integration tests for UserSyncService's database-heavy methods
 * (previewVOUsers, syncSelectedUsers success path) - real database, VO API
 * fully mocked via UserVOAuth. Complements the existing unit tests, which
 * deliberately only cover the pure-validation paths (see that file's own
 * note on why DB-heavy methods belong here instead).
 *
 * @group DB
 */
class UserSyncServiceTest extends TestCase {
	private const UID_PREFIX = 'zzz_test_usersync_';

	private UserSyncService $service;
	private IDBConnection $connection;

	protected function setUp(): void {
		parent::setUp();

		$this->connection = \OC::$server->get(IDBConnection::class);
		$this->service = new UserSyncService(
			$this->connection,
			\OC::$server->get(IConfig::class),
			\OC::$server->get(LoggerInterface::class)
		);

		$this->cleanupTestData();
	}

	protected function tearDown(): void {
		$this->cleanupTestData();
		parent::tearDown();
	}

	private function cleanupTestData(): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->delete('user_vo')
			->where($qb->expr()->like('uid', $qb->createNamedParameter(self::UID_PREFIX . '%')))
			->executeStatement();
	}

	private function insertUser(string $uid, ?string $voUserId, string $backend = 'user_vo'): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->insert('user_vo')->values([
			'uid' => $qb->createNamedParameter($uid),
			'backend' => $qb->createNamedParameter($backend),
			'vo_user_id' => $qb->createNamedParameter($voUserId),
		])->executeStatement();
	}

	// --- previewVOUsers() ---

	public function testPreviewVOUsersSkipsDuplicateMarkedEntries(): void {
		$this->insertUser(self::UID_PREFIX . 'dup!duplicate', '1');
		$backend = $this->createMock(UserVOAuth::class);

		$result = $this->service->previewVOUsers($backend);

		$this->assertTrue($result['success']);
		$uids = array_column($result['results'], 'uid');
		$this->assertNotContains(self::UID_PREFIX . 'dup!duplicate', $uids);
	}

	public function testPreviewVOUsersReportsSkippedWhenNoVoUserId(): void {
		$this->insertUser(self::UID_PREFIX . 'nid', null);
		$backend = $this->createMock(UserVOAuth::class);

		$result = $this->service->previewVOUsers($backend);

		$row = $this->findResultRow($result, self::UID_PREFIX . 'nid');
		$this->assertEquals('skipped', $row['status']);
	}

	public function testPreviewVOUsersReportsFailedWhenVoFetchReturnsNull(): void {
		$this->insertUser(self::UID_PREFIX . 'nf', '99');
		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchUserDataFromVO')->willReturn(null);

		$result = $this->service->previewVOUsers($backend);

		$row = $this->findResultRow($result, self::UID_PREFIX . 'nf');
		$this->assertEquals('failed', $row['status']);
	}

	public function testPreviewVOUsersReportsSanitizedErrorMessage(): void {
		$this->insertUser(self::UID_PREFIX . 'err', '1');
		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchUserDataFromVO')->willReturn(['_error' => 'no_login']);

		$result = $this->service->previewVOUsers($backend);

		$row = $this->findResultRow($result, self::UID_PREFIX . 'err');
		$this->assertEquals('failed', $row['status']);
		$this->assertEquals('No login credentials in VO', $row['message']);
	}

	public function testPreviewVOUsersReportsDeletedStatus(): void {
		$this->insertUser(self::UID_PREFIX . 'del', '1');
		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchUserDataFromVO')->willReturn([
			'username' => 'x', 'firstname' => 'A', 'lastname' => 'B', '_deleted' => true,
		]);

		$result = $this->service->previewVOUsers($backend);

		$row = $this->findResultRow($result, self::UID_PREFIX . 'del');
		$this->assertEquals('deleted', $row['status']);
	}

	public function testPreviewVOUsersPhotoStatusIgnoresAnonymousPlaceholder(): void {
		$this->insertUser(self::UID_PREFIX . 'anon', '1');
		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchUserDataFromVO')->willReturn([
			'username' => 'x', 'firstname' => 'A', 'lastname' => 'B', 'foto' => 'anonym.gif',
		]);

		$result = $this->service->previewVOUsers($backend);

		$row = $this->findResultRow($result, self::UID_PREFIX . 'anon');
		$this->assertEquals('-', $row['photo_status']);
	}

	public function testPreviewVOUsersPhotoStatusAvailableForRealPhoto(): void {
		$this->insertUser(self::UID_PREFIX . 'photo', '1');
		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchUserDataFromVO')->willReturn([
			'username' => 'x', 'firstname' => 'A', 'lastname' => 'B', 'foto' => 'real.jpg',
		]);

		$result = $this->service->previewVOUsers($backend);

		$row = $this->findResultRow($result, self::UID_PREFIX . 'photo');
		$this->assertEquals('Available in VO', $row['photo_status']);
	}

	private function findResultRow(array $result, string $uid): array {
		foreach ($result['results'] as $row) {
			if ($row['uid'] === $uid) {
				return $row;
			}
		}
		$this->fail("No result row for uid $uid");
	}

	// --- syncSelectedUsers() success path (only validation-error paths are unit-tested) ---

	public function testSyncSelectedUsersSucceedsForKnownUser(): void {
		$uid = self::UID_PREFIX . 'sync1';
		$this->insertUser($uid, '1');

		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchUserDataFromVO')->willReturn([
			'username' => $uid, 'firstname' => 'Sync', 'lastname' => 'Test',
		]);
		$backend->method('syncUserData')->willReturn(['success' => true, 'photo_error' => null]);

		$result = $this->service->syncSelectedUsers([$uid], $backend);

		$this->assertTrue($result['success']);
		$this->assertEquals(1, $result['summary']['synced'], "syncSelectedUsers() uses 'synced' as the summary key (differs from syncAllUsers()'s 'success')");
	}

	public function testSyncSelectedUsersReportsFailureFromBackend(): void {
		$uid = self::UID_PREFIX . 'sync2';
		$this->insertUser($uid, '1');

		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchUserDataFromVO')->willReturn(null);

		$result = $this->service->syncSelectedUsers([$uid], $backend);

		$this->assertTrue($result['success'], 'Envelope success is true even though the individual user failed');
		$this->assertEquals(1, $result['summary']['failed']);
	}

	// --- syncAllUsers(): last_full_user_sync_at stamping (drives
	// GroupManagementService's possibly_stale flag - see that class) ---

	public function testSyncAllUsersStampsLastFullUserSyncTimestamp(): void {
		$config = \OC::$server->get(IConfig::class);
		$config->deleteAppValue('user_vo', 'last_full_user_sync_at');

		$uid = self::UID_PREFIX . 'fullsync1';
		$this->insertUser($uid, '1');

		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchUserDataFromVO')->willReturn([
			'username' => $uid, 'firstname' => 'Full', 'lastname' => 'Sync',
		]);
		$backend->method('syncUserData')->willReturn(['success' => true, 'photo_error' => null]);

		$before = time();
		$this->service->syncAllUsers($backend);
		$after = time();

		$stamped = (int)$config->getAppValue('user_vo', 'last_full_user_sync_at', '0');
		$this->assertGreaterThanOrEqual($before, $stamped);
		$this->assertLessThanOrEqual($after, $stamped);

		$config->deleteAppValue('user_vo', 'last_full_user_sync_at');
	}

	/**
	 * Regression test: processSyncLoop() (and so syncAllUsers()) returns a
	 * top-level 'success' => true even when every single user failed - see
	 * testSyncSelectedUsersReportsFailureFromBackend() for the same
	 * envelope-vs-per-user distinction. A VO outage mid-sync must not stamp
	 * this timestamp: nothing was actually refreshed, so every managed
	 * group's "possibly stale" flag would be falsely cleared by a sync that
	 * accomplished nothing.
	 */
	public function testSyncAllUsersDoesNotStampTimestampWhenAUserFails(): void {
		$config = \OC::$server->get(IConfig::class);
		$config->deleteAppValue('user_vo', 'last_full_user_sync_at');

		$uid = self::UID_PREFIX . 'fullsyncfail1';
		$this->insertUser($uid, '1');

		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchUserDataFromVO')->willReturn(null);

		$result = $this->service->syncAllUsers($backend);

		// syncAllUsers() syncs every user_vo row, not just the one this test
		// inserted - other rows may already exist in this environment, so
		// only assert that a failure was recorded, not an exact count.
		$this->assertGreaterThan(0, $result['summary']['failed'], 'Precondition: the sync must have actually recorded a failure');
		$this->assertEquals('', $config->getAppValue('user_vo', 'last_full_user_sync_at', ''));
	}

	/**
	 * Regression test for a second issue an independent review found in the
	 * first fix: 'failed' also counts real-but-permanent per-user states
	 * (deleted in VO, no VO login credentials) that processSyncLoop()
	 * intentionally still calls successful for summary purposes - those
	 * aren't sync failures, and gating the stamp on 'failed' would mean an
	 * install with even one such member could never stamp this timestamp
	 * again, silently disabling the entire staleness feature. Deleted-in-VO
	 * is exercised here; 'no_login' goes through the same api_failures
	 * exclusion in processSyncLoop() and isn't separately re-tested.
	 */
	public function testSyncAllUsersStampsTimestampEvenWhenAUserIsDeletedInVO(): void {
		$config = \OC::$server->get(IConfig::class);
		$config->deleteAppValue('user_vo', 'last_full_user_sync_at');

		$uid = self::UID_PREFIX . 'fullsyncdeleted1';
		$this->insertUser($uid, '1');

		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchUserDataFromVO')->willReturn([
			'username' => $uid, 'firstname' => 'Gone', 'lastname' => 'User', '_deleted' => true,
		]);
		$backend->method('syncUserData')->willReturn(['success' => true, 'photo_error' => null]);

		$before = time();
		$result = $this->service->syncAllUsers($backend);
		$after = time();

		$this->assertGreaterThan(0, $result['summary']['failed'], 'Precondition: deleted-in-VO is still counted under the broad failed count');
		$this->assertEquals(0, $result['summary']['api_failures'], 'Precondition: but not as an api_failure');

		$stamped = (int)$config->getAppValue('user_vo', 'last_full_user_sync_at', '0');
		$this->assertGreaterThanOrEqual($before, $stamped);
		$this->assertLessThanOrEqual($after, $stamped);

		$config->deleteAppValue('user_vo', 'last_full_user_sync_at');
	}

	/**
	 * Only syncAllUsers() (a full sweep of every known user) may stamp this
	 * timestamp - syncSelectedUsers() only refreshes some users, and
	 * stamping it here would give every managed group a false "confirmed
	 * fresh" signal even for groups whose actual members weren't part of
	 * this selective sync.
	 */
	public function testSyncSelectedUsersDoesNotStampLastFullUserSyncTimestamp(): void {
		$config = \OC::$server->get(IConfig::class);
		$config->deleteAppValue('user_vo', 'last_full_user_sync_at');

		$uid = self::UID_PREFIX . 'selective1';
		$this->insertUser($uid, '1');

		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchUserDataFromVO')->willReturn([
			'username' => $uid, 'firstname' => 'Selective', 'lastname' => 'Sync',
		]);
		$backend->method('syncUserData')->willReturn(['success' => true, 'photo_error' => null]);

		$this->service->syncSelectedUsers([$uid], $backend);

		$this->assertEquals('', $config->getAppValue('user_vo', 'last_full_user_sync_at', ''));
	}
}
