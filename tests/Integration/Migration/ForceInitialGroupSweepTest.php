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
}
