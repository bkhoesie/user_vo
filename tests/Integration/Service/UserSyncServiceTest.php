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
}
