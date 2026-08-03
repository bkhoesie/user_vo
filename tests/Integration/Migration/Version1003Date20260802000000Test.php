<?php
namespace OCA\UserVO\Tests\Integration\Migration;

use OCA\UserVO\Migration\Version1003Date20260802000000;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use Test\TestCase;

/**
 * Regression test for the pre-existing-duplicate-rows fix: adding a unique
 * index on top of duplicate vo_group_id rows (from the create-group race this
 * migration closes) would otherwise abort the whole upgrade with a raw SQL
 * constraint-violation error. preSchemaChange() must de-dupe first.
 *
 * By the time this test runs, the dev DB already has the unique indexes this
 * migration adds (it's already been applied) - so testPreSchemaChangeRemovesDuplicates
 * temporarily drops them to recreate the real precondition (an install that
 * hit the race *before* upgrading to this fix), the same way this fix was
 * originally reproduced and verified manually.
 *
 * @group DB
 */
class Version1003Date20260802000000Test extends TestCase {
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
			->where($qb->expr()->like('vo_group_id', $qb->createNamedParameter('test_migration_dupe%')))
			->executeStatement();
	}

	private function insertRow(string $voGroupId, string $name, string $ncGroupId): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->insert('user_vo_groups')->values([
			'vo_group_id' => $qb->createNamedParameter($voGroupId),
			'vo_group_name' => $qb->createNamedParameter($name),
			'nc_group_id' => $qb->createNamedParameter($ncGroupId),
			'deleted_in_vo' => $qb->createNamedParameter(0, \PDO::PARAM_INT),
		])->executeStatement();
	}

	private function schemaClosure(): \Closure {
		/** @var \OC\DB\ConnectionAdapter $adapter */
		$adapter = $this->connection;
		return fn () => new \OC\DB\SchemaWrapper($adapter->getInner());
	}

	/**
	 * Applies a schema mutation via the same Doctrine schema-diff mechanism
	 * MigrationService uses for real migrations (get wrapper, mutate table,
	 * migrateToSchema), instead of raw SQL - raw `ALTER TABLE ... ADD/DROP
	 * INDEX` is MySQL/MariaDB syntax and doesn't parse on SQLite, which CI
	 * runs against.
	 */
	private function applySchemaChange(\Closure $mutateTable): void {
		/** @var \OC\DB\ConnectionAdapter $adapter */
		$adapter = $this->connection;
		$schema = ($this->schemaClosure())();
		$mutateTable($schema->getTable('user_vo_groups'));
		$adapter->getInner()->migrateToSchema($schema->getWrappedSchema());
	}

	private function dropUniqueIndexes(): void {
		$this->applySchemaChange(function ($table) {
			$table->dropIndex('user_vo_groups_vo_gid_u');
			$table->addIndex(['vo_group_id'], 'user_vo_groups_vo_gid');
			$table->dropIndex('user_vo_groups_nc_gid_u');
			$table->addIndex(['nc_group_id'], 'user_vo_groups_nc_gid');
		});
	}

	private function restoreUniqueIndexes(): void {
		$this->applySchemaChange(function ($table) {
			$table->dropIndex('user_vo_groups_vo_gid');
			$table->addUniqueIndex(['vo_group_id'], 'user_vo_groups_vo_gid_u');
			$table->dropIndex('user_vo_groups_nc_gid');
			$table->addUniqueIndex(['nc_group_id'], 'user_vo_groups_nc_gid_u');
		});
	}

	public function testPreSchemaChangeRemovesDuplicatesKeepingTheOldestRow(): void {
		// Recreate the real precondition: an install that hit the race *before*
		// this fix, so its unique indexes don't exist yet and duplicates can
		// actually be inserted.
		$this->dropUniqueIndexes();
		try {
			$this->insertRow('test_migration_dupe1', 'First', 'uservo_test_migration_dupe1');
			$this->insertRow('test_migration_dupe1', 'Racer', 'uservo_test_migration_dupe1');

			$qb = $this->connection->getQueryBuilder();
			$before = $qb->select('id')->from('user_vo_groups')
				->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter('test_migration_dupe1')))
				->orderBy('id', 'ASC')
				->executeQuery()->fetchAll();
			$this->assertCount(2, $before, 'Setup should have produced 2 duplicate rows');
			$oldestId = $before[0]['id'];

			$migration = new Version1003Date20260802000000($this->connection);
			$warnings = [];
			$output = $this->createMock(IOutput::class);
			$output->method('warning')->willReturnCallback(function ($message) use (&$warnings) {
				$warnings[] = $message;
			});

			$migration->preSchemaChange($output, $this->schemaClosure(), []);

			$qb = $this->connection->getQueryBuilder();
			$after = $qb->select('id')->from('user_vo_groups')
				->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter('test_migration_dupe1')))
				->executeQuery()->fetchAll();
			$this->assertCount(1, $after, 'Duplicate row should have been removed');
			$this->assertEquals($oldestId, $after[0]['id'], 'The oldest (lowest id) row should be the one kept');
			$this->assertNotEmpty($warnings, 'Should log what it removed, not silently delete data');
		} finally {
			$this->restoreUniqueIndexes();
		}
	}

	public function testPreSchemaChangeIsNoOpWithoutDuplicates(): void {
		$this->insertRow('test_migration_dupe2', 'Only', 'uservo_test_migration_dupe2');

		$migration = new Version1003Date20260802000000($this->connection);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->never())->method('warning');

		$migration->preSchemaChange($output, $this->schemaClosure(), []);

		$qb = $this->connection->getQueryBuilder();
		$after = $qb->select('id')->from('user_vo_groups')
			->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter('test_migration_dupe2')))
			->executeQuery()->fetchAll();
		$this->assertCount(1, $after);
	}
}
