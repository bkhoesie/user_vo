<?php
/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserVO\Tests\Integration\Service;

use OCA\UserVO\Service\GroupManagementService;
use OCA\UserVO\UserVOAuth;
use OCP\AppFramework\App;
use OCP\IDBConnection;
use OCP\IGroupManager;
use Test\TestCase;

/**
 * Integration tests for GroupManagementService
 * Tests with real Nextcloud database and services
 *
 * @group DB
 */
class GroupManagementServiceTest extends TestCase {
	private GroupManagementService $service;
	private IDBConnection $connection;
	private IGroupManager $groupManager;

	protected function setUp(): void {
		parent::setUp();

		// Get real services from DI container
		$app = new App('user_vo');
		$container = $app->getContainer();

		$this->service = $container->get(GroupManagementService::class);
		$this->connection = \OC::$server->get(\OCP\IDBConnection::class);
		$this->groupManager = \OC::$server->get(\OCP\IGroupManager::class);
	}

	protected function tearDown(): void {
		// Clean up any test data
		$qb = $this->connection->getQueryBuilder();
		$qb->delete('user_vo_groups')
			->where($qb->expr()->like('vo_group_id', $qb->createNamedParameter('test_%')))
			->executeStatement();

		// Clean up test NC groups
		foreach ($this->groupManager->search('uservo_test_') as $group) {
			$group->delete();
		}

		parent::tearDown();
	}

	/**
	 * Test creating a group stores data in database and creates NC group
	 */
	public function testCreateGroupStoresInDatabase(): void {
		// Mock backend to return test group data
		$backend = $this->getMockBuilder(UserVOAuth::class)
			->disableOriginalConstructor()
			->getMock();

		$backend->method('fetchAllGroups')->willReturn([
			[
				'id' => 'test_123',
				'name' => 'Test Integration Group',
				'parent_id' => null,
				'pos' => 1
			]
		]);

		// Create group
		$result = $this->service->createGroup('test_123', $backend);

		// Verify success
		$this->assertTrue($result['success'], 'Group creation should succeed');
		$this->assertEquals('uservo_test_123', $result['nc_group_id']);
		$this->assertEquals('Test Integration Group', $result['vo_group_name']);

		// Verify database entry
		$qb = $this->connection->getQueryBuilder();
		$query = $qb->select('*')
			->from('user_vo_groups')
			->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter('test_123')));

		$rows = $query->executeQuery()->fetchAll();
		$this->assertCount(1, $rows, 'Should have one database entry');
		$this->assertEquals('Test Integration Group', $rows[0]['vo_group_name']);
		$this->assertEquals('1', $rows[0]['vo_position_index']);

		// Verify NC group created
		$this->assertTrue($this->groupManager->groupExists('uservo_test_123'), 'NC group should exist');
	}

	/**
	 * createGroupFromData() inserts new groups pre-marked dirty (dirty_seq=1)
	 * so GroupSyncSweepJob can pick them up if the auto-sync below fails or is
	 * contended - but in the normal path (this test), that auto-sync should
	 * clear it right away by capturing seq_at_start=1 and advancing clean_seq
	 * to match.
	 */
	public function testCreateGroupIsCleanAfterTheNormalAutoSync(): void {
		$backend = $this->getMockBuilder(UserVOAuth::class)
			->disableOriginalConstructor()
			->getMock();
		$backend->method('fetchAllGroups')->willReturn([
			['id' => 'test_dirty_after_create', 'name' => 'Test Dirty After Create', 'parentid' => null, 'pos' => 1],
		]);

		$result = $this->service->createGroup('test_dirty_after_create', $backend);
		$this->assertTrue($result['success'], $result['error'] ?? '');

		$qb = $this->connection->getQueryBuilder();
		$row = $qb->select('dirty_seq', 'clean_seq')
			->from('user_vo_groups')
			->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter('test_dirty_after_create')))
			->executeQuery()->fetch();

		$this->assertSame((int)$row['dirty_seq'], (int)$row['clean_seq'], 'The immediate auto-sync should have already cleared the initial dirty mark');
	}

	/**
	 * Distinguishes the real behavior from a vacuous "always starts 0/0" -
	 * the row must actually start dirty, not just converge because both
	 * counters happen to start at 0. Forces the immediate post-create
	 * auto-sync to fail (a second, separate fetchAllGroups() call inside
	 * syncSingleGroupById(), distinct from createGroup()'s own lookup call)
	 * and confirms the group is left dirty for GroupSyncSweepJob to repair,
	 * rather than silently staying clean-and-possibly-empty.
	 */
	public function testCreateGroupStaysDirtyWhenTheAutoSyncFails(): void {
		$backend = $this->getMockBuilder(UserVOAuth::class)
			->disableOriginalConstructor()
			->getMock();
		$callCount = 0;
		$backend->method('fetchAllGroups')->willReturnCallback(function () use (&$callCount) {
			$callCount++;
			if ($callCount === 1) {
				// createGroup()'s own lookup of the group to create.
				return [['id' => 'test_dirty_autosync_fails', 'name' => 'Test Dirty Autosync Fails', 'parentid' => null, 'pos' => 1]];
			}
			// The auto-sync's own separate fetch - simulates a VO outage
			// landing right after creation.
			return null;
		});

		$result = $this->service->createGroup('test_dirty_autosync_fails', $backend);
		$this->assertTrue($result['success'], 'Group creation itself must still succeed even if the follow-up auto-sync fails');

		$qb = $this->connection->getQueryBuilder();
		$row = $qb->select('dirty_seq', 'clean_seq')
			->from('user_vo_groups')
			->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter('test_dirty_autosync_fails')))
			->executeQuery()->fetch();

		$this->assertGreaterThan((int)$row['clean_seq'], (int)$row['dirty_seq'], 'A group whose initial auto-sync failed must stay dirty for the sweep to repair');
	}

	/**
	 * Test creating duplicate group returns error
	 */
	public function testCreateDuplicateGroupReturnsError(): void {
		// First, create a test group
		$this->createTestGroup('test_456', 'Test Duplicate Group', '1');

		// Mock backend
		$backend = $this->getMockBuilder(UserVOAuth::class)
			->disableOriginalConstructor()
			->getMock();

		$backend->method('fetchAllGroups')->willReturn([
			[
				'id' => 'test_456',
				'name' => 'Test Duplicate Group',
				'parent_id' => null,
				'pos' => 1
			]
		]);

		// Try to create again
		$result = $this->service->createGroup('test_456', $backend);

		// Verify error
		$this->assertFalse($result['success']);
		$this->assertEquals('Group is already managed', $result['error']);
		$this->assertEquals(409, $result['status_code']);
	}

	/**
	 * Test that a concurrent create for the same VO group - i.e. two calls that both
	 * pass createGroup()'s check-then-insert existence check before either has
	 * inserted - fails gracefully via the unique index instead of throwing. Simulated
	 * by calling the private insert helper directly twice, bypassing the pre-check
	 * both real callers (createGroup(), bulkCreateGroups()) do before reaching it.
	 */
	public function testConcurrentCreateOfSameGroupReturnsErrorInsteadOfDuplicateRow(): void {
		$groupData = ['id' => 'test_789', 'name' => 'Test Race Group', 'parent_id' => null, 'pos' => 1];
		$allGroups = [$groupData];

		$ref = new \ReflectionMethod(GroupManagementService::class, 'createGroupFromData');
		$ref->setAccessible(true);

		$first = $ref->invoke($this->service, 'test_789', $groupData, $allGroups);
		$this->assertTrue($first['success'], $first['error'] ?? '');

		// Second "racer" - the DB insert underneath must reject this, not create a
		// second row for the same vo_group_id/nc_group_id.
		$second = $ref->invoke($this->service, 'test_789', $groupData, $allGroups);
		$this->assertFalse($second['success']);
		$this->assertEquals('Group is already managed', $second['error']);
		$this->assertEquals(409, $second['status_code']);

		$qb = $this->connection->getQueryBuilder();
		$rows = $qb->select('*')
			->from('user_vo_groups')
			->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter('test_789')))
			->executeQuery()->fetchAll();
		$this->assertCount(1, $rows, 'Exactly one row should exist for the group, not a duplicate');
	}

	/**
	 * Same race as above, but driven through the public createGroup() entry point
	 * rather than the private insert helper directly - this is what actually catches
	 * bugs in how createGroup() maps createGroupFromData()'s result to an HTTP status
	 * code (a real bug slipped through here: createGroup() unconditionally overwrote
	 * any failure's status_code to 500, silently turning the 409 above into a 500).
	 *
	 * Simulates the race by inserting the "other racer's" row as a side effect of the
	 * fetchAllGroups() mock call - which runs after createGroup()'s own pre-check but
	 * before its insert, the same window a real concurrent request would land in.
	 */
	public function testCreateGroupReturns409NotFor500WhenRaceLostAtPublicApi(): void {
		$voGroupId = 'test_race_public';
		$groupData = ['id' => $voGroupId, 'name' => 'Test Public Race Group', 'parentid' => null, 'pos' => 1];

		$backend = $this->getMockBuilder(UserVOAuth::class)
			->disableOriginalConstructor()
			->getMock();
		$backend->method('fetchAllGroups')->willReturnCallback(function () use ($voGroupId, $groupData) {
			$this->createTestGroup($voGroupId, $groupData['name'], '1');
			return [$groupData];
		});

		$result = $this->service->createGroup($voGroupId, $backend);

		$this->assertFalse($result['success']);
		$this->assertEquals(409, $result['status_code'], 'A lost create-race must surface as 409, not 500');

		$qb = $this->connection->getQueryBuilder();
		$rows = $qb->select('*')
			->from('user_vo_groups')
			->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter($voGroupId)))
			->executeQuery()->fetchAll();
		$this->assertCount(1, $rows, 'Exactly one row should exist, not a duplicate');
	}

	/**
	 * Test deleting a group removes from database and NC
	 */
	public function testDeleteGroupRemovesFromDatabase(): void {
		// First create a test group
		$this->createTestGroup('test_789', 'Test Delete Group', '2');

		// Delete it
		$result = $this->service->deleteGroup('test_789');

		// Verify success
		$this->assertTrue($result['success'], 'Delete should succeed');

		// Verify removed from database
		$qb = $this->connection->getQueryBuilder();
		$query = $qb->select('*')
			->from('user_vo_groups')
			->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter('test_789')));

		$rows = $query->executeQuery()->fetchAll();
		$this->assertCount(0, $rows, 'Should have no database entries');

		// Verify NC group deleted
		$this->assertFalse($this->groupManager->groupExists('uservo_test_789'), 'NC group should not exist');
	}

	/**
	 * Test deleting non-existent group returns error
	 */
	public function testDeleteNonExistentGroupReturnsError(): void {
		$result = $this->service->deleteGroup('test_nonexistent');

		$this->assertFalse($result['success']);
		$this->assertEquals('Group is not managed', $result['error']);
		$this->assertEquals(404, $result['status_code']);
	}

	/**
	 * Test position index for group with no parent
	 */
	public function testPositionIndexForRootLevelGroup(): void {
		// Mock backend for root-level group
		$backend = $this->getMockBuilder(UserVOAuth::class)
			->disableOriginalConstructor()
			->getMock();

		$backend->method('fetchAllGroups')->willReturn([
			[
				'id' => 'test_root',
				'name' => 'Root Group',
				'parent_id' => null,
				'pos' => 5
			]
		]);

		// Create group
		$result = $this->service->createGroup('test_root', $backend);

		// Verify success
		$this->assertTrue($result['success']);

		// Verify position index is just the position number
		$qb = $this->connection->getQueryBuilder();
		$query = $qb->select('vo_position_index')
			->from('user_vo_groups')
			->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter('test_root')));

		$row = $query->executeQuery()->fetch();
		$this->assertEquals('5', $row['vo_position_index'], 'Root group should have position as index');
	}

	/**
	 * Test fetchAllVOGroups returns groups and updates deleted flags
	 */
	public function testFetchAllVOGroupsReturnsGroupsAndUpdatesDeletedFlags(): void {
		// Create a managed group
		$this->createTestGroup('test_existing', 'Existing Group', '1');

		// Mock backend to return groups (test_existing still exists, but not test_deleted)
		$backend = $this->getMockBuilder(UserVOAuth::class)
			->disableOriginalConstructor()
			->getMock();

		$backend->method('fetchAllGroups')->willReturn([
			[
				'id' => 'test_existing',
				'name' => 'Existing Group',
				'parentid' => null,
				'pos' => 1
			],
			[
				'id' => 'test_new',
				'name' => 'New Group',
				'parentid' => null,
				'pos' => 2
			]
		]);

		// Fetch all groups
		$result = $this->service->fetchAllVOGroups($backend);

		// Verify success
		$this->assertTrue($result['success']);
		$this->assertCount(2, $result['groups']);

		// Verify test_existing is marked as managed
		$existingGroup = array_values(array_filter($result['groups'], fn($g) => $g['vo_group_id'] === 'test_existing'))[0];
		$this->assertTrue($existingGroup['is_managed']);

		// Verify test_new is not managed
		$newGroup = array_values(array_filter($result['groups'], fn($g) => $g['vo_group_id'] === 'test_new'))[0];
		$this->assertFalse($newGroup['is_managed']);
	}

	/**
	 * Test fetchManagedGroups returns managed groups with VO data
	 */
	public function testFetchManagedGroupsReturnsManagedGroups(): void {
		// Create two managed groups
		$this->createTestGroup('test_managed1', 'Managed Group 1', '1');
		$this->createTestGroup('test_managed2', 'Managed Group 2', '2');

		// Mock backend to return VO data
		$backend = $this->getMockBuilder(UserVOAuth::class)
			->disableOriginalConstructor()
			->getMock();

		$backend->method('fetchAllGroups')->willReturn([
			[
				'id' => 'test_managed1',
				'name' => 'Managed Group 1 (Updated)',
				'parentid' => null,
				'pos' => 1
			],
			[
				'id' => 'test_managed2',
				'name' => 'Managed Group 2 (Updated)',
				'parentid' => null,
				'pos' => 2
			]
		]);

		// Fetch managed groups
		$result = $this->service->fetchManagedGroups($backend);

		// Verify success
		$this->assertTrue($result['success'], 'fetchManagedGroups failed: ' . ($result['error'] ?? 'unknown error'));
		$this->assertGreaterThanOrEqual(2, count($result['groups']), 'Should have at least 2 groups');

		// Filter to just our test groups
		$testGroups = array_filter($result['groups'], fn($g) =>
			$g['vo_group_id'] === 'test_managed1' || $g['vo_group_id'] === 'test_managed2'
		);
		$this->assertCount(2, $testGroups, 'Should find our 2 test groups');

		// Verify groups have both DB and VO data
		foreach ($testGroups as $group) {
			$this->assertArrayHasKey('vo_group_id', $group);
			$this->assertArrayHasKey('nc_group_id', $group);
			$this->assertArrayHasKey('vo_group_name', $group);
			$this->assertStringContainsString('test_managed', $group['vo_group_id']);
		}
	}

	/**
	 * Test bulkCreateGroups creates multiple groups efficiently
	 */
	public function testBulkCreateGroupsCreatesMultipleGroups(): void {
		// Mock backend to return multiple groups
		$backend = $this->getMockBuilder(UserVOAuth::class)
			->disableOriginalConstructor()
			->getMock();

		$backend->method('fetchAllGroups')->willReturn([
			[
				'id' => 'test_bulk1',
				'name' => 'Bulk Group 1',
				'parentid' => null,
				'pos' => 1
			],
			[
				'id' => 'test_bulk2',
				'name' => 'Bulk Group 2',
				'parentid' => null,
				'pos' => 2
			],
			[
				'id' => 'test_bulk3',
				'name' => 'Bulk Group 3',
				'parentid' => null,
				'pos' => 3
			]
		]);

		// Bulk create 3 groups
		$result = $this->service->bulkCreateGroups(['test_bulk1', 'test_bulk2', 'test_bulk3'], $backend);

		// Verify all created
		$this->assertCount(3, $result['created']);
		$this->assertCount(0, $result['skipped']);
		$this->assertCount(0, $result['errors']);

		// Verify all in database
		$qb = $this->connection->getQueryBuilder();
		$query = $qb->select('vo_group_id')
			->from('user_vo_groups')
			->where($qb->expr()->like('vo_group_id', $qb->createNamedParameter('test_bulk%')));

		$rows = $query->executeQuery()->fetchAll();
		$this->assertCount(3, $rows);

		// Verify all NC groups created
		$this->assertTrue($this->groupManager->groupExists('uservo_test_bulk1'));
		$this->assertTrue($this->groupManager->groupExists('uservo_test_bulk2'));
		$this->assertTrue($this->groupManager->groupExists('uservo_test_bulk3'));
	}

	/**
	 * Regression test: bulkCreateGroups() must auto-sync members after creation,
	 * matching the single createGroup() path. Previously bulkCreateGroups() never
	 * called the sync service at all, leaving newly bulk-created groups at 0
	 * members until an explicit sync - found via manual testing against the real
	 * VereinOnline API on 2026-08-01.
	 */
	public function testBulkCreateGroupsAutoSyncsMembers(): void {
		$backend = $this->getMockBuilder(UserVOAuth::class)
			->disableOriginalConstructor()
			->getMock();

		$backend->method('fetchAllGroups')->willReturn([
			[
				'id' => 'test_bulk_sync1',
				'name' => 'Bulk Sync Group 1',
				'parentid' => null,
				'pos' => 1
			],
			[
				'id' => 'test_bulk_sync2',
				'name' => 'Bulk Sync Group 2',
				'parentid' => null,
				'pos' => 2
			]
		]);

		$result = $this->service->bulkCreateGroups(['test_bulk_sync1', 'test_bulk_sync2'], $backend);

		$this->assertCount(2, $result['created']);
		foreach ($result['created'] as $created) {
			$this->assertArrayHasKey(
				'synced',
				$created,
				'bulkCreateGroups() must attempt to sync each newly created group, like createGroup() does'
			);
			$this->assertTrue(
				$created['synced'],
				"Auto-sync should succeed for group {$created['vo_group_id']}: " . ($created['sync_error'] ?? '')
			);
		}
	}

	/**
	 * Test bulkCreateGroups skips already managed groups
	 */
	public function testBulkCreateGroupsSkipsExisting(): void {
		// Pre-create one group
		$this->createTestGroup('test_existing_bulk', 'Existing Bulk Group', '1');

		// Mock backend
		$backend = $this->getMockBuilder(UserVOAuth::class)
			->disableOriginalConstructor()
			->getMock();

		$backend->method('fetchAllGroups')->willReturn([
			[
				'id' => 'test_existing_bulk',
				'name' => 'Existing Bulk Group',
				'parentid' => null,
				'pos' => 1
			],
			[
				'id' => 'test_new_bulk',
				'name' => 'New Bulk Group',
				'parentid' => null,
				'pos' => 2
			]
		]);

		// Try to create both
		$result = $this->service->bulkCreateGroups(['test_existing_bulk', 'test_new_bulk'], $backend);

		// Verify one created, one skipped
		$this->assertCount(1, $result['created']);
		$this->assertCount(1, $result['skipped']);
		$this->assertCount(0, $result['errors']);

		// Verify skipped group ID
		$this->assertEquals('test_existing_bulk', $result['skipped'][0]['vo_group_id']);
	}

	/**
	 * Test bulkDeleteGroups deletes multiple groups
	 */
	public function testBulkDeleteGroupsDeletesMultipleGroups(): void {
		// Create multiple groups
		$this->createTestGroup('test_delete1', 'Delete Group 1', '1');
		$this->createTestGroup('test_delete2', 'Delete Group 2', '2');
		$this->createTestGroup('test_delete3', 'Delete Group 3', '3');

		// Bulk delete
		$result = $this->service->bulkDeleteGroups(['test_delete1', 'test_delete2', 'test_delete3']);

		// Verify all deleted
		$this->assertCount(3, $result['deleted']);
		$this->assertCount(0, $result['errors']);

		// Verify removed from database
		$qb = $this->connection->getQueryBuilder();
		$query = $qb->select('vo_group_id')
			->from('user_vo_groups')
			->where($qb->expr()->like('vo_group_id', $qb->createNamedParameter('test_delete%')));

		$rows = $query->executeQuery()->fetchAll();
		$this->assertCount(0, $rows);

		// Verify NC groups deleted
		$this->assertFalse($this->groupManager->groupExists('uservo_test_delete1'));
		$this->assertFalse($this->groupManager->groupExists('uservo_test_delete2'));
		$this->assertFalse($this->groupManager->groupExists('uservo_test_delete3'));
	}

	/**
	 * Test bulkDeleteGroups handles non-existent groups gracefully
	 */
	public function testBulkDeleteGroupsHandlesNonExistent(): void {
		// Create one group
		$this->createTestGroup('test_delete_real', 'Real Group', '1');

		// Try to delete one real, one fake
		$result = $this->service->bulkDeleteGroups(['test_delete_real', 'test_delete_fake']);

		// Verify one deleted, one error
		$this->assertCount(1, $result['deleted']);
		$this->assertCount(1, $result['errors']);

		// Verify error group ID
		$this->assertEquals('test_delete_fake', $result['errors'][0]['vo_group_id']);
	}

	/**
	 * Helper method to create test group in database
	 */
	private function createTestGroup(string $voGroupId, string $name, string $positionIndex): void {
		$ncGroupId = 'uservo_' . $voGroupId;

		// Insert into database with all required fields
		$qb = $this->connection->getQueryBuilder();
		$qb->insert('user_vo_groups')
			->values([
				'vo_group_id' => $qb->createNamedParameter($voGroupId),
				'vo_group_name' => $qb->createNamedParameter($name),
				'nc_group_id' => $qb->createNamedParameter($ncGroupId),
				'nc_display_name' => $qb->createNamedParameter($name),
				'vo_position_index' => $qb->createNamedParameter($positionIndex),
				'vo_parent_id' => $qb->createNamedParameter(null),
				'vo_position' => $qb->createNamedParameter((int)$positionIndex),
				'deleted_in_vo' => $qb->createNamedParameter(0, \PDO::PARAM_INT),
				'member_count' => $qb->createNamedParameter(0, \PDO::PARAM_INT),
				'vo_member_count' => $qb->createNamedParameter(0, \PDO::PARAM_INT),
				'non_vo_member_count' => $qb->createNamedParameter(0, \PDO::PARAM_INT),
			])
			->executeStatement();

		// Create NC group
		$this->groupManager->createGroup($ncGroupId);
	}
}
