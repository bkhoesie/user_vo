<?php
namespace OCA\UserVO\Tests\Integration\Migration;

use OCA\UserVO\Migration\ForceInitialGroupSweep;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use Test\TestCase;

/**
 * Integration test for the one-time repair step that marks every existing
 * managed group dirty on upgrade to Version1005Date20260803000000, so
 * pre-existing membership drift (from before the ledger existed) gets
 * repaired by GroupSyncSweepJob too, not just drift from here on.
 *
 * @group DB
 */
class ForceInitialGroupSweepTest extends TestCase {
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
		$qb->delete('user_vo_groups')
			->where($qb->expr()->like('vo_group_id', $qb->createNamedParameter('test_forcesweep_%')))
			->executeStatement();
	}

	private function insertRow(string $voGroupId, int $dirtySeq, int $cleanSeq): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->insert('user_vo_groups')->values([
			'vo_group_id' => $qb->createNamedParameter($voGroupId),
			'vo_group_name' => $qb->createNamedParameter('Test Force Sweep Group'),
			'nc_group_id' => $qb->createNamedParameter('uservo_' . $voGroupId),
			'deleted_in_vo' => $qb->createNamedParameter(0, \PDO::PARAM_INT),
			'dirty_seq' => $qb->createNamedParameter($dirtySeq, \PDO::PARAM_INT),
			'clean_seq' => $qb->createNamedParameter($cleanSeq, \PDO::PARAM_INT),
		])->executeStatement();
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

	private function runRepairStep(): void {
		$repairStep = new ForceInitialGroupSweep($this->connection);
		$repairStep->run($this->createMock(IOutput::class));
	}

	public function testMarksExistingCleanGroupsDirty(): void {
		$this->insertRow('test_forcesweep_a', 0, 0);
		$this->insertRow('test_forcesweep_b', 0, 0);

		$this->runRepairStep();

		[$dirtyA, $cleanA] = $this->readSeqs('test_forcesweep_a');
		[$dirtyB, $cleanB] = $this->readSeqs('test_forcesweep_b');
		$this->assertSame(1, $dirtyA);
		$this->assertSame(0, $cleanA);
		$this->assertSame(1, $dirtyB);
		$this->assertSame(0, $cleanB);
	}

	public function testDoesNotClobberAGroupAlreadyLegitimatelyDirty(): void {
		// Simulates a real write landing between the schema migration and
		// this repair step running - dirty_seq is already meaningfully ahead
		// of clean_seq and must not be reset down to 1.
		$this->insertRow('test_forcesweep_c', 5, 2);

		$this->runRepairStep();

		[$dirty, $clean] = $this->readSeqs('test_forcesweep_c');
		$this->assertSame(5, $dirty, 'A group already dirty for a real reason must not be clobbered');
		$this->assertSame(2, $clean);
	}

	public function testIsIdempotentWhenRunMoreThanOnce(): void {
		$this->insertRow('test_forcesweep_d', 0, 0);

		$this->runRepairStep();
		[$dirtyAfterFirst] = $this->readSeqs('test_forcesweep_d');
		$this->assertSame(1, $dirtyAfterFirst);

		// A second run (e.g. via occ maintenance:repair) must not re-mark a
		// group whose dirty_seq is now 1 and clean_seq still 0 - they're no
		// longer equal, so the WHERE guard must exclude it.
		$this->runRepairStep();
		[$dirtyAfterSecond, $cleanAfterSecond] = $this->readSeqs('test_forcesweep_d');
		$this->assertSame(1, $dirtyAfterSecond);
		$this->assertSame(0, $cleanAfterSecond);
	}

	/**
	 * This is what every real install actually looks like by the time a
	 * *second* release runs this repair step - post-migration repair steps
	 * run on every version bump, not just the one that introduces them, and
	 * ordinary traffic converges healthy groups to (N,N) for N > 0 within
	 * days under NC's ~5-minute session revalidation. A literal `dirty_seq =
	 * 1` here would leave the group trailing N-1 increments behind
	 * clean_seq, unable to register as dirty again until N more writes land -
	 * silently reintroducing B1 for that entire window.
	 */
	public function testConvergedGroupWithRealTrafficIsMarkedDirtyNotClobbered(): void {
		$this->insertRow('test_forcesweep_e', 7, 7);

		$this->runRepairStep();

		[$dirty, $clean] = $this->readSeqs('test_forcesweep_e');
		$this->assertSame(7, $clean, 'clean_seq must be untouched');
		$this->assertGreaterThan($clean, $dirty, 'A converged group must end up dirty again for the sweep to reconfirm it, not stuck below clean_seq');
	}

	/**
	 * Self-heals any install that already hit the bug this fix replaces (a
	 * prior buggy run that set dirty_seq to a literal 1 on a group that was
	 * actually at (N,N) for N > 1, leaving it inverted below clean_seq).
	 */
	public function testSelfHealsAnAlreadyInvertedGroup(): void {
		$this->insertRow('test_forcesweep_f', 1, 7);

		$this->runRepairStep();

		[$dirty, $clean] = $this->readSeqs('test_forcesweep_f');
		$this->assertSame(7, $clean);
		$this->assertGreaterThan($clean, $dirty, 'An already-inverted group must be repaired, not left stuck dirty_seq < clean_seq');
	}
}
