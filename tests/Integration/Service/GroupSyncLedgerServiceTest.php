<?php
namespace OCA\UserVO\Tests\Integration\Service;

use OCA\UserVO\Service\GroupSyncLedgerService;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * Integration tests for GroupSyncLedgerService's dirty/clean sequence
 * ledger against a real database - the mechanism that closes the B1 gap
 * (a user's own metadata write racing a concurrent full group sync's read).
 *
 * See GroupSyncLedgerService's class doc-comment and the
 * Version1005Date20260803000000 migration for the full design and
 * interleaving argument this is meant to pin down.
 *
 * @group DB
 */
class GroupSyncLedgerServiceTest extends TestCase {
	private const GROUP_A = 'test_ledger_a';
	private const GROUP_B = 'test_ledger_b';
	private const GROUP_C = 'test_ledger_c';

	private GroupSyncLedgerService $service;
	private IDBConnection $connection;

	protected function setUp(): void {
		parent::setUp();
		$this->connection = \OC::$server->get(IDBConnection::class);
		$this->service = new GroupSyncLedgerService($this->connection, $this->createMock(LoggerInterface::class));
		$this->cleanupTestData();
	}

	protected function tearDown(): void {
		$this->cleanupTestData();
		parent::tearDown();
	}

	private function cleanupTestData(): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->delete('user_vo_groups')
			->where($qb->expr()->like('vo_group_id', $qb->createNamedParameter('test_ledger_%')))
			->executeStatement();
	}

	private function insertTestGroupRow(string $voGroupId, ?\DateTime $lastSynced = null): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->insert('user_vo_groups')
			->values([
				'vo_group_id' => $qb->createNamedParameter($voGroupId),
				'vo_group_name' => $qb->createNamedParameter('Test Ledger Group'),
				'nc_group_id' => $qb->createNamedParameter('uservo_' . $voGroupId),
				'deleted_in_vo' => $qb->createNamedParameter(0, \PDO::PARAM_INT),
				'last_synced' => $qb->createNamedParameter($lastSynced, 'datetime'),
			])
			->executeStatement();
	}

	private function setLockToken(string $voGroupId, ?string $token): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->update('user_vo_groups')
			->set('sync_lock_token', $qb->createNamedParameter($token))
			->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter($voGroupId)))
			->executeStatement();
	}

	private function readSeqs(string $voGroupId): array {
		$qb = $this->connection->getQueryBuilder();
		$row = $qb->select('dirty_seq', 'clean_seq')
			->from('user_vo_groups')
			->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter($voGroupId)))
			->executeQuery()->fetch();
		return [(int)$row['dirty_seq'], (int)$row['clean_seq']];
	}

	public function testMarkDirtyIncrementsOnlyTheNamedGroups(): void {
		$this->insertTestGroupRow(self::GROUP_A);
		$this->insertTestGroupRow(self::GROUP_B);

		$this->service->markDirty([self::GROUP_A]);

		[$dirtyA, $cleanA] = $this->readSeqs(self::GROUP_A);
		[$dirtyB, $cleanB] = $this->readSeqs(self::GROUP_B);
		$this->assertSame(1, $dirtyA);
		$this->assertSame(0, $cleanA);
		$this->assertSame(0, $dirtyB, 'An unrelated group must not be touched');
		$this->assertSame(0, $cleanB);
	}

	public function testMarkDirtyIsAtomicNotAReadModifyWrite(): void {
		$this->insertTestGroupRow(self::GROUP_A);

		for ($i = 0; $i < 50; $i++) {
			$this->service->markDirty([self::GROUP_A]);
		}

		[$dirty] = $this->readSeqs(self::GROUP_A);
		$this->assertSame(50, $dirty, 'Each call must apply its own SQL-level increment, not a stale PHP-side read-modify-write');
	}

	public function testMarkDirtyIgnoresEmptyGroupIdsWithoutError(): void {
		$this->insertTestGroupRow(self::GROUP_A);
		$this->service->markDirty(['', self::GROUP_A, '']);
		[$dirty] = $this->readSeqs(self::GROUP_A);
		$this->assertSame(1, $dirty, 'Blank IDs must be filtered out, not matched or errored on');
	}

	public function testMarkCleanAdvancesCleanSeqWhenLeaseIsStillHeld(): void {
		$this->insertTestGroupRow(self::GROUP_A);
		$this->setLockToken(self::GROUP_A, 'token-a');
		$this->service->markDirty([self::GROUP_A]);
		$this->service->markDirty([self::GROUP_A]);

		$this->service->markCleanIfStillOwned(self::GROUP_A, 'token-a', 2);

		[$dirty, $clean] = $this->readSeqs(self::GROUP_A);
		$this->assertSame(2, $dirty);
		$this->assertSame(2, $clean, 'clean_seq should advance to the captured seq when the lease is still ours');
	}

	public function testMarkCleanNeverLowersAHigherCleanSeq(): void {
		$this->insertTestGroupRow(self::GROUP_A);
		$this->setLockToken(self::GROUP_A, 'token-a');
		for ($i = 0; $i < 9; $i++) {
			$this->service->markDirty([self::GROUP_A]);
		}
		$this->service->markCleanIfStillOwned(self::GROUP_A, 'token-a', 9);

		// A late-finishing sync captured an older seq (4) and tries to advance to it.
		$this->service->markCleanIfStillOwned(self::GROUP_A, 'token-a', 4);

		[, $clean] = $this->readSeqs(self::GROUP_A);
		$this->assertSame(9, $clean, 'clean_seq must never move backwards');
	}

	/**
	 * Under correct operation, dirty_seq >= clean_seq should always hold -
	 * clean_seq is only ever set to a value that was itself once a valid
	 * dirty_seq snapshot, and dirty_seq only increases. dirty_seq < clean_seq
	 * can only mean the ledger was corrupted by something outside the normal
	 * sync path (e.g. a past repair-step bug - see ForceInitialGroupSweep's
	 * history). This must be repaired the moment any sync completes and
	 * notices it, not left for enough further markDirty() calls to
	 * organically grow dirty_seq back past clean_seq on their own.
	 */
	public function testMarkCleanSelfHealsAnInvertedLedgerState(): void {
		$this->insertTestGroupRow(self::GROUP_A);
		$this->setLockToken(self::GROUP_A, 'token-a');
		// Simulate the corrupted state directly: clean_seq ahead of dirty_seq.
		$qb = $this->connection->getQueryBuilder();
		$qb->update('user_vo_groups')
			->set('dirty_seq', $qb->createNamedParameter(1, \PDO::PARAM_INT))
			->set('clean_seq', $qb->createNamedParameter(7, \PDO::PARAM_INT))
			->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter(self::GROUP_A)))
			->executeStatement();

		// A normal sync captures the (still-broken) dirty_seq=1 as its seqAtStart.
		$this->service->markCleanIfStillOwned(self::GROUP_A, 'token-a', 1);

		[$dirty, $clean] = $this->readSeqs(self::GROUP_A);
		$this->assertSame(7, $clean, 'clean_seq must be untouched by the self-heal');
		$this->assertGreaterThan($clean, $dirty, 'An inverted ledger must be repaired by the very next sync to complete, not left waiting for organic writes to catch up');
	}

	/**
	 * Negative control for the self-heal above: a redundant sync where
	 * nothing changed since the last one completed (clean_seq == seqAtStart,
	 * not >) is completely normal and must not be perturbed.
	 */
	public function testMarkCleanDoesNotPerturbALegitimatelyRedundantSync(): void {
		$this->insertTestGroupRow(self::GROUP_A);
		$this->setLockToken(self::GROUP_A, 'token-a');
		$this->service->markDirty([self::GROUP_A]);
		$this->service->markCleanIfStillOwned(self::GROUP_A, 'token-a', 1);

		// A second, redundant sync captures the same already-clean seq.
		$this->service->markCleanIfStillOwned(self::GROUP_A, 'token-a', 1);

		[$dirty, $clean] = $this->readSeqs(self::GROUP_A);
		$this->assertSame(1, $dirty, 'A legitimate dirty_seq == clean_seq state must not be touched by the self-heal check');
		$this->assertSame(1, $clean);
	}

	public function testMarkCleanIsRejectedAndRedirtiesWhenTheLeaseWasReassigned(): void {
		$this->insertTestGroupRow(self::GROUP_A);
		$this->setLockToken(self::GROUP_A, 'token-a');
		$this->service->markDirty([self::GROUP_A]);
		$seqAtStart = 1;

		// Simulate the lease being reassigned to a different worker while
		// worker A's sync was still running (A's work outlived its TTL).
		$this->setLockToken(self::GROUP_A, 'token-b');

		// A finally finishes and tries to claim clean using its own (now stale) token.
		$this->service->markCleanIfStillOwned(self::GROUP_A, 'token-a', $seqAtStart);

		[$dirty, $clean] = $this->readSeqs(self::GROUP_A);
		$this->assertSame(0, $clean, 'A stale claim must not advance clean_seq');
		$this->assertSame(2, $dirty, 'A stale claim must re-dirty the group instead of silently doing nothing');
	}

	public function testMarkCleanIsANoOpWhenTheGroupWasDeletedMidSync(): void {
		$this->insertTestGroupRow(self::GROUP_A);
		$this->setLockToken(self::GROUP_A, 'token-a');

		$qb = $this->connection->getQueryBuilder();
		$qb->delete('user_vo_groups')
			->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter(self::GROUP_A)))
			->executeStatement();

		// Must not throw, and must not resurrect a dirty row for a group that's gone.
		$this->service->markCleanIfStillOwned(self::GROUP_A, 'token-a', 1);

		$qb = $this->connection->getQueryBuilder();
		$row = $qb->select('vo_group_id')
			->from('user_vo_groups')
			->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter(self::GROUP_A)))
			->executeQuery()->fetch();
		$this->assertFalse($row, 'Deleted group must stay deleted, not get re-inserted by the ledger');
	}

	public function testWriteLandingDuringTheSyncWindowLeavesTheGroupDirty(): void {
		$this->insertTestGroupRow(self::GROUP_A);
		$this->setLockToken(self::GROUP_A, 'token-a');

		// Sync captures seq_at_start = 0 (nothing dirty yet), then a
		// concurrent user-metadata write lands before the sync's clean-advance.
		$seqAtStart = 0;
		$this->service->markDirty([self::GROUP_A]);

		$this->service->markCleanIfStillOwned(self::GROUP_A, 'token-a', $seqAtStart);

		[$dirty, $clean] = $this->readSeqs(self::GROUP_A);
		$this->assertGreaterThan($clean, $dirty, 'A write that lands after the capture must not be lost by the clean-advance');
	}

	public function testTwoWritesInOneSyncWindowAreClearedByASingleLaterSync(): void {
		$this->insertTestGroupRow(self::GROUP_A);
		$this->setLockToken(self::GROUP_A, 'token-a');

		$seqAtStart = 0;
		$this->service->markDirty([self::GROUP_A]); // write 1, during the window
		$this->service->markDirty([self::GROUP_A]); // write 2, during the window
		$this->service->markCleanIfStillOwned(self::GROUP_A, 'token-a', $seqAtStart);

		[$dirtyAfterFirstSync, $cleanAfterFirstSync] = $this->readSeqs(self::GROUP_A);
		$this->assertGreaterThan($cleanAfterFirstSync, $dirtyAfterFirstSync, 'Still dirty after the first sync - it never saw either write');

		// A later sync captures the up-to-date seq and clears both at once.
		$this->service->markCleanIfStillOwned(self::GROUP_A, 'token-a', $dirtyAfterFirstSync);

		[$dirty, $clean] = $this->readSeqs(self::GROUP_A);
		$this->assertSame($dirty, $clean, 'One later capture-and-advance clears any number of coalesced writes - only the comparison matters, not the count');
	}

	public function testFindDirtyGroupsIsEmptyWhenEverythingIsClean(): void {
		$this->insertTestGroupRow(self::GROUP_A);
		$this->insertTestGroupRow(self::GROUP_B);
		// findDirtyGroups() is system-wide, so a shared dev DB may carry
		// unrelated dirty rows from elsewhere - only assert about our own.
		$dirty = $this->service->findDirtyGroups(1000);
		$this->assertNotContains(self::GROUP_A, $dirty);
		$this->assertNotContains(self::GROUP_B, $dirty);
	}

	public function testFindDirtyGroupsRespectsTheBatchLimit(): void {
		$this->insertTestGroupRow(self::GROUP_A, new \DateTime('2026-01-01'));
		$this->insertTestGroupRow(self::GROUP_B, new \DateTime('2026-01-02'));
		$this->insertTestGroupRow(self::GROUP_C, new \DateTime('2026-01-03'));
		$this->service->markDirty([self::GROUP_A, self::GROUP_B, self::GROUP_C]);

		$this->assertCount(2, $this->service->findDirtyGroups(2));
	}

	public function testFindDirtyGroupsOrdersOldestSyncedFirst(): void {
		$this->insertTestGroupRow(self::GROUP_A, new \DateTime('2026-01-03'));
		$this->insertTestGroupRow(self::GROUP_B, new \DateTime('2026-01-01'));
		$this->insertTestGroupRow(self::GROUP_C, new \DateTime('2026-01-02'));
		$this->service->markDirty([self::GROUP_A, self::GROUP_B, self::GROUP_C]);

		// Filter to just our own groups (preserving relative order) - a shared
		// dev DB may carry other unrelated dirty rows interspersed.
		$ownDirty = array_values(array_intersect(
			$this->service->findDirtyGroups(1000),
			[self::GROUP_A, self::GROUP_B, self::GROUP_C]
		));
		$this->assertSame(
			[self::GROUP_B, self::GROUP_C, self::GROUP_A],
			$ownDirty,
			'A hot/never-synced group must not starve older-neglected groups out of the batch'
		);
	}
}
