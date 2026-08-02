<?php
namespace OCA\UserVO\Tests\Integration\Service;

use OCA\UserVO\Service\GroupSyncService;
use OCA\UserVO\Service\GroupNameHarmonizer;
use OCA\UserVO\Service\GroupSyncLockService;
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

	protected function setUp(): void {
		parent::setUp();

		$this->connection = \OC::$server->get(\OCP\IDBConnection::class);
		$this->groupManager = \OC::$server->get(\OCP\IGroupManager::class);
		$this->userManager = \OC::$server->get(\OCP\IUserManager::class);
		$harmonizer = new GroupNameHarmonizer();
		$lockService = new GroupSyncLockService($this->connection);

		$this->service = new GroupSyncService(
			$this->connection,
			$this->groupManager,
			$this->userManager,
			$harmonizer,
			$lockService
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
		$testGroups = ['uservo_test_123', 'uservo_test_456', 'uservo_test_556', 'uservo_test_789', 'uservo_test_lockrace'];
		foreach ($testGroups as $groupId) {
			if ($this->groupManager->groupExists($groupId)) {
				$group = $this->groupManager->get($groupId);
				if ($group) {
					$group->delete();
				}
			}
		}

		// Delete test users
		$testUsers = ['testuser1', 'testuser2', 'testuser3', 'testuser_lockrace'];
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
		$voGroupIds = [];
		for ($i = 4; $i <= 5; $i++) {
			$groupId = "uservo_test_{$i}56";
			$voGroupId = "test_{$i}56";
			$voGroupName = "Test Group {$i}";
			$voGroupIds[] = $voGroupId;

			$ncGroup = $this->groupManager->createGroup($groupId);
			$this->assertNotNull($ncGroup);
			$this->createTestGroupInDB($voGroupId, $groupId, $voGroupName);
		}

		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchAllGroups')->willReturn([
			['id' => 'test_456', 'name' => 'Test Group 4', 'parentid' => null, 'pos' => 1],
			['id' => 'test_556', 'name' => 'Test Group 5', 'parentid' => null, 'pos' => 2],
		]);

		$result = $this->service->syncGroupsByIds($voGroupIds, $backend);

		$this->assertTrue($result['success']);
		$this->assertEquals(2, $result['synced']);
		$this->assertEquals(0, $result['failed']);
		$this->assertCount(2, $result['results']);
		foreach ($result['results'] as $groupResult) {
			$this->assertEquals('success', $groupResult['status']);
			$this->assertArrayHasKey('added', $groupResult);
			$this->assertArrayHasKey('removed', $groupResult);
		}
	}

	public function testSyncHandlesDeletedGroupsInVO() {
		$ncGroup = $this->groupManager->createGroup('uservo_test_789');
		$this->assertNotNull($ncGroup);
		$this->createTestGroupInDB('test_789', 'uservo_test_789', 'Test Group Gone');

		// Group no longer appears in VO's group list (deleted in VO), but a
		// different group is still returned so fetchAllGroups() isn't empty.
		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchAllGroups')->willReturn([
			['id' => 'some_other_group', 'name' => 'Still There', 'parentid' => null, 'pos' => 1],
		]);

		$result = $this->service->syncSingleGroupById('test_789', $backend);

		// Deletion in VO isn't a sync failure - the group is kept, using its
		// last-known (stored) name, and flagged as deleted for the admin UI.
		$this->assertTrue($result['success'], $result['error'] ?? '');
		$this->assertEquals('Test Group Gone', $result['vo_group_name']);

		$qb = $this->connection->getQueryBuilder();
		$qb->select('deleted_in_vo')
			->from('user_vo_groups')
			->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter('test_789')));
		$dbResult = $qb->executeQuery();
		$row = $dbResult->fetch();
		$dbResult->closeCursor();

		$this->assertEquals(1, $row['deleted_in_vo']);
	}

	/**
	 * Regression test for the lost-update race Step 18's per-group lease
	 * closes: at real production scale, overlapping syncs of the same
	 * shared group (driven by NC's periodic credential-token revalidation
	 * across many active sessions) can interleave their reads/writes of
	 * user_vo and NC group membership, and a straggler acting on a stale
	 * snapshot can silently restore membership a concurrent sync just
	 * removed. PHPUnit can't run genuinely concurrent syncs, so this
	 * verifies the mechanism the fix actually depends on directly: while
	 * another sync holds a group's lease, a non-blocking sync of that same
	 * group must not touch its NC membership at all (not "usually skip" -
	 * genuinely never mutate while locked), and must catch up correctly
	 * once the lease is released. If the lock-acquire wrapper were ever
	 * bypassed or miswired, this would start mutating membership on the
	 * locked call and fail.
	 */
	public function testNonBlockingSyncNeverMutatesMembershipWhileGroupIsLocked(): void {
		$voGroupId = 'test_lockrace';
		$ncGroupId = 'uservo_test_lockrace';
		$uid = 'testuser_lockrace';

		$this->groupManager->createGroup($ncGroupId);
		$this->createTestGroupInDB($voGroupId, $ncGroupId, 'Test Lock Race Group');

		if (!$this->userManager->userExists($uid)) {
			$this->userManager->createUser($uid, 'ATestPassword123!');
		}
		$qb = $this->connection->getQueryBuilder();
		$qb->insert('user_vo')->values([
			'uid' => $qb->createNamedParameter($uid),
			'backend' => $qb->createNamedParameter('user_vo'),
			'vo_group_ids' => $qb->createNamedParameter($voGroupId),
		])->executeStatement();

		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchAllGroups')->willReturn([
			['id' => $voGroupId, 'name' => 'Test Lock Race Group', 'parentid' => null, 'pos' => 1],
		]);

		$lockService = new GroupSyncLockService($this->connection);

		try {
			// Simulate "another sync is already running for this group."
			$this->assertTrue($lockService->tryAcquire($voGroupId));

			$result = $this->service->syncGroupsByIds([$voGroupId], $backend, nonBlocking: true);
			$this->assertTrue($result['success']);
			$this->assertEmpty($result['results'][0]['added'], 'Locked sync must not report any membership change');

			$ncGroup = $this->groupManager->get($ncGroupId);
			$members = array_map(fn ($u) => $u->getUID(), $ncGroup->getUsers());
			$this->assertNotContains($uid, $members, 'User must NOT have been added while the group was locked');
		} finally {
			$lockService->release($voGroupId);
		}

		// Lease is free again - the same sync should now apply normally.
		$result = $this->service->syncGroupsByIds([$voGroupId], $backend, nonBlocking: true);
		$this->assertTrue($result['success']);
		$this->assertContains($uid, $result['results'][0]['added']);

		$ncGroup = $this->groupManager->get($ncGroupId);
		$members = array_map(fn ($u) => $u->getUID(), $ncGroup->getUsers());
		$this->assertContains($uid, $members, 'User should be added once the lease is available');

		$this->userManager->get($uid)?->delete();
	}
}
