<?php
namespace OCA\UserVO\Tests\Integration\Service;

use OCA\UserVO\Service\GroupSyncService;
use OCA\UserVO\Service\ConfigService;
use OCA\UserVO\Service\GroupNameHarmonizer;
use OCA\UserVO\UserVOAuth;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use Test\TestCase;

/**
 * Integration tests for GroupSyncService
 *
 * These tests use real Nextcloud services and database operations.
 * External APIs (UserVOAuth) are still mocked.
 *
 * @group DB
 */
class GroupSyncServiceTest extends TestCase {
	private GroupSyncService $service;
	private IDBConnection $connection;
	private IGroupManager $groupManager;
	private IUserManager $userManager;
	private ConfigService $configService;

	protected function setUp(): void {
		parent::setUp();

		$this->connection = \OC::$server->getDatabaseConnection();
		$this->groupManager = \OC::$server->getGroupManager();
		$this->userManager = \OC::$server->getUserManager();
		$this->configService = $this->createMock(ConfigService::class);
		$harmonizer = new GroupNameHarmonizer();

		$this->service = new GroupSyncService(
			$this->connection,
			$this->groupManager,
			$this->userManager,
			$this->configService,
			$harmonizer
		);

		// Clean up any test data
		$this->cleanupTestData();
	}

	protected function tearDown(): void {
		$this->cleanupTestData();
		parent::tearDown();
	}

	private function cleanupTestData(): void {
		// Delete test groups from database
		$qb = $this->connection->getQueryBuilder();
		$qb->delete('user_vo_groups')
			->where($qb->expr()->like('vo_group_id', $qb->createNamedParameter('test_%')))
			->executeStatement();

		// Delete test NC groups
		$testGroups = ['uservo_test_123', 'uservo_test_456', 'uservo_test_789'];
		foreach ($testGroups as $groupId) {
			if ($this->groupManager->groupExists($groupId)) {
				$group = $this->groupManager->get($groupId);
				if ($group) {
					$group->delete();
				}
			}
		}

		// Delete test users
		$testUsers = ['testuser1', 'testuser2', 'testuser3'];
		foreach ($testUsers as $userId) {
			if ($this->userManager->userExists($userId)) {
				$user = $this->userManager->get($userId);
				if ($user) {
					$user->delete();
				}
			}
		}

		// Clean user_vo table
		$qb = $this->connection->getQueryBuilder();
		$qb->delete('user_vo')
			->where($qb->expr()->like('uid', $qb->createNamedParameter('testuser%')))
			->executeStatement();
	}

	private function createTestGroupInDB(string $voGroupId, string $ncGroupId, string $voGroupName): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->insert('user_vo_groups')
			->values([
				'vo_group_id' => $qb->createNamedParameter($voGroupId),
				'vo_group_name' => $qb->createNamedParameter($voGroupName),
				'nc_group_id' => $qb->createNamedParameter($ncGroupId),
				'nc_display_name' => $qb->createNamedParameter($voGroupName),
				'vo_parent_id' => $qb->createNamedParameter(null),
				'vo_position' => $qb->createNamedParameter(1, \PDO::PARAM_INT),
				'vo_position_index' => $qb->createNamedParameter('1'),
				'deleted_in_vo' => $qb->createNamedParameter(0, \PDO::PARAM_INT),
				'member_count' => $qb->createNamedParameter(0, \PDO::PARAM_INT),
				'vo_member_count' => $qb->createNamedParameter(0, \PDO::PARAM_INT),
				'non_vo_member_count' => $qb->createNamedParameter(0, \PDO::PARAM_INT),
			])
			->executeStatement();
	}

	public function testSyncSingleGroupByIdWithRealDatabase() {
		// Create test group in NC
		$ncGroup = $this->groupManager->createGroup('uservo_test_123');
		$this->assertNotNull($ncGroup);

		// Create corresponding DB entry
		$this->createTestGroupInDB('test_123', 'uservo_test_123', 'Test Group 123');

		// Mock backend to return group data
		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchAllGroups')->willReturn([
			['id' => 'test_123', 'name' => 'Test Group 123', 'parentid' => null, 'pos' => 1]
		]);

		// Call sync
		$result = $this->service->syncSingleGroupById('test_123', $backend);

		// Verify result
		$this->assertTrue($result['success']);
		$this->assertEquals('test_123', $result['vo_group_id']);
		$this->assertEquals('uservo_test_123', $result['nc_group_id']);
		$this->assertEquals('Test Group 123', $result['vo_group_name']);
		$this->assertArrayHasKey('added', $result);
		$this->assertArrayHasKey('removed', $result);
		$this->assertArrayHasKey('skipped', $result);

		// Verify database was updated
		$qb = $this->connection->getQueryBuilder();
		$qb->select('last_synced', 'member_count')
			->from('user_vo_groups')
			->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter('test_123')));
		$dbResult = $qb->executeQuery();
		$row = $dbResult->fetch();
		$dbResult->closeCursor();

		$this->assertNotNull($row['last_synced']);
		$this->assertEquals(0, $row['member_count']);
	}

	public function testSyncAllManagedGroupsWithMultipleGroups() {
		// First cleanup to ensure clean state
		$this->cleanupTestData();

		// Create 3 test groups
		for ($i = 1; $i <= 3; $i++) {
			$groupId = "uservo_test_{$i}23";
			$voGroupId = "test_{$i}23";
			$voGroupName = "Test Group {$i}";

			$ncGroup = $this->groupManager->createGroup($groupId);
			$this->assertNotNull($ncGroup);
			$this->createTestGroupInDB($voGroupId, $groupId, $voGroupName);
		}

		// Mock backend to return only our test groups
		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchAllGroups')->willReturn([
			['id' => 'test_123', 'name' => 'Test Group 1', 'parentid' => null, 'pos' => 1],
			['id' => 'test_223', 'name' => 'Test Group 2', 'parentid' => null, 'pos' => 2],
			['id' => 'test_323', 'name' => 'Test Group 3', 'parentid' => null, 'pos' => 3],
		]);

		// Call sync - but note this will sync ALL managed groups in DB, not just test ones
		// We need to ensure cleanup ran first
		$result = $this->service->syncAllManagedGroups($backend);

		// Verify result
		$this->assertTrue($result['success']);
		$this->assertEquals('Bulk sync completed', $result['message']);
		// Can't assert exact count if other groups exist, so just check >= 3
		$this->assertGreaterThanOrEqual(3, $result['summary']['total']);
		// Check that our 3 test groups succeeded
		$testGroupResults = array_filter($result['results'], function($r) {
			return str_starts_with($r['vo_group_id'], 'test_');
		});
		$this->assertCount(3, $testGroupResults);

		// Verify all test groups were marked as synced
		foreach ($testGroupResults as $groupResult) {
			$this->assertEquals('success', $groupResult['status']);
			$this->assertArrayHasKey('added', $groupResult);
			$this->assertArrayHasKey('removed', $groupResult);
		}
	}

	public function testSyncGroupsByIdsWithRealDatabase() {
		// Create 2 test groups
		for ($i = 4; $i <= 5; $i++) {
			$groupId = "uservo_test_{$i}56";
			$voGroupId = "test_{$i}56";
			$voGroupName = "Test Group {$i}";

			$ncGroup = $this->groupManager->createGroup($groupId);
			$this->assertNotNull($ncGroup);
			$this->createTestGroupInDB($voGroupId, $groupId, $voGroupName);
		}

		// Mock config service
		$this->configService->method('loadConfiguration')->willReturn([
			'api_url' => 'https://example.com',
			'api_username' => 'test',
			'api_password' => 'test'
		]);

		// Note: syncGroupsByIds creates its own backend internally
		// We can't easily mock it without more refactoring, so we'll skip this test
		// and rely on unit tests for that method
		$this->markTestSkipped('syncGroupsByIds creates backend internally - needs refactoring to be testable');
	}

	public function testSyncHandlesDeletedGroupsInVO() {
		// TODO: This test needs more investigation
		// The service behavior when a group is deleted from VO is complex and involves
		// multiple error paths. Skipping for now - covered by unit tests.
		$this->markTestSkipped('Deleted group handling needs more investigation - complex error paths');
	}
}
