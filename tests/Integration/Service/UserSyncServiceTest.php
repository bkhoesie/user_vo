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

		// Unconditional, not just in the specific tests that set it - a
		// failed assertion partway through one of those would otherwise
		// skip its own inline cleanup and leak a stamped value into
		// whichever test runs next.
		\OC::$server->get(IConfig::class)->deleteAppValue('user_vo', 'last_full_user_sync_at');
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
	 * A VO outage mid-sync must not stamp last_full_user_sync_at - nothing
	 * was actually refreshed. Mocks the real '_error' => 'api_error' shape
	 * (fetchUserDataFromVO() never actually returns a literal null).
	 */
	public function testSyncAllUsersDoesNotStampTimestampWhenAUserFails(): void {
		$config = \OC::$server->get(IConfig::class);
		$config->deleteAppValue('user_vo', 'last_full_user_sync_at');

		$uid = self::UID_PREFIX . 'fullsyncfail1';
		$this->insertUser($uid, '1');

		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchUserDataFromVO')->willReturn(['_error' => 'api_error', '_message' => 'Rate limited']);

		$result = $this->service->syncAllUsers($backend);

		// syncAllUsers() syncs every user_vo row, not just the one this test
		// inserted - other rows may already exist in this environment, so
		// only assert that a failure was recorded, not an exact count.
		$this->assertGreaterThan(0, $result['summary']['api_failures'], 'Precondition: the sync must have actually recorded an API failure');
		$this->assertEquals('', $config->getAppValue('user_vo', 'last_full_user_sync_at', ''));
	}

	/**
	 * An orphaned user_vo row (tracking row survives, NC account gone) is a
	 * permanent state, not a sync failure - must not permanently block the
	 * stamp.
	 */
	public function testSyncAllUsersStampsTimestampEvenWhenAUserHasNoNcAccount(): void {
		$config = \OC::$server->get(IConfig::class);
		$config->deleteAppValue('user_vo', 'last_full_user_sync_at');

		$uid = self::UID_PREFIX . 'fullsyncorphaned1';
		$this->insertUser($uid, '1');

		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchUserDataFromVO')->willReturn([
			'username' => $uid, 'firstname' => 'Orphaned', 'lastname' => 'Row',
		]);
		$backend->method('syncUserData')->willReturn(['success' => false, 'photo_error' => null, 'nc_user_missing' => true]);

		$before = time();
		$result = $this->service->syncAllUsers($backend);
		$after = time();

		$this->assertGreaterThan(0, $result['summary']['failed'], 'Precondition: the orphaned row is still counted under the broad failed count');
		$this->assertEquals(0, $result['summary']['api_failures'], 'Precondition: but not as an api_failure');

		$stamped = (int)$config->getAppValue('user_vo', 'last_full_user_sync_at', '0');
		$this->assertGreaterThanOrEqual($before, $stamped);
		$this->assertLessThanOrEqual($after, $stamped);

		$config->deleteAppValue('user_vo', 'last_full_user_sync_at');
	}

	/** The other side: success:false without nc_user_missing must still block the stamp. */
	public function testSyncAllUsersDoesNotStampTimestampOnAGenuineSyncUserDataFailure(): void {
		$config = \OC::$server->get(IConfig::class);
		$config->deleteAppValue('user_vo', 'last_full_user_sync_at');

		$uid = self::UID_PREFIX . 'fullsyncgenuinefail1';
		$this->insertUser($uid, '1');

		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchUserDataFromVO')->willReturn([
			'username' => $uid, 'firstname' => 'Genuine', 'lastname' => 'Failure',
		]);
		$backend->method('syncUserData')->willReturn(['success' => false, 'photo_error' => null]);

		$result = $this->service->syncAllUsers($backend);

		$this->assertGreaterThan(0, $result['summary']['api_failures'], 'Precondition: a plain success:false without nc_user_missing must still count as an api_failure');
		$this->assertEquals('', $config->getAppValue('user_vo', 'last_full_user_sync_at', ''));
	}

	/**
	 * Deleted-in-VO is a permanent per-user state, not a sync failure - must
	 * not block the stamp. ('no_login' goes through the same exclusion and
	 * isn't separately re-tested.)
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

		$this->assertEquals('deleted', $this->findResultRow($result, $uid)['status'], 'Precondition: this uid specifically took the deleted branch, not some other failure branch');
		$this->assertGreaterThan(0, $result['summary']['failed'], 'Precondition: deleted-in-VO is still counted under the broad failed count');
		$this->assertEquals(0, $result['summary']['api_failures'], 'Precondition: but not as an api_failure');

		$stamped = (int)$config->getAppValue('user_vo', 'last_full_user_sync_at', '0');
		$this->assertGreaterThanOrEqual($before, $stamped);
		$this->assertLessThanOrEqual($after, $stamped);

		$config->deleteAppValue('user_vo', 'last_full_user_sync_at');
	}

	/**
	 * A user who is both deleted-in-VO and has an orphaned user_vo row
	 * (nc_user_missing) must still be excluded like the plain orphaned-row
	 * case above - a nonexistent uid can't be a group member, so its stale
	 * vo_group_ids can't matter.
	 */
	public function testSyncAllUsersStampsTimestampWhenAUserIsDeletedInVOAndOrphaned(): void {
		$config = \OC::$server->get(IConfig::class);
		$config->deleteAppValue('user_vo', 'last_full_user_sync_at');

		$uid = self::UID_PREFIX . 'fullsyncdeletedorphaned1';
		$this->insertUser($uid, '1');

		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchUserDataFromVO')->willReturn([
			'username' => $uid, 'firstname' => 'Gone', 'lastname' => 'AndOrphaned', '_deleted' => true,
		]);
		$backend->method('syncUserData')->willReturn(['success' => false, 'photo_error' => null, 'nc_user_missing' => true]);

		$before = time();
		$result = $this->service->syncAllUsers($backend);
		$after = time();

		$this->assertEquals('deleted', $this->findResultRow($result, $uid)['status'], 'Precondition: this uid took the deleted branch, not some other failure branch');
		$this->assertEquals(0, $result['summary']['api_failures'], 'Precondition: deleted + orphaned must not count as an api_failure');

		$stamped = (int)$config->getAppValue('user_vo', 'last_full_user_sync_at', '0');
		$this->assertGreaterThanOrEqual($before, $stamped);
		$this->assertLessThanOrEqual($after, $stamped);

		$config->deleteAppValue('user_vo', 'last_full_user_sync_at');
	}

	/**
	 * Deleted-in-VO whose metadata write itself failed (not orphaned) is the
	 * one sub-case that must still block the stamp: GroupSyncService doesn't
	 * filter membership by deleted-in-VO, so a stale vo_group_ids could be
	 * wrongly honored for a uid that does still exist in NC.
	 */
	public function testSyncAllUsersDoesNotStampTimestampWhenADeletedUsersMetadataWriteFails(): void {
		$config = \OC::$server->get(IConfig::class);
		$config->deleteAppValue('user_vo', 'last_full_user_sync_at');

		$uid = self::UID_PREFIX . 'fullsyncdeletedwritefail1';
		$this->insertUser($uid, '1');

		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchUserDataFromVO')->willReturn([
			'username' => $uid, 'firstname' => 'Gone', 'lastname' => 'WriteFailed', '_deleted' => true,
		]);
		$backend->method('syncUserData')->willReturn(['success' => false, 'photo_error' => null]);

		$result = $this->service->syncAllUsers($backend);

		$this->assertEquals('deleted', $this->findResultRow($result, $uid)['status'], 'Precondition: this uid took the deleted branch, not some other failure branch');
		$this->assertGreaterThan(0, $result['summary']['api_failures'], 'Precondition: a deleted user whose write also failed must still count as an api_failure');
		$this->assertEquals('', $config->getAppValue('user_vo', 'last_full_user_sync_at', ''));
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
