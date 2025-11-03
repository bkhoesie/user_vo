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
		$this->connection = \OC::$server->getDatabaseConnection();
		$this->groupManager = \OC::$server->getGroupManager();
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
	 * Helper method to create test group in database
	 */
	private function createTestGroup(string $voGroupId, string $name, string $positionIndex): void {
		$ncGroupId = 'uservo_' . $voGroupId;

		// Insert into database
		$qb = $this->connection->getQueryBuilder();
		$qb->insert('user_vo_groups')
			->values([
				'vo_group_id' => $qb->createNamedParameter($voGroupId),
				'vo_group_name' => $qb->createNamedParameter($name),
				'nc_group_id' => $qb->createNamedParameter($ncGroupId),
				'vo_position_index' => $qb->createNamedParameter($positionIndex),
				'vo_parent_id' => $qb->createNamedParameter(null),
				'vo_position' => $qb->createNamedParameter((int)$positionIndex)
			])
			->executeStatement();

		// Create NC group
		$this->groupManager->createGroup($ncGroupId);
	}
}
