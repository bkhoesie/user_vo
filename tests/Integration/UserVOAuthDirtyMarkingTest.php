<?php
namespace OCA\UserVO\Tests\Integration;

use OCA\UserVO\UserVOAuth;
use OCP\IDBConnection;
use Test\TestCase;

/**
 * Integration tests for the writer side of the B1 fix:
 * UserVOAuth::updateVOMetadata() marking groups dirty via
 * GroupSyncLedgerService. See that service and the
 * Version1005Date20260803000000 migration for the full design; see
 * GroupSyncServiceTest for the reader/sync-side coverage this pairs with.
 *
 * updateVOMetadata() is protected, invoked here via reflection - same
 * pattern as SyncUsersJobTest's handling of run().
 *
 * @group DB
 */
class UserVOAuthDirtyMarkingTest extends TestCase {
	private const UID_PREFIX = 'zzz_test_dirtymarking_';
	private const GROUP_PREFIX = 'test_dirtymarking_';

	private IDBConnection $connection;

	protected function setUp(): void {
		parent::setUp();
		$this->connection = \OC::$server->get(IDBConnection::class);
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

		$qb = $this->connection->getQueryBuilder();
		$qb->delete('user_vo_groups')
			->where($qb->expr()->like('vo_group_id', $qb->createNamedParameter(self::GROUP_PREFIX . '%')))
			->executeStatement();
	}

	private function createManagedGroupRow(string $voGroupId): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->insert('user_vo_groups')
			->values([
				'vo_group_id' => $qb->createNamedParameter($voGroupId),
				'vo_group_name' => $qb->createNamedParameter('Test Dirty Marking Group'),
				'nc_group_id' => $qb->createNamedParameter('uservo_' . $voGroupId),
				'deleted_in_vo' => $qb->createNamedParameter(0, \PDO::PARAM_INT),
			])
			->executeStatement();
	}

	private function createUserVoRow(string $uid, string $voGroupIds): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->insert('user_vo')->values([
			'uid' => $qb->createNamedParameter($uid),
			'backend' => $qb->createNamedParameter('user_vo'),
			'vo_group_ids' => $qb->createNamedParameter($voGroupIds),
		])->executeStatement();
	}

	private function userExistsInDb(string $uid): bool {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('uid')->from('user_vo')
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		return $qb->executeQuery()->fetch() !== false;
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

	private function invokeUpdateVOMetadata(UserVOAuth $auth, string $uid, array $voUserData): void {
		$ref = new \ReflectionMethod(UserVOAuth::class, 'updateVOMetadata');
		$ref->setAccessible(true);
		$ref->invoke($auth, $uid, $voUserData);
	}

	public function testMetadataWriteMarksBothAddedAndRemovedGroupsDirty(): void {
		$groupA = self::GROUP_PREFIX . 'a';
		$groupB = self::GROUP_PREFIX . 'b';
		$groupC = self::GROUP_PREFIX . 'c';
		foreach ([$groupA, $groupB, $groupC] as $g) {
			$this->createManagedGroupRow($g);
		}

		$uid = self::UID_PREFIX . 'addremove';
		$this->createUserVoRow($uid, "$groupA,$groupB");

		$auth = new UserVOAuth('https://vo.test/org', 'apiuser', 'apipass');
		$this->invokeUpdateVOMetadata($auth, $uid, ['id' => '1', 'username' => $uid, 'group_ids' => "$groupB,$groupC"]);

		[$dirtyA, $cleanA] = $this->readSeqs($groupA);
		[$dirtyB, $cleanB] = $this->readSeqs($groupB);
		[$dirtyC, $cleanC] = $this->readSeqs($groupC);
		$this->assertGreaterThan($cleanA, $dirtyA, 'Group removed from membership must be marked dirty');
		$this->assertGreaterThan($cleanC, $dirtyC, 'Group added to membership must be marked dirty');
		$this->assertGreaterThan($cleanB, $dirtyB, 'Group present in both old and new is still marked dirty - the union is marked unconditionally, not a diff');
	}

	public function testMetadataWriteIgnoresUnmanagedVoGroupIds(): void {
		$groupA = self::GROUP_PREFIX . 'managed';
		$this->createManagedGroupRow($groupA);

		$uid = self::UID_PREFIX . 'unmanaged';
		$auth = new UserVOAuth('https://vo.test/org', 'apiuser', 'apipass');

		// Must not throw, and must not create a stray row for the unmanaged ID.
		$this->invokeUpdateVOMetadata($auth, $uid, ['id' => '1', 'username' => $uid, 'group_ids' => 'some_unmanaged_group_id']);

		$this->assertTrue($this->userExistsInDb($uid));

		$qb = $this->connection->getQueryBuilder();
		$row = $qb->select('vo_group_id')
			->from('user_vo_groups')
			->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter('some_unmanaged_group_id')))
			->executeQuery()->fetch();
		$this->assertFalse($row, 'markDirty() for an unmanaged group ID must not create a row');
	}

	public function testFirstLoginInsertPathMarksTheNewGroupsDirty(): void {
		$groupD = self::GROUP_PREFIX . 'd';
		$this->createManagedGroupRow($groupD);

		$uid = self::UID_PREFIX . 'firstlogin';
		$this->assertFalse($this->userExistsInDb($uid));

		$auth = new UserVOAuth('https://vo.test/org', 'apiuser', 'apipass');
		$this->invokeUpdateVOMetadata($auth, $uid, ['id' => '1', 'username' => $uid, 'group_ids' => $groupD, 'firstname' => 'Test', 'lastname' => 'User']);

		$this->assertTrue($this->userExistsInDb($uid), 'First-login INSERT path must still create the row');
		[$dirty, $clean] = $this->readSeqs($groupD);
		$this->assertGreaterThan($clean, $dirty, 'A brand-new membership (OLD = empty) must still mark the new group dirty');
	}

	/**
	 * The metadata write and its dirty-mark must share one atomic unit: if
	 * this method is ever called inside an outer transaction that later
	 * rolls back, both the value write and the dirty mark must roll back
	 * together, not just one of them.
	 */
	public function testMetadataWriteAndDirtyMarkAreAtomic(): void {
		$groupId = self::GROUP_PREFIX . 'atomic';
		$this->createManagedGroupRow($groupId);
		$uid = self::UID_PREFIX . 'atomic';

		$this->connection->beginTransaction();
		try {
			$auth = new UserVOAuth('https://vo.test/org', 'apiuser', 'apipass');
			$this->invokeUpdateVOMetadata($auth, $uid, ['id' => '1', 'username' => $uid, 'group_ids' => $groupId]);
		} finally {
			$this->connection->rollBack();
		}

		$this->assertFalse($this->userExistsInDb($uid), 'Rolling back the outer transaction must roll back the metadata write');
		[$dirty] = $this->readSeqs($groupId);
		$this->assertSame(0, $dirty, 'Rolling back the outer transaction must also roll back the dirty mark - they share one atomic unit, not two independent ones');
	}

	public function testEmptyGroupIdsStringDoesNotMarkAGarbageGroup(): void {
		$uid = self::UID_PREFIX . 'emptygroups';
		$auth = new UserVOAuth('https://vo.test/org', 'apiuser', 'apipass');

		// Must not throw despite an empty group_ids string on both sides (no
		// prior row, and the new value is '').
		$this->invokeUpdateVOMetadata($auth, $uid, ['id' => '1', 'username' => $uid, 'group_ids' => '']);

		$this->assertTrue($this->userExistsInDb($uid));

		$qb = $this->connection->getQueryBuilder();
		$row = $qb->select('vo_group_id')
			->from('user_vo_groups')
			->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter('')))
			->executeQuery()->fetch();
		$this->assertFalse($row, 'An empty group_ids string must not produce a garbage \'\' group entry');
	}
}
