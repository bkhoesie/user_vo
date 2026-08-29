<?php
namespace OCA\UserVO\Tests\Integration\Service;

use OCA\UserVO\Service\AuditLogService;
use OCA\UserVO\Service\GroupSyncService;
use OCA\UserVO\Service\GroupNameHarmonizer;
use OCA\UserVO\Service\GroupSyncLedgerService;
use OCA\UserVO\Service\GroupSyncLockService;
use OCA\UserVO\UserVOAuth;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
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
	private GroupSyncLedgerService $ledgerService;

	protected function setUp(): void {
		parent::setUp();

		$this->connection = \OC::$server->get(\OCP\IDBConnection::class);
		$this->groupManager = \OC::$server->get(\OCP\IGroupManager::class);
		$this->userManager = \OC::$server->get(\OCP\IUserManager::class);
		$harmonizer = new GroupNameHarmonizer();
		$lockService = new GroupSyncLockService($this->connection);
		$this->ledgerService = new GroupSyncLedgerService($this->connection, $this->createMock(LoggerInterface::class));

		$this->service = new GroupSyncService(
			$this->connection,
			$this->groupManager,
			$this->userManager,
			$harmonizer,
			$lockService,
			$this->ledgerService,
			\OC::$server->get(AuditLogService::class)
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
		$testGroups = ['uservo_test_123', 'uservo_test_456', 'uservo_test_556', 'uservo_test_789', 'uservo_test_lockrace', 'uservo_test_contended', 'uservo_test_deleted_midsync', 'uservo_test_bulk_locked', 'uservo_test_bulk_free', 'uservo_test_nonblocking_missing', 'uservo_test_nonblocking_api_down', 'uservo_test_concurrent_write', 'uservo_test_no_concurrent_write', 'uservo_test_contended_ledger', 'uservo_test_throws_adduser', 'uservo_test_lease_expire_mid', 'uservo_test_seq_after_wait', 'uservo_test_pidx_child', 'uservo_test_pos_zero_unchanged', 'uservo_test_throwable_good', 'uservo_test_throwable_bad', 'uservo_test_toctou_deleted_during_wait', 'uservo_test_blocking_api_down_single', 'uservo_test_blocking_api_down_byids', 'uservo_test_blocking_api_down_all', 'uservo_test_already_deleted', 'uservo_test_login_already_deleted', 'uservo_test_flag_survives_login', 'uservo_test_restore', 'uservo_test_mass_removal', 'uservo_test_duplicate_excluded', 'uservo_test_baddata_1', 'uservo_test_baddata_2', 'uservo_test_baddata_3', 'uservo_test_downapi_1', 'uservo_test_downapi_2', 'uservo_test_downapi_3', 'uservo_test_structurally_empty', 'uservo_test_nb_lease_reassigned', 'uservo_test_vgs_populated', 'uservo_test_vgs_skip_untouched', 'uservo_test_login_never_restores_flag'];
		foreach ($testGroups as $groupId) {
			if ($this->groupManager->groupExists($groupId)) {
				$group = $this->groupManager->get($groupId);
				if ($group) {
					$group->delete();
				}
			}
		}

		// Delete test users
		$testUsers = ['testuser1', 'testuser2', 'testuser3', 'testuser_lockrace', 'testuser_nonblocking_api_down', 'testuser_concurrent_write', 'testuser_throw_a', 'testuser_throw_b', 'testuser_already_deleted_member', 'testuser_mass_removal'];
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

	/**
	 * Direct group_user table read, deliberately bypassing IGroup::getUsers()
	 * and IGroupManager::isInGroup() - both cache membership in-process (the
	 * cached Group object's user list, resp. IGroupManager's per-uid group
	 * list) and stable28's Group::addUser() has a cache-staleness quirk
	 * (`if ($this->users)` treats a freshly-created group's empty array as
	 * falsy and skips updating the cache) that a getUsers() call can observe
	 * as a stale empty membership list despite the row already being
	 * written. A raw read of the actual persisted state avoids both caches
	 * and works the same whether checked once or, as in the lock-race test
	 * below, twice for the same uid/group.
	 */
	private function isUserInNcGroup(string $uid, string $gid): bool {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('uid')->from('group_user')
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('gid', $qb->createNamedParameter($gid)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row !== false;
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

	/**
	 * Regression test for the TOCTOU narrowing in syncSingleGroupFull()'s
	 * blocking path: the upfront groupExists() check only rules out the
	 * group having been deleted *before* the bounded wait started - if it's
	 * deleted *during* the wait itself (a concurrent deleteGroup() call, say),
	 * a failed acquire afterward must still be reported as "no longer
	 * exists" (500), not misleadingly as lock contention (409), since both
	 * look identical (0 affected rows) to the conditional UPDATE.
	 */
	public function testSyncSingleGroupByIdReportsGroupGoneWhenDeletedDuringTheWait(): void {
		$voGroupId = 'test_toctou_deleted_during_wait';
		$ncGroupId = 'uservo_test_toctou_deleted_during_wait';
		$this->createTestGroupInDB($voGroupId, $ncGroupId, 'TOCTOU Test');

		// Simulates: exists at the upfront check, gone by the time the wait
		// gives up (a concurrent delete happened in between).
		$mockLockService = $this->createMock(GroupSyncLockService::class);
		$mockLockService->method('groupExists')->willReturnOnConsecutiveCalls(true, false);
		$mockLockService->method('acquireWithBoundedWait')->willReturn(null);

		$service = new GroupSyncService(
			$this->connection,
			$this->groupManager,
			$this->userManager,
			new GroupNameHarmonizer(),
			$mockLockService,
			$this->ledgerService,
			\OC::$server->get(AuditLogService::class)
		);

		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchAllGroups')->willReturn([
			['id' => $voGroupId, 'name' => 'TOCTOU Test', 'parentid' => null, 'pos' => 1],
		]);

		$result = $service->syncSingleGroupById($voGroupId, $backend);

		$this->assertFalse($result['success']);
		$this->assertEquals('Group no longer exists', $result['error']);
		$this->assertEquals(500, $result['status_code'], 'Must be distinguishable from a 409 lock-contention response');
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
		$backend->method('fetchGroupMembers')->willReturn([]);

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

	/**
	 * Regression test: GroupManagementService::calculatePositionIndex() (used
	 * on group creation) and this service's own calculatePositionIndex() (used
	 * here, when a sync detects a parent/position change) used to be two
	 * separate implementations writing incompatible formats to the same
	 * vo_position_index column - a dotted hierarchical string like "3.2" vs. a
	 * bare depth-first-traversal integer. Since both services now delegate to
	 * the single copy living on this class, a position change picked up
	 * during sync must still produce the same dotted format group creation
	 * would have produced for the same position.
	 */
	public function testSyncRecalculatesPositionIndexInDottedFormatOnPositionChange(): void {
		$parentVoId = 'test_pidx_parent';
		$childVoId = 'test_pidx_child';
		$childNcId = 'uservo_test_pidx_child';

		// Parent already recorded with a root-level dotted index ("3").
		$qb = $this->connection->getQueryBuilder();
		$qb->insert('user_vo_groups')->values([
			'vo_group_id' => $qb->createNamedParameter($parentVoId),
			'vo_group_name' => $qb->createNamedParameter('Pidx Parent'),
			'nc_group_id' => $qb->createNamedParameter('uservo_test_pidx_parent'),
			'nc_display_name' => $qb->createNamedParameter('Pidx Parent'),
			'vo_parent_id' => $qb->createNamedParameter(null),
			'vo_position' => $qb->createNamedParameter(3, \PDO::PARAM_INT),
			'vo_position_index' => $qb->createNamedParameter('3'),
			'deleted_in_vo' => $qb->createNamedParameter(0, \PDO::PARAM_INT),
			'member_count' => $qb->createNamedParameter(0, \PDO::PARAM_INT),
			'vo_member_count' => $qb->createNamedParameter(0, \PDO::PARAM_INT),
			'non_vo_member_count' => $qb->createNamedParameter(0, \PDO::PARAM_INT),
		])->executeStatement();

		$ncGroup = $this->groupManager->createGroup($childNcId);
		$this->assertNotNull($ncGroup);

		// Child starts at position 1 under the parent ("3.1").
		$qb = $this->connection->getQueryBuilder();
		$qb->insert('user_vo_groups')->values([
			'vo_group_id' => $qb->createNamedParameter($childVoId),
			'vo_group_name' => $qb->createNamedParameter('Pidx Child'),
			'nc_group_id' => $qb->createNamedParameter($childNcId),
			'nc_display_name' => $qb->createNamedParameter('Pidx Child'),
			'vo_parent_id' => $qb->createNamedParameter($parentVoId),
			'vo_position' => $qb->createNamedParameter(1, \PDO::PARAM_INT),
			'vo_position_index' => $qb->createNamedParameter('3.1'),
			'deleted_in_vo' => $qb->createNamedParameter(0, \PDO::PARAM_INT),
			'member_count' => $qb->createNamedParameter(0, \PDO::PARAM_INT),
			'vo_member_count' => $qb->createNamedParameter(0, \PDO::PARAM_INT),
			'non_vo_member_count' => $qb->createNamedParameter(0, \PDO::PARAM_INT),
		])->executeStatement();

		// VO now reports the child moved to position 2 under the same parent.
		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchAllGroups')->willReturn([
			['id' => $parentVoId, 'name' => 'Pidx Parent', 'parentid' => null, 'pos' => 3],
			['id' => $childVoId, 'name' => 'Pidx Child', 'parentid' => $parentVoId, 'pos' => 2],
		]);
		$backend->method('fetchGroupMembers')->willReturn([]);

		$result = $this->service->syncSingleGroupById($childVoId, $backend);
		$this->assertTrue($result['success']);

		$qb = $this->connection->getQueryBuilder();
		$row = $qb->select('vo_position_index')->from('user_vo_groups')
			->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter($childVoId)))
			->executeQuery()->fetch();

		$this->assertSame('3.2', $row['vo_position_index'], 'Position index must stay in the dotted hierarchical format GroupManagementService also writes, not a bare depth-first integer');
	}

	/**
	 * Regression test: a stored position of 0 must not be treated as "no
	 * position stored" - $groupRow['vo_position'] is falsy for a real, valid
	 * position of 0, so a truthy check there (rather than isset()) made
	 * every sync think a position-0 group's position had "changed" (int 0
	 * vs. the truthy-check's null), triggering an unnecessary
	 * vo_position_index recalculation on every single sync of such a group.
	 */
	public function testSyncDoesNotRecalculatePositionIndexWhenPositionZeroIsUnchanged(): void {
		$voGroupId = 'test_pos_zero_unchanged';
		$ncGroupId = 'uservo_test_pos_zero_unchanged';

		$ncGroup = $this->groupManager->createGroup($ncGroupId);
		$this->assertNotNull($ncGroup);

		$qb = $this->connection->getQueryBuilder();
		$qb->insert('user_vo_groups')->values([
			'vo_group_id' => $qb->createNamedParameter($voGroupId),
			'vo_group_name' => $qb->createNamedParameter('Pos Zero Unchanged'),
			'nc_group_id' => $qb->createNamedParameter($ncGroupId),
			'nc_display_name' => $qb->createNamedParameter('Pos Zero Unchanged'),
			'vo_parent_id' => $qb->createNamedParameter(null),
			'vo_position' => $qb->createNamedParameter(0, \PDO::PARAM_INT),
			// A sentinel calculatePositionIndex() could never legitimately
			// produce - if it survives the sync untouched, the recalculation
			// branch correctly didn't fire; if it's replaced, it did.
			'vo_position_index' => $qb->createNamedParameter('SENTINEL_UNCHANGED'),
			'deleted_in_vo' => $qb->createNamedParameter(0, \PDO::PARAM_INT),
			'member_count' => $qb->createNamedParameter(0, \PDO::PARAM_INT),
			'vo_member_count' => $qb->createNamedParameter(0, \PDO::PARAM_INT),
			'non_vo_member_count' => $qb->createNamedParameter(0, \PDO::PARAM_INT),
		])->executeStatement();

		// VO reports the exact same position (0) - nothing actually changed.
		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchAllGroups')->willReturn([
			['id' => $voGroupId, 'name' => 'Pos Zero Unchanged', 'parentid' => null, 'pos' => 0],
		]);
		$backend->method('fetchGroupMembers')->willReturn([]);

		$result = $this->service->syncSingleGroupById($voGroupId, $backend);
		$this->assertTrue($result['success']);

		$qb = $this->connection->getQueryBuilder();
		$row = $qb->select('vo_position_index')->from('user_vo_groups')
			->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter($voGroupId)))
			->executeQuery()->fetch();

		$this->assertSame('SENTINEL_UNCHANGED', $row['vo_position_index'], 'Must not recalculate the position index when nothing about the position/parent actually changed');
	}

	/**
	 * Regression test: a group whose sync throws a genuine \Error (not just
	 * \Exception - a \TypeError from unexpected VO data, say) must not abort
	 * the rest of the batch. Reachable from the login path via
	 * UserVOAuth::syncUserGroupsOnLogin() (itself only wrapped in
	 * catch(\Exception) too), so catching only \Exception here would let
	 * such an error escape this loop, the outer function, and abort the
	 * entire login - same reasoning as GroupSyncSweepJob's equivalent
	 * per-group loop, which already catches \Throwable.
	 */
	public function testSyncGroupsByIdsRecoversFromAnErrorInOneGroupAndStillSyncsTheRest(): void {
		$goodVoId = 'test_throwable_good';
		$goodNcId = 'uservo_test_throwable_good';
		$badVoId = 'test_throwable_bad';
		$badNcId = 'uservo_test_throwable_bad';

		$realGoodGroup = $this->groupManager->createGroup($goodNcId);
		$this->assertNotNull($realGoodGroup);
		$this->createTestGroupInDB($goodVoId, $goodNcId, 'Throwable Good');
		$this->createTestGroupInDB($badVoId, $badNcId, 'Throwable Bad');

		$mockGroupManager = $this->createMock(IGroupManager::class);
		$mockGroupManager->method('get')->willReturnCallback(function ($gid) use ($goodNcId, $badNcId, $realGoodGroup) {
			if ($gid === $badNcId) {
				throw new \Error('Simulated fatal error deep in group lookup');
			}
			if ($gid === $goodNcId) {
				return $realGoodGroup;
			}
			return null;
		});

		$service = new GroupSyncService(
			$this->connection,
			$mockGroupManager,
			$this->userManager,
			new GroupNameHarmonizer(),
			new GroupSyncLockService($this->connection),
			$this->ledgerService,
			\OC::$server->get(AuditLogService::class)
		);

		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchAllGroups')->willReturn([
			['id' => $goodVoId, 'name' => 'Throwable Good', 'parentid' => null, 'pos' => 1],
			['id' => $badVoId, 'name' => 'Throwable Bad', 'parentid' => null, 'pos' => 2],
		]);
		$backend->method('fetchGroupMembers')->willReturn([]);

		$result = $service->syncGroupsByIds([$badVoId, $goodVoId], $backend);

		$this->assertTrue($result['success'], 'The batch overall must still succeed despite one group erroring');
		$this->assertEquals(1, $result['synced']);
		$this->assertEquals(1, $result['failed']);

		$statuses = array_column($result['results'], 'status', 'vo_group_id');
		$this->assertEquals('error', $statuses[$badVoId]);
		$this->assertEquals('success', $statuses[$goodVoId]);
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
		$backend->method('fetchGroupMembers')->willReturn([]);

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
		$backend->method('fetchGroupMembers')->willReturn([]);

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
	 * Same "missing from the fetched VO group map" shape as above, but via
	 * the non-blocking (login) path - the only path that can ever see a
	 * cached/stale map, not a guaranteed-live one. A group missing from a
	 * possibly-stale snapshot isn't trustworthy evidence it was actually
	 * deleted, so deleted_in_vo must NOT get set here, unlike the blocking
	 * path above.
	 */
	public function testNonBlockingSyncDoesNotFlagDeletedInVoFromAPossiblyStaleMap() {
		$ncGroup = $this->groupManager->createGroup('uservo_test_nonblocking_missing');
		$this->assertNotNull($ncGroup);
		$this->createTestGroupInDB('test_nonblocking_missing', 'uservo_test_nonblocking_missing', 'Test Group Maybe Gone');

		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchAllGroups')->willReturn([
			['id' => 'some_other_group', 'name' => 'Still There', 'parentid' => null, 'pos' => 1],
		]);
		// The login path never trusts deleted_in_vo, so it still calls
		// fetchGroupMembers() here rather than taking the skip branch - an
		// empty result is then treated as untrusted (see the class's own
		// empty-distrust rule), not as evidence of deletion either.
		$backend->method('fetchGroupMembers')->willReturn([]);

		$result = $this->service->syncGroupsByIds(['test_nonblocking_missing'], $backend, nonBlocking: true);

		$this->assertTrue($result['success']);
		$this->assertEquals(1, $result['synced']);

		$qb = $this->connection->getQueryBuilder();
		$qb->select('deleted_in_vo', 'vo_group_name')
			->from('user_vo_groups')
			->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter('test_nonblocking_missing')));
		$dbResult = $qb->executeQuery();
		$row = $dbResult->fetch();
		$dbResult->closeCursor();

		$this->assertEquals(0, $row['deleted_in_vo'], 'Must not flag deletion from a map that might just be a stale/cached snapshot');
		$this->assertEquals('Test Group Maybe Gone', $row['vo_group_name'], 'Should still keep the last-known name, same as the blocking path');
	}

	/**
	 * A GetGroups failure must not abort login-time membership sync -
	 * membership doesn't depend on that data at all (it comes from the local
	 * user_vo table). Previously this aborted the whole login-triggered
	 * batch, silently breaking "log in again and it'll sync" for exactly the
	 * case that goal cares about: a genuine VO metadata outage.
	 */
	public function testNonBlockingSyncStillSyncsMembershipWhenGetGroupsFailsEntirely() {
		$voGroupId = 'test_nonblocking_api_down';
		$ncGroupId = 'uservo_test_nonblocking_api_down';
		$uid = 'testuser_nonblocking_api_down';

		$this->groupManager->createGroup($ncGroupId);
		$this->createTestGroupInDB($voGroupId, $ncGroupId, 'Test Group API Down');

		if (!$this->userManager->userExists($uid)) {
			$this->userManager->createUser($uid, 'ATestPassword123!');
		}
		$qb = $this->connection->getQueryBuilder();
		$qb->insert('user_vo')->values([
			'uid' => $qb->createNamedParameter($uid),
			'backend' => $qb->createNamedParameter('user_vo'),
			'vo_user_id' => $qb->createNamedParameter('vo_user_nonblocking_api_down'),
		])->executeStatement();

		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchAllGroups')->willReturn(null);
		// Membership comes from this direct per-group fetch, a call
		// independent of fetchAllGroups() (used only for cosmetic metadata) -
		// its failure above must not stop this from being attempted.
		$backend->method('fetchGroupMembers')->willReturn([
			['id' => 'vo_user_nonblocking_api_down', 'name' => 'Test, User'],
		]);

		$result = $this->service->syncGroupsByIds([$voGroupId], $backend, nonBlocking: true);

		$this->assertTrue($result['success'], 'Membership sync must still succeed despite the metadata fetch failure');
		$this->assertEquals(1, $result['synced']);
		$this->assertContains($uid, $result['results'][0]['added']);

		$this->assertTrue($this->isUserInNcGroup($uid, $ncGroupId), 'User should actually be added to the NC group');

		$this->userManager->get($uid)?->delete();
	}

	/**
	 * Blocking callers (admin/manual sync, unlike the login path above) must
	 * fail loudly - and record the failure in the audit log - rather than
	 * silently doing nothing, when the VO groups fetch fails entirely.
	 */
	public function testSyncSingleGroupByIdRecordsAuditLogEntryWhenGetGroupsFailsEntirely(): void {
		$voGroupId = 'test_blocking_api_down_single';
		$ncGroupId = 'uservo_test_blocking_api_down_single';
		$this->groupManager->createGroup($ncGroupId);
		$this->createTestGroupInDB($voGroupId, $ncGroupId, 'Test Group Blocking API Down');

		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchAllGroups')->willReturn(null);

		$result = $this->service->syncSingleGroupById($voGroupId, $backend);

		$this->assertFalse($result['success']);
		$this->assertEquals(500, $result['status_code']);
		$this->assertAuditLogHasFetchFailedEntry($voGroupId, 'Group sync failed');
	}

	public function testSyncGroupsByIdsRecordsAuditLogEntryWhenGetGroupsFailsEntirelyAndBlocking(): void {
		$voGroupId = 'test_blocking_api_down_byids';
		$ncGroupId = 'uservo_test_blocking_api_down_byids';
		$this->groupManager->createGroup($ncGroupId);
		$this->createTestGroupInDB($voGroupId, $ncGroupId, 'Test Group Blocking API Down ByIds');

		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchAllGroups')->willReturn(null);

		$result = $this->service->syncGroupsByIds([$voGroupId], $backend, nonBlocking: false);

		$this->assertFalse($result['success']);
		$this->assertAuditLogHasFetchFailedEntry(null, 'vo_group_ids: ' . $voGroupId);
	}

	public function testSyncAllManagedGroupsRecordsAuditLogEntryWhenGetGroupsFailsEntirely(): void {
		$voGroupId = 'test_blocking_api_down_all';
		$ncGroupId = 'uservo_test_blocking_api_down_all';
		$this->groupManager->createGroup($ncGroupId);
		$this->createTestGroupInDB($voGroupId, $ncGroupId, 'Test Group Blocking API Down All');

		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchAllGroups')->willReturn(null);

		$result = $this->service->syncAllManagedGroups($backend);

		$this->assertFalse($result['success']);
		$this->assertAuditLogHasFetchFailedEntry(null, 'Bulk group sync failed');
	}

	/**
	 * Asserts a 'vo_api_fetch_failed' audit log entry exists, then deletes
	 * it (not covered by cleanupTestData()). Matches on message text too,
	 * not just event_type + group_id - for the two null-group_id call sites
	 * that pair alone is loose enough that a leftover row from an aborted
	 * earlier test run (cleanup here is inline, not in tearDown(), so an
	 * assertion failure above leaks the row) could satisfy it.
	 */
	private function assertAuditLogHasFetchFailedEntry(?string $expectedGroupId, string $expectedMessageSubstring): void {
		$auditLog = \OC::$server->get(AuditLogService::class);
		$entries = $auditLog->getRecentEntries();
		$entry = current(array_filter(
			$entries,
			fn ($e) => $e['event_type'] === 'vo_api_fetch_failed'
				&& $e['group_id'] === $expectedGroupId
				&& str_contains($e['message'], $expectedMessageSubstring)
		));
		$this->assertNotFalse($entry, 'Expected a vo_api_fetch_failed audit log entry (group_id=' . ($expectedGroupId ?? 'null') . ', message containing "' . $expectedMessageSubstring . '")');

		$deleteQb = $this->connection->getQueryBuilder();
		$deleteQb->delete('user_vo_audit_log')
			->where($deleteQb->expr()->eq('id', $deleteQb->createNamedParameter($entry['id'], \PDO::PARAM_INT)))
			->executeStatement();
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
			'vo_user_id' => $qb->createNamedParameter('vo_user_lockrace'),
		])->executeStatement();

		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchAllGroups')->willReturn([
			['id' => $voGroupId, 'name' => 'Test Lock Race Group', 'parentid' => null, 'pos' => 1],
		]);
		$backend->method('fetchGroupMembers')->willReturn([
			['id' => 'vo_user_lockrace', 'name' => 'Test, User'],
		]);

		$lockService = new GroupSyncLockService($this->connection);

		try {
			// Simulate "another sync is already running for this group."
			$lockToken = $lockService->tryAcquire($voGroupId);
			$this->assertNotNull($lockToken);

			$result = $this->service->syncGroupsByIds([$voGroupId], $backend, nonBlocking: true);
			$this->assertTrue($result['success']);
			$this->assertEquals(1, $result['skipped'], 'A lock-contended sync must be counted as skipped, not synced');
			$this->assertEquals(0, $result['synced']);
			$this->assertEquals('skipped', $result['results'][0]['status'], 'Must be reported distinctly from a real success - not indistinguishable from an empty sync');

			$this->assertFalse($this->isUserInNcGroup($uid, $ncGroupId), 'User must NOT have been added while the group was locked');
		} finally {
			$lockService->release($voGroupId, $lockToken);
		}

		// Lease is free again - the same sync should now apply normally.
		$result = $this->service->syncGroupsByIds([$voGroupId], $backend, nonBlocking: true);
		$this->assertTrue($result['success']);
		$this->assertContains($uid, $result['results'][0]['added']);

		$this->assertTrue($this->isUserInNcGroup($uid, $ncGroupId), 'User should be added once the lease is available');

		$this->userManager->get($uid)?->delete();
	}

	/**
	 * The blocking path (used by everything except login-time sync) must
	 * surface genuine lock contention as a distinct, non-500 status - not
	 * indistinguishable from a real sync failure.
	 */
	public function testSyncSingleGroupByIdReturns409NotFor500WhenContended(): void {
		$voGroupId = 'test_contended';
		$ncGroupId = 'uservo_test_contended';

		$this->groupManager->createGroup($ncGroupId);
		$this->createTestGroupInDB($voGroupId, $ncGroupId, 'Test Contended Group');

		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchAllGroups')->willReturn([
			['id' => $voGroupId, 'name' => 'Test Contended Group', 'parentid' => null, 'pos' => 1],
		]);

		$lockService = new GroupSyncLockService($this->connection);
		$lockToken = $lockService->tryAcquire($voGroupId);
		$this->assertNotNull($lockToken);

		try {
			$start = microtime(true);
			$result = $this->service->syncSingleGroupById($voGroupId, $backend);
			$elapsed = microtime(true) - $start;

			$this->assertFalse($result['success']);
			$this->assertEquals(409, $result['status_code'], 'Genuine contention must be a distinct status, not a generic 500');
			$this->assertGreaterThanOrEqual(2.5, $elapsed, 'Should have actually waited close to the bound, not failed immediately');
		} finally {
			$lockService->release($voGroupId, $lockToken);
		}
	}

	/**
	 * A group deleted between syncSingleGroupById()'s own pre-check and the
	 * lock-acquire attempt must fail fast with an accurate message, not burn
	 * the full bounded wait only to misreport "already in progress" for a
	 * group that no longer exists at all.
	 */
	public function testSyncSingleGroupByIdFailsFastWithAccurateMessageWhenGroupDeletedMidSync(): void {
		$voGroupId = 'test_deleted_midsync';
		$ncGroupId = 'uservo_test_deleted_midsync';

		$this->groupManager->createGroup($ncGroupId);
		$this->createTestGroupInDB($voGroupId, $ncGroupId, 'Test Deleted Mid-Sync Group');

		$backend = $this->createMock(UserVOAuth::class);
		// Simulate a concurrent deletion landing between the pre-check (which
		// already passed once we get here) and the lock-acquire attempt.
		$backend->method('fetchAllGroups')->willReturnCallback(function () use ($voGroupId) {
			$qb = $this->connection->getQueryBuilder();
			$qb->delete('user_vo_groups')
				->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter($voGroupId)))
				->executeStatement();
			return [['id' => $voGroupId, 'name' => 'Test Deleted Mid-Sync Group', 'parentid' => null, 'pos' => 1]];
		});

		$start = microtime(true);
		$result = $this->service->syncSingleGroupById($voGroupId, $backend);
		$elapsed = microtime(true) - $start;

		$this->assertFalse($result['success']);
		$this->assertEquals('Group no longer exists', $result['error']);
		$this->assertLessThan(1.0, $elapsed, 'Must fail fast instead of burning the full bounded wait');
	}

	/**
	 * The lease must be released via the wrapper's finally block even when
	 * the locked sync body itself throws mid-way - otherwise a single failed
	 * sync would wedge that group's lease for its full TTL, well beyond any
	 * reasonable retry. Only the happy (successful-release) path was
	 * previously covered.
	 */
	public function testLeaseIsReleasedWhenLockedSyncBodyThrows(): void {
		$voGroupId = 'test_throws_midsync';
		// Points at an NC group that was never created - syncSingleGroupFullLocked()
		// throws "NC group does not exist" once it gets past the lock-acquire.
		$this->createTestGroupInDB($voGroupId, 'uservo_test_throws_midsync_missing', 'Test Throws Group');

		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchAllGroups')->willReturn([
			['id' => $voGroupId, 'name' => 'Test Throws Group', 'parentid' => null, 'pos' => 1],
		]);

		$result = $this->service->syncSingleGroupById($voGroupId, $backend);
		$this->assertFalse($result['success']);
		$this->assertStringContainsString('NC group does not exist', $result['error']);

		$lockService = new GroupSyncLockService($this->connection);
		$token = $lockService->tryAcquire($voGroupId);
		$this->assertNotNull($token, 'Lease must have been released despite the body throwing - a failed sync must not wedge the lease');
		$lockService->release($voGroupId, $token);
	}

	/**
	 * Contention on one group during a bulk sync must surface as that one
	 * group's failure, not abort or corrupt the results for the other,
	 * unlocked groups in the same batch.
	 */
	public function testBulkSyncReportsContentionOnOneGroupWithoutAffectingOthers(): void {
		$lockedVoGroupId = 'test_bulk_locked';
		$freeVoGroupId = 'test_bulk_free';

		$this->groupManager->createGroup('uservo_test_bulk_locked');
		$this->createTestGroupInDB($lockedVoGroupId, 'uservo_test_bulk_locked', 'Locked Group');
		$this->groupManager->createGroup('uservo_test_bulk_free');
		$this->createTestGroupInDB($freeVoGroupId, 'uservo_test_bulk_free', 'Free Group');

		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchAllGroups')->willReturn([
			['id' => $lockedVoGroupId, 'name' => 'Locked Group', 'parentid' => null, 'pos' => 1],
			['id' => $freeVoGroupId, 'name' => 'Free Group', 'parentid' => null, 'pos' => 2],
		]);
		$backend->method('fetchGroupMembers')->willReturn([]);

		$lockService = new GroupSyncLockService($this->connection);
		$token = $lockService->tryAcquire($lockedVoGroupId);
		$this->assertNotNull($token);

		try {
			$result = $this->service->syncAllManagedGroups($backend);
		} finally {
			$lockService->release($lockedVoGroupId, $token);
		}

		$this->assertTrue($result['success'], 'A single contended group must not fail the whole bulk sync');
		$byGroupId = [];
		foreach ($result['results'] as $groupResult) {
			$byGroupId[$groupResult['vo_group_id']] = $groupResult;
		}

		$this->assertEquals('error', $byGroupId[$lockedVoGroupId]['status']);
		$this->assertStringContainsString('already in progress', $byGroupId[$lockedVoGroupId]['error']);
		$this->assertEquals('success', $byGroupId[$freeVoGroupId]['status'], 'The unlocked group must still sync normally');
	}

	/**
	 * Login-time (non-blocking) sync must actually wait up to its shared
	 * budget on contention rather than giving up instantly - a login that
	 * wins the lock within budget reads fresh data and can repair a
	 * concurrent sync's stale result, instead of always just skipping.
	 */
	public function testNonBlockingSyncWaitsUpToSharedBudgetBeforeSkipping(): void {
		$voGroupId = 'test_login_wait';
		$this->createTestGroupInDB($voGroupId, 'uservo_test_login_wait', 'Test Login Wait Group');

		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchAllGroups')->willReturn([
			['id' => $voGroupId, 'name' => 'Test Login Wait Group', 'parentid' => null, 'pos' => 1],
		]);

		$lockService = new GroupSyncLockService($this->connection);
		// Long enough lease that it won't self-expire during this test.
		$token = $lockService->tryAcquire($voGroupId, 60);
		$this->assertNotNull($token);

		try {
			$start = microtime(true);
			$result = $this->service->syncGroupsByIds([$voGroupId], $backend, nonBlocking: true);
			$elapsed = microtime(true) - $start;

			$this->assertEquals(1, $result['skipped']);
			$this->assertGreaterThanOrEqual(0.7, $elapsed, 'Should have actually spent close to the wait budget, not skipped instantly');
			$this->assertLessThan(2.0, $elapsed, 'Must still be bounded, not the admin/cron 3s wait');
		} finally {
			$lockService->release($voGroupId, $token);
		}
	}

	/**
	 * The wait budget is shared across the whole login-triggered batch, not
	 * reset per group - otherwise a login with several contended groups
	 * could block for (budget x group count) instead of a bounded total.
	 */
	public function testNonBlockingSyncSharesWaitBudgetAcrossGroupsNotPerGroup(): void {
		$firstVoGroupId = 'test_login_wait_a';
		$secondVoGroupId = 'test_login_wait_b';
		$this->createTestGroupInDB($firstVoGroupId, 'uservo_test_login_wait_a', 'Test Login Wait Group A');
		$this->createTestGroupInDB($secondVoGroupId, 'uservo_test_login_wait_b', 'Test Login Wait Group B');

		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchAllGroups')->willReturn([
			['id' => $firstVoGroupId, 'name' => 'Test Login Wait Group A', 'parentid' => null, 'pos' => 1],
			['id' => $secondVoGroupId, 'name' => 'Test Login Wait Group B', 'parentid' => null, 'pos' => 2],
		]);

		$lockService = new GroupSyncLockService($this->connection);
		$tokenA = $lockService->tryAcquire($firstVoGroupId, 60);
		$tokenB = $lockService->tryAcquire($secondVoGroupId, 60);
		$this->assertNotNull($tokenA);
		$this->assertNotNull($tokenB);

		try {
			$start = microtime(true);
			$result = $this->service->syncGroupsByIds([$firstVoGroupId, $secondVoGroupId], $backend, nonBlocking: true);
			$elapsed = microtime(true) - $start;

			$this->assertEquals(2, $result['skipped']);
			// If the budget reset per group, two contended groups would take
			// roughly 2x the total budget instead of sharing one.
			$this->assertLessThan(1.8, $elapsed, 'Wait budget must be shared across the batch, not given fresh to each group');
		} finally {
			$lockService->release($firstVoGroupId, $tokenA);
			$lockService->release($secondVoGroupId, $tokenB);
		}
	}

	/**
	 * Headline regression test for the dirty/clean ledger's core guarantee,
	 * re-aimed for the direct-fetch membership design (this used to drive the
	 * race via a concurrent write to the now-unused vo_group_ids column - see
	 * git history for the pre-redesign version). syncSingleGroupFullLocked()
	 * captures seqAtStart right after acquiring the group's lease, before its
	 * own live membership fetch. If VO reports a change affecting this same
	 * group for a *different* user while this sync is still in flight (that
	 * user's own login dirty-marking the group), this sync's own snapshot -
	 * already read before that new mark landed - must not claim clean past
	 * it: the group must end dirty, not falsely clean, so the sweep picks up
	 * what this sync's own fetch could have missed.
	 *
	 * PHPUnit can't run genuinely concurrent syncs (same caveat as
	 * testNonBlockingSyncNeverMutatesMembershipWhileGroupIsLocked above), so
	 * this drives the race window directly via a mocked IGroup::getUsers()
	 * callback - called at exactly the point such a concurrent dirty-mark
	 * would land, after this sync's own membership fetch already ran.
	 */
	public function testConcurrentDirtyMarkDuringSyncLeavesGroupDirtyRatherThanFalselyClean(): void {
		$voGroupId = 'test_concurrent_write';
		$ncGroupId = 'uservo_test_concurrent_write';

		$this->createTestGroupInDB($voGroupId, $ncGroupId, 'Test Concurrent Write Group');

		$mockGroup = $this->createMock(\OCP\IGroup::class);
		$mockGroup->method('getUsers')->willReturnCallback(function () use ($voGroupId) {
			// Simulates a different user's own login dirty-marking this same
			// group (e.g. UserVOAuth::updateVOMetadata()'s symmetric diff)
			// landing after this sync's own fetch, but before it completes.
			$this->ledgerService->markDirty([$voGroupId]);
			return [];
		});

		$mockGroupManager = $this->createMock(IGroupManager::class);
		$mockGroupManager->method('get')->willReturn($mockGroup);

		$service = new GroupSyncService(
			$this->connection,
			$mockGroupManager,
			$this->userManager,
			new GroupNameHarmonizer(),
			new GroupSyncLockService($this->connection),
			$this->ledgerService,
			\OC::$server->get(AuditLogService::class)
		);

		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchAllGroups')->willReturn([
			['id' => $voGroupId, 'name' => 'Test Concurrent Write Group', 'parentid' => null, 'pos' => 1],
		]);
		$backend->method('fetchGroupMembers')->willReturn([]);

		$result = $service->syncSingleGroupById($voGroupId, $backend);
		$this->assertTrue($result['success'], $result['error'] ?? '');

		[$dirty, $clean] = $this->readSeqs($voGroupId);
		$this->assertGreaterThan($clean, $dirty, 'A dirty mark landing during the sync window must leave the group dirty, not falsely clean');
	}

	/**
	 * Negative control for the test above: without it, an implementation that
	 * never advances clean_seq at all would also "pass" - always dirty is not
	 * the same as correctly tracking dirt.
	 */
	public function testSyncMarksGroupCleanWhenNoWriteLandsDuringTheWindow(): void {
		$voGroupId = 'test_no_concurrent_write';
		$ncGroupId = 'uservo_test_no_concurrent_write';

		$this->groupManager->createGroup($ncGroupId);
		$this->createTestGroupInDB($voGroupId, $ncGroupId, 'Test No Concurrent Write Group');
		$this->ledgerService->markDirty([$voGroupId]); // something for the sync to actually clear

		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchAllGroups')->willReturn([
			['id' => $voGroupId, 'name' => 'Test No Concurrent Write Group', 'parentid' => null, 'pos' => 1],
		]);
		$backend->method('fetchGroupMembers')->willReturn([]);

		$result = $this->service->syncSingleGroupById($voGroupId, $backend);
		$this->assertTrue($result['success'], $result['error'] ?? '');

		[$dirty, $clean] = $this->readSeqs($voGroupId);
		$this->assertSame($dirty, $clean, 'A sync with no concurrent write must actually advance clean_seq to match');
	}

	/**
	 * A group skipped entirely due to lock contention (login path) must not
	 * have its ledger touched at all - it never captured a seq or reached the
	 * clean-advance, so it must stay exactly as dirty as it was, for the
	 * sweep to pick up later.
	 */
	public function testLockContendedLoginSyncLeavesGroupDirtyForTheSweep(): void {
		$voGroupId = 'test_contended_ledger';
		$ncGroupId = 'uservo_test_contended_ledger';
		$this->createTestGroupInDB($voGroupId, $ncGroupId, 'Test Contended Ledger Group');
		$this->ledgerService->markDirty([$voGroupId]);

		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchAllGroups')->willReturn([
			['id' => $voGroupId, 'name' => 'Test Contended Ledger Group', 'parentid' => null, 'pos' => 1],
		]);

		$lockService = new GroupSyncLockService($this->connection);
		$token = $lockService->tryAcquire($voGroupId);
		$this->assertNotNull($token);

		try {
			$result = $this->service->syncGroupsByIds([$voGroupId], $backend, nonBlocking: true);
			$this->assertEquals(1, $result['skipped']);
		} finally {
			$lockService->release($voGroupId, $token);
		}

		[$dirty, $clean] = $this->readSeqs($voGroupId);
		$this->assertGreaterThan($clean, $dirty, 'A skipped (lock-contended) sync must not advance clean_seq - the group must stay dirty for the sweep');
	}

	/**
	 * A sync that fails partway through applying membership (some
	 * add/removeUser calls already took effect) must leave the group dirty -
	 * otherwise a half-applied sync could masquerade as clean if a later
	 * caller swallows the exception (as most callers here do, to keep other
	 * groups in a batch unaffected).
	 */
	public function testSyncThatThrowsAfterMutatingMembershipLeavesGroupDirty(): void {
		$voGroupId = 'test_throws_adduser';
		$ncGroupId = 'uservo_test_throws_adduser';
		$uidA = 'testuser_throw_a';
		$uidB = 'testuser_throw_b';

		$this->createTestGroupInDB($voGroupId, $ncGroupId, 'Test Throws AddUser Group');
		$voUserIds = ['A' => 'vo_user_throw_a', 'B' => 'vo_user_throw_b'];
		foreach ([$uidA => $voUserIds['A'], $uidB => $voUserIds['B']] as $uid => $voUserId) {
			if (!$this->userManager->userExists($uid)) {
				$this->userManager->createUser($uid, 'ATestPassword123!');
			}
			$qb = $this->connection->getQueryBuilder();
			$qb->insert('user_vo')->values([
				'uid' => $qb->createNamedParameter($uid),
				'backend' => $qb->createNamedParameter('user_vo'),
				'vo_user_id' => $qb->createNamedParameter($voUserId),
			])->executeStatement();
		}

		$mockGroup = $this->createMock(\OCP\IGroup::class);
		$mockGroup->method('getUsers')->willReturn([]);
		$addUserCalls = 0;
		$mockGroup->method('addUser')->willReturnCallback(function () use (&$addUserCalls) {
			$addUserCalls++;
			if ($addUserCalls === 2) {
				throw new \Exception('Simulated failure partway through applying membership');
			}
		});

		$mockGroupManager = $this->createMock(IGroupManager::class);
		$mockGroupManager->method('get')->willReturn($mockGroup);

		$service = new GroupSyncService(
			$this->connection,
			$mockGroupManager,
			$this->userManager,
			new GroupNameHarmonizer(),
			new GroupSyncLockService($this->connection),
			$this->ledgerService,
			\OC::$server->get(AuditLogService::class)
		);

		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchAllGroups')->willReturn([
			['id' => $voGroupId, 'name' => 'Test Throws AddUser Group', 'parentid' => null, 'pos' => 1],
		]);
		$backend->method('fetchGroupMembers')->willReturn([
			['id' => $voUserIds['A'], 'name' => 'Throw, A'],
			['id' => $voUserIds['B'], 'name' => 'Throw, B'],
		]);

		$result = $service->syncSingleGroupById($voGroupId, $backend);
		$this->assertFalse($result['success']);

		[$dirty, $clean] = $this->readSeqs($voGroupId);
		$this->assertGreaterThan($clean, $dirty, 'A sync that fails partway through applying membership must leave the group dirty for the sweep to repair');
	}

	/**
	 * The opposite of the test above: a failure *before* any membership
	 * mutation was attempted (e.g. the NC group itself is missing) must NOT
	 * re-dirty the group - otherwise a permanently broken group would re-mark
	 * itself dirty forever, and the sweep would retry it every tick with no
	 * way to ever converge.
	 */
	public function testSyncThatThrowsBeforeMutatingMembershipDoesNotRedirty(): void {
		$voGroupId = 'test_throws_before_mutation';
		// Points at an NC group that was never created - throws "NC group does
		// not exist" before the membership-mutation try/catch block is ever entered.
		$this->createTestGroupInDB($voGroupId, 'uservo_test_throws_before_mutation_missing', 'Test Throws Before Mutation Group');

		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchAllGroups')->willReturn([
			['id' => $voGroupId, 'name' => 'Test Throws Before Mutation Group', 'parentid' => null, 'pos' => 1],
		]);

		$result = $this->service->syncSingleGroupById($voGroupId, $backend);
		$this->assertFalse($result['success']);
		$this->assertStringContainsString('NC group does not exist', $result['error']);

		[$dirty, $clean] = $this->readSeqs($voGroupId);
		$this->assertSame(0, $dirty, 'A failure before any mutation was attempted must not re-dirty the group');
		$this->assertSame(0, $clean);
	}

	/**
	 * The ledger analogue of testStaleReleaseDoesNotStealANewlyAcquiredLease:
	 * if this sync's own lease outlives its TTL and gets reassigned to
	 * another worker mid-body, this sync must not claim clean on completion -
	 * it may have just applied membership computed from a stale snapshot, and
	 * a second worker may already be (or about to be) acting on fresher data.
	 */
	public function testSyncWhoseLeaseExpiredMidBodyDoesNotClaimClean(): void {
		$voGroupId = 'test_lease_expire_mid';
		$ncGroupId = 'uservo_test_lease_expire_mid';
		$this->createTestGroupInDB($voGroupId, $ncGroupId, 'Test Lease Expire Mid Group');

		$lockService = new GroupSyncLockService($this->connection);

		$mockGroup = $this->createMock(\OCP\IGroup::class);
		$mockGroup->method('getUsers')->willReturnCallback(function () use ($voGroupId, $lockService) {
			$past = (new \DateTime())->modify('-1 second');
			$qb = $this->connection->getQueryBuilder();
			$qb->update('user_vo_groups')
				->set('sync_lock_until', $qb->createNamedParameter($past, 'datetime'))
				->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter($voGroupId)))
				->executeStatement();
			$otherToken = $lockService->tryAcquire($voGroupId, 60);
			$this->assertNotNull($otherToken, 'Second worker should acquire once the first lease is forced to expire');
			return [];
		});

		$mockGroupManager = $this->createMock(IGroupManager::class);
		$mockGroupManager->method('get')->willReturn($mockGroup);

		$service = new GroupSyncService(
			$this->connection,
			$mockGroupManager,
			$this->userManager,
			new GroupNameHarmonizer(),
			$lockService,
			$this->ledgerService,
			\OC::$server->get(AuditLogService::class)
		);

		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchAllGroups')->willReturn([
			['id' => $voGroupId, 'name' => 'Test Lease Expire Mid Group', 'parentid' => null, 'pos' => 1],
		]);
		$backend->method('fetchGroupMembers')->willReturn([]);

		// The original sync (whose lease was expired-and-reassigned mid-body
		// by its own getUsers() call above) still runs to completion and
		// tries to mark clean using its now-stale token.
		$result = $service->syncSingleGroupById($voGroupId, $backend);
		$this->assertTrue($result['success'], $result['error'] ?? '');

		[$dirty, $clean] = $this->readSeqs($voGroupId);
		$this->assertGreaterThan($clean, $dirty, 'A sync whose lease was reassigned mid-body must not claim clean');
	}

	/**
	 * A dirty mark that lands while a sync is queued waiting for a contended
	 * lease (not yet acquired) must still be correctly folded into that
	 * sync's eventual seq capture once it does acquire - not left dangling as
	 * a false "still dirty" after a fully successful sync. This is what
	 * capturing seq_at_start *after* lock acquire (rather than before, or at
	 * the start of the whole call) guarantees.
	 */
	public function testSeqCapturedAfterAcquireIsNotSpuriouslyDirtiedByAWaitBeforeIt(): void {
		$voGroupId = 'test_seq_after_wait';
		$ncGroupId = 'uservo_test_seq_after_wait';
		$this->createTestGroupInDB($voGroupId, $ncGroupId, 'Test Seq After Wait Group');
		$this->groupManager->createGroup($ncGroupId);

		$lockService = new GroupSyncLockService($this->connection);
		// Pre-holder blocks the target sync from acquiring immediately, with a
		// short lease so it self-expires within the bounded wait.
		$preHolderToken = $lockService->tryAcquire($voGroupId, 1);
		$this->assertNotNull($preHolderToken);

		// A write lands while the target sync is queued waiting for the lease.
		$this->ledgerService->markDirty([$voGroupId]);

		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchAllGroups')->willReturn([
			['id' => $voGroupId, 'name' => 'Test Seq After Wait Group', 'parentid' => null, 'pos' => 1],
		]);
		$backend->method('fetchGroupMembers')->willReturn([]);

		$result = $this->service->syncSingleGroupById($voGroupId, $backend);
		$this->assertTrue($result['success'], $result['error'] ?? '');

		[$dirty, $clean] = $this->readSeqs($voGroupId);
		$this->assertSame($dirty, $clean, 'A mark that landed before the eventual acquire must be folded into the captured seq, not left dangling as a false dirty');
	}

	// --- deleted_in_vo skip rule: data-loss prevention ---
	//
	// Verified empirically against the real production VO API:
	// GetMembers(filter=gruppe=<a gone id>) returns a well-formed [], not an
	// error - indistinguishable, by itself, from "this group genuinely has
	// zero members right now". These tests guard the rule that prevents that
	// from silently wiping a group whose VO id has simply become stale.

	/**
	 * A live sync of a group already flagged deleted_in_vo must never call
	 * fetchGroupMembers() at all - if it did and trusted an errant []
	 * response, it would wipe the group's real membership.
	 */
	public function testLiveSyncOfAlreadyDeletedGroupNeverFetchesMembers(): void {
		$voGroupId = 'test_already_deleted';
		$ncGroupId = 'uservo_test_already_deleted';
		$uid = 'testuser_already_deleted_member';

		$ncGroup = $this->groupManager->createGroup($ncGroupId);
		$this->createTestGroupInDB($voGroupId, $ncGroupId, 'Test Already Deleted Group');
		$qb = $this->connection->getQueryBuilder();
		$qb->update('user_vo_groups')
			->set('deleted_in_vo', $qb->createNamedParameter(1, \PDO::PARAM_INT))
			->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter($voGroupId)))
			->executeStatement();

		if (!$this->userManager->userExists($uid)) {
			$this->userManager->createUser($uid, 'ATestPassword123!');
		}
		$ncGroup->addUser($this->userManager->get($uid));
		$this->assertTrue($this->isUserInNcGroup($uid, $ncGroupId), 'Precondition: user is a real, existing member');

		// The skip decision itself is derived purely from live absence from
		// fetchAllGroups()'s map ($groupDeletedInVO), not from this stored
		// flag - the pre-set deleted_in_vo=1 above is just the group's
		// starting state, matching what a prior live sync would already
		// have recorded. fetchGroupMembers() must never be called once this
		// group is (again) absent from a live listing.
		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchAllGroups')->willReturn([
			['id' => 'some_other_group', 'name' => 'Still There', 'parentid' => null, 'pos' => 1],
		]);
		$backend->expects($this->never())->method('fetchGroupMembers');

		$result = $this->service->syncSingleGroupById($voGroupId, $backend);
		$this->assertTrue($result['success'], $result['error'] ?? '');

		$this->assertTrue($this->isUserInNcGroup($uid, $ncGroupId), 'Membership must be left completely untouched');
		$this->userManager->get($uid)?->delete();
	}

	/**
	 * The login path never consults deleted_in_vo at all (neither reading
	 * nor writing it) - it always calls fetchGroupMembers(), and an empty
	 * result is then handled by the general empty-distrust rule, not by
	 * deletion detection. This is the structural fix for round 6's blocker:
	 * the login path's decision must not depend on cache freshness or flag
	 * timing at all.
	 */
	public function testLoginSyncOfAlreadyDeletedGroupStillCallsFetchGroupMembers(): void {
		$voGroupId = 'test_login_already_deleted';
		$ncGroupId = 'uservo_test_login_already_deleted';

		$this->groupManager->createGroup($ncGroupId);
		$this->createTestGroupInDB($voGroupId, $ncGroupId, 'Test Login Already Deleted Group');
		$qb = $this->connection->getQueryBuilder();
		$qb->update('user_vo_groups')
			->set('deleted_in_vo', $qb->createNamedParameter(1, \PDO::PARAM_INT))
			->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter($voGroupId)))
			->executeStatement();

		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchAllGroups')->willReturn([]);
		$backend->expects($this->once())->method('fetchGroupMembers')->willReturn([]);

		$result = $this->service->syncGroupsByIds([$voGroupId], $backend, nonBlocking: true);
		$this->assertTrue($result['success'], $result['error'] ?? '');

		$qb = $this->connection->getQueryBuilder();
		$row = $qb->select('deleted_in_vo')->from('user_vo_groups')
			->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter($voGroupId)))
			->executeQuery()->fetch();
		$this->assertEquals(1, $row['deleted_in_vo'], 'The login path must not clear the flag either - it never writes it at all');
	}

	/**
	 * Regression test for round 5's original blocker, restated for the final
	 * design: a live sync sets deleted_in_vo=1, then a login-triggered sync of
	 * the SAME group runs next. The flag must survive - a login sync has no
	 * basis to conclude the group came back (it never even reads the flag),
	 * so if the metadata write it performs unconditionally cleared it, the
	 * *next* sync of any kind would wrongly treat the group as no-longer-
	 * deleted and could wipe its membership via a live fetchGroupMembers()
	 * call returning the same empirically-confirmed [].
	 */
	public function testLoginSyncDoesNotClearAFlagALiveSyncSet(): void {
		$voGroupId = 'test_flag_survives_login';
		$ncGroupId = 'uservo_test_flag_survives_login';

		$this->groupManager->createGroup($ncGroupId);
		$this->createTestGroupInDB($voGroupId, $ncGroupId, 'Test Flag Survives Login Group');

		// Step 1: a live sync detects the group is gone from VO.
		$liveBackend = $this->createMock(UserVOAuth::class);
		$liveBackend->method('fetchAllGroups')->willReturn([
			['id' => 'some_other_group', 'name' => 'Still There', 'parentid' => null, 'pos' => 1],
		]);
		$result = $this->service->syncSingleGroupById($voGroupId, $liveBackend);
		$this->assertTrue($result['success'], $result['error'] ?? '');

		$qb = $this->connection->getQueryBuilder();
		$row = $qb->select('deleted_in_vo')->from('user_vo_groups')
			->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter($voGroupId)))
			->executeQuery()->fetch();
		$this->assertEquals(1, $row['deleted_in_vo'], 'Precondition: the live sync must have flagged it deleted');

		// Step 2: a login-triggered sync of the same group runs next.
		$loginBackend = $this->createMock(UserVOAuth::class);
		$loginBackend->method('fetchAllGroups')->willReturn([]);
		$loginBackend->method('fetchGroupMembers')->willReturn([]);
		$result = $this->service->syncGroupsByIds([$voGroupId], $loginBackend, nonBlocking: true);
		$this->assertTrue($result['success'], $result['error'] ?? '');

		$qb = $this->connection->getQueryBuilder();
		$row = $qb->select('deleted_in_vo')->from('user_vo_groups')
			->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter($voGroupId)))
			->executeQuery()->fetch();
		$this->assertEquals(1, $row['deleted_in_vo'], 'The flag must still be 1 after the login sync - it must not have been cleared');
	}

	/**
	 * A VO-restored group (present in a live listing again) must clear its
	 * stored deleted_in_vo flag and resume normal reconciliation, not stay
	 * skipped forever.
	 */
	public function testLiveSyncRestoresAGroupThatReappearsInVO(): void {
		$voGroupId = 'test_restore';
		$ncGroupId = 'uservo_test_restore';

		$this->groupManager->createGroup($ncGroupId);
		$this->createTestGroupInDB($voGroupId, $ncGroupId, 'Test Restore Group');
		$qb = $this->connection->getQueryBuilder();
		$qb->update('user_vo_groups')
			->set('deleted_in_vo', $qb->createNamedParameter(1, \PDO::PARAM_INT))
			->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter($voGroupId)))
			->executeStatement();

		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchAllGroups')->willReturn([
			['id' => $voGroupId, 'name' => 'Test Restore Group', 'parentid' => null, 'pos' => 1],
		]);
		$backend->method('fetchGroupMembers')->willReturn([]);

		$result = $this->service->syncSingleGroupById($voGroupId, $backend);
		$this->assertTrue($result['success'], $result['error'] ?? '');

		$qb = $this->connection->getQueryBuilder();
		$row = $qb->select('deleted_in_vo')->from('user_vo_groups')
			->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter($voGroupId)))
			->executeQuery()->fetch();
		$this->assertEquals(0, $row['deleted_in_vo'], 'A group present again in a live listing must have its flag cleared, not stay skipped forever');
	}

	/**
	 * Regression test: the login path must never restore (or otherwise
	 * touch) deleted_in_vo, even via the *normal* reconcile branch - not
	 * just the empty-result-distrust branch
	 * testLoginSyncDoesNotClearAFlagALiveSyncSet already covers. VO's
	 * GetMembers can still answer for a group that's absent from GetGroups
	 * (the two endpoints aren't guaranteed to agree, e.g. an archived group)
	 * - a non-empty fetchGroupMembers() result on the login path must not
	 * restore this flag either, since only a live-confirmed sync is
	 * authorized to conclude the group came back.
	 */
	public function testLoginSyncNeverRestoresDeletedInVoEvenViaTheNormalReconcileBranch(): void {
		$voGroupId = 'test_login_never_restores_flag';
		$ncGroupId = 'uservo_test_login_never_restores_flag';
		$this->groupManager->createGroup($ncGroupId);
		$this->createTestGroupInDB($voGroupId, $ncGroupId, 'Test Login Never Restores Flag Group');
		$qb = $this->connection->getQueryBuilder();
		$qb->update('user_vo_groups')
			->set('deleted_in_vo', $qb->createNamedParameter(1, \PDO::PARAM_INT))
			->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter($voGroupId)))
			->executeStatement();

		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchAllGroups')->willReturn([]); // Irrelevant on the login path.
		// Non-empty (matching nobody) so this takes the normal reconcile
		// path, not the empty-result-distrust branch.
		$backend->method('fetchGroupMembers')->willReturn([
			['id' => 'nonexistent_vo_id', 'name' => 'Nobody Here'],
		]);

		$result = $this->service->syncGroupsByIds([$voGroupId], $backend, nonBlocking: true);
		$this->assertTrue($result['success'], $result['error'] ?? '');

		$qb = $this->connection->getQueryBuilder();
		$row = $qb->select('deleted_in_vo')->from('user_vo_groups')
			->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter($voGroupId)))
			->executeQuery()->fetch();
		$this->assertEquals(1, $row['deleted_in_vo'], 'The login path must never restore this flag, even via the normal (non-empty-result) reconcile branch');
	}

	// --- mass-removal audit visibility ---

	public function testEmptyingAGroupIsLoggedAsMassRemoval(): void {
		$voGroupId = 'test_mass_removal';
		$ncGroupId = 'uservo_test_mass_removal';
		$uid = 'testuser_mass_removal';

		$this->createTestGroupInDB($voGroupId, $ncGroupId, 'Test Mass Removal Group');

		// A VO-backend member, via mocks - matches this file's established
		// pattern for exercising the removal branch (getBackendClassName()
		// gates it, which a plain database-backend test account can't satisfy).
		$mockUser = $this->createMock(\OCP\IUser::class);
		$mockUser->method('getUID')->willReturn($uid);
		$mockUser->method('getBackendClassName')->willReturn('OCA\\UserVO\\UserVOAuth');

		$removedUids = [];
		$mockGroup = $this->createMock(\OCP\IGroup::class);
		$mockGroup->method('getUsers')->willReturnOnConsecutiveCalls([$mockUser], []);
		$mockGroup->method('getDisplayName')->willReturn('Test Mass Removal Group');
		$mockGroup->method('removeUser')->willReturnCallback(function ($user) use (&$removedUids) {
			$removedUids[] = $user->getUID();
		});

		$mockGroupManager = $this->createMock(IGroupManager::class);
		$mockGroupManager->method('get')->willReturn($mockGroup);

		$mockUserManager = $this->createMock(IUserManager::class);
		$mockUserManager->method('get')->willReturnCallback(fn ($u) => $u === $uid ? $mockUser : null);

		$service = new GroupSyncService(
			$this->connection,
			$mockGroupManager,
			$mockUserManager,
			new GroupNameHarmonizer(),
			new GroupSyncLockService($this->connection),
			$this->ledgerService,
			\OC::$server->get(AuditLogService::class)
		);

		// Group genuinely still exists in VO (present in the live listing),
		// but currently has zero direct members.
		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchAllGroups')->willReturn([
			['id' => $voGroupId, 'name' => 'Test Mass Removal Group', 'parentid' => null, 'pos' => 1],
		]);
		$backend->method('fetchGroupMembers')->willReturn([]);

		$result = $service->syncSingleGroupById($voGroupId, $backend);
		$this->assertTrue($result['success'], $result['error'] ?? '');
		$this->assertEquals([$uid], $removedUids, 'The member must actually have been removed');

		$auditLog = \OC::$server->get(AuditLogService::class);
		$entries = $auditLog->getRecentEntries();
		$massRemovalEntry = current(array_filter(
			$entries,
			fn ($e) => $e['event_type'] === 'group_membership_mass_removed' && $e['group_id'] === $voGroupId
		));
		$this->assertNotFalse($massRemovalEntry, 'Emptying every VO-backend member must log a distinct mass-removal action, not the routine message');

		$routineEntry = current(array_filter(
			$entries,
			fn ($e) => $e['event_type'] === 'group_membership_changed' && $e['group_id'] === $voGroupId
		));
		$this->assertFalse($routineEntry, 'Must not ALSO log the routine message for the same change');
	}

	// --- reverse lookup: !duplicate exclusion, dedup ---

	/**
	 * A user_vo row marked !duplicate must never be resolved back to a VO
	 * member - same exclusion the DB-scan code being replaced already had.
	 */
	public function testDuplicateMarkedRowIsNeverAddedToAGroup(): void {
		$voGroupId = 'test_duplicate_excluded';
		$ncGroupId = 'uservo_test_duplicate_excluded';
		$duplicateUid = 'testuser_dup!duplicate';

		$this->groupManager->createGroup($ncGroupId);
		$this->createTestGroupInDB($voGroupId, $ncGroupId, 'Test Duplicate Excluded Group');

		$qb = $this->connection->getQueryBuilder();
		$qb->insert('user_vo')->values([
			'uid' => $qb->createNamedParameter($duplicateUid),
			'backend' => $qb->createNamedParameter('user_vo'),
			'vo_user_id' => $qb->createNamedParameter('vo_user_dup'),
		])->executeStatement();

		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchAllGroups')->willReturn([
			['id' => $voGroupId, 'name' => 'Test Duplicate Excluded Group', 'parentid' => null, 'pos' => 1],
		]);
		$backend->method('fetchGroupMembers')->willReturn([
			['id' => 'vo_user_dup', 'name' => 'Dup, Test'],
		]);

		$result = $this->service->syncSingleGroupById($voGroupId, $backend);
		$this->assertTrue($result['success'], $result['error'] ?? '');
		$this->assertEquals(0, $result['member_count'], 'The !duplicate-marked row must not have been added');

		$qb = $this->connection->getQueryBuilder();
		$qb->delete('user_vo')->where($qb->expr()->eq('uid', $qb->createNamedParameter($duplicateUid)))->executeStatement();
	}

	/**
	 * Regression test: PHP silently coerces an all-numeric array key back to
	 * an int. resolveUidsForVoUserIds() uses the uid as an array key purely
	 * for dedup - returning array_keys() directly would hand back an int
	 * for a uid like "41207" (e.g. a VO membership-number-derived
	 * username), not the string this method's contract promises. Under this
	 * file's declare(strict_types=1), that surfaces as a TypeError the
	 * first time a caller (IUserManager::get(string $uid)) receives it.
	 */
	public function testResolveUidsForVoUserIdsReturnsStringsEvenForAllNumericUids(): void {
		$numericUid = '41207';
		$qb = $this->connection->getQueryBuilder();
		$qb->insert('user_vo')->values([
			'uid' => $qb->createNamedParameter($numericUid),
			'backend' => $qb->createNamedParameter('user_vo'),
			'vo_user_id' => $qb->createNamedParameter('vo_numeric_test'),
		])->executeStatement();

		$ref = new \ReflectionMethod(GroupSyncService::class, 'resolveUidsForVoUserIds');
		$ref->setAccessible(true);
		$result = $ref->invoke($this->service, ['vo_numeric_test']);

		$this->assertSame(['41207'], $result, 'Must return a string uid, not an int PHP\'s array-key coercion produced');
		$this->assertIsString($result[0]);

		$qb = $this->connection->getQueryBuilder();
		$qb->delete('user_vo')->where($qb->expr()->eq('uid', $qb->createNamedParameter($numericUid)))->executeStatement();
	}

	// --- circuit breaker: starvation safety and exception discrimination ---

	/**
	 * A VoGroupDataUnusableException (a per-group data problem) must never
	 * trip the circuit breaker, no matter how many groups produce it in the
	 * same batch - the actual N>=2 starvation scenario round 4 found. Uses
	 * two such groups specifically, not one, since a threshold-based fix
	 * (rejected in favor of this exception-type discrimination) would have
	 * passed a single-group version of this test for the wrong reason.
	 */
	public function testSyncAllManagedGroupsNeverBreaksOnGroupDataProblems(): void {
		$this->cleanupTestData();
		$voIds = ['test_baddata_1', 'test_baddata_2', 'test_baddata_3'];
		foreach ($voIds as $voId) {
			$this->groupManager->createGroup('uservo_' . $voId);
			$this->createTestGroupInDB($voId, 'uservo_' . $voId, 'Test Bad Data ' . $voId);
		}

		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchAllGroups')->willReturn(array_map(
			fn ($voId) => ['id' => $voId, 'name' => 'Test Bad Data ' . $voId, 'parentid' => null, 'pos' => 1],
			$voIds
		));
		// Malformed for every group - if this counted toward the breaker,
		// two or more such groups would abort the batch early. Mocking a
		// throw directly (not a malformed return value) - a full method mock
		// replaces fetchGroupMembers()'s own body entirely, so it never runs
		// the real isWellFormedVOList() check that would normally produce
		// this exception; that check itself is covered directly in
		// tests/Unit/UserVOAuthTest.php.
		$backend->method('fetchGroupMembers')->willThrowException(
			new \OCA\UserVO\Service\Exception\VoGroupDataUnusableException('malformed response')
		);

		$result = $this->service->syncAllManagedGroups($backend);
		$this->assertTrue($result['success']);

		$testResults = array_filter($result['results'], fn ($r) => in_array($r['vo_group_id'], $voIds, true));
		$this->assertCount(3, $testResults, 'All three groups must have been attempted - none skipped due to an early breaker exit');
		foreach ($testResults as $r) {
			$this->assertEquals('error', $r['status']);
		}
	}

	/**
	 * The complementary case: a genuine transport-level failure
	 * (VoApiUnavailableException) DOES trip the breaker, after 2 consecutive
	 * occurrences - stopping the batch before burning a live API call on
	 * every remaining group during a real outage.
	 */
	public function testSyncAllManagedGroupsBreaksAfterTwoConsecutiveApiUnavailableFailures(): void {
		$this->cleanupTestData();
		$voIds = ['test_downapi_1', 'test_downapi_2', 'test_downapi_3'];
		foreach ($voIds as $voId) {
			$this->groupManager->createGroup('uservo_' . $voId);
			$this->createTestGroupInDB($voId, 'uservo_' . $voId, 'Test Down API ' . $voId);
		}

		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchAllGroups')->willReturn(array_map(
			fn ($voId) => ['id' => $voId, 'name' => 'Test Down API ' . $voId, 'parentid' => null, 'pos' => 1],
			$voIds
		));
		// Transport failure for every group.
		$backend->method('fetchGroupMembers')->willReturn(null);

		$result = $this->service->syncAllManagedGroups($backend);
		$this->assertTrue($result['success']);

		$testResults = array_filter($result['results'], fn ($r) => in_array($r['vo_group_id'], $voIds, true));
		$this->assertCount(2, $testResults, 'Must stop after the 2nd consecutive VO-unavailable failure, not attempt the 3rd group');
	}

	// --- resolveUidsForVoUserIds(): chunking ---

	/**
	 * array_chunk(..., 500) must not drop or duplicate ids at the chunk
	 * boundary itself - the specific off-by-one a naive chunk-size
	 * assumption could introduce. Raw query-builder inserts in a loop
	 * (rather than one bulk statement) match this file's existing style;
	 * 501 rows is small enough to stay fast.
	 */
	public function testResolveUidsForVoUserIdsChunksAcrossTheFiveHundredBoundary(): void {
		$voMemberIds = [];
		for ($i = 0; $i < 501; $i++) {
			$voMemberIds[] = "chunkvo_$i";
			$qb = $this->connection->getQueryBuilder();
			$qb->insert('user_vo')->values([
				'uid' => $qb->createNamedParameter("testuser_chunk_$i"),
				'backend' => $qb->createNamedParameter('user_vo'),
				'vo_user_id' => $qb->createNamedParameter("chunkvo_$i"),
			])->executeStatement();
		}

		$ref = new \ReflectionMethod(GroupSyncService::class, 'resolveUidsForVoUserIds');
		$ref->setAccessible(true);
		$result = $ref->invoke($this->service, $voMemberIds);

		$this->assertCount(501, $result, 'Every id across both chunks must resolve, including the ones straddling the 500-boundary');
		$this->assertContains('testuser_chunk_499', $result, 'Last id of the first chunk');
		$this->assertContains('testuser_chunk_500', $result, 'First (and only) id of the second chunk');
	}

	// --- structurally-empty VO groups: self-heal must not degrade to 24h ---

	/**
	 * A managed group that's structurally empty in VO (fetchGroupMembers()
	 * legitimately returns []) must still get explicitly re-dirtied by a
	 * login-triggered sync, not left however clean it already was -
	 * dirty-marking is otherwise driven exclusively by
	 * UserVOAuth::updateVOMetadata()'s own VO-side diff, a signal that never
	 * fires for this group if nothing about its VO-side membership changed.
	 * Without this explicit call, an NC-side-only edit (the self-heal case
	 * that diff exists to protect) would degrade from the sweep's
	 * <=5-minute cadence to the nightly sync's <=24h one.
	 */
	public function testLoginSyncOfAStructurallyEmptyGroupExplicitlyRedirtiesForTheSweep(): void {
		$voGroupId = 'test_structurally_empty';
		$ncGroupId = 'uservo_test_structurally_empty';
		$this->groupManager->createGroup($ncGroupId);
		$this->createTestGroupInDB($voGroupId, $ncGroupId, 'Test Structurally Empty Group');

		[$dirty0, $clean0] = $this->readSeqs($voGroupId);
		$this->assertSame($dirty0, $clean0, 'Precondition: a freshly-created group starts clean');

		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchAllGroups')->willReturn([
			['id' => $voGroupId, 'name' => 'Test Structurally Empty Group', 'parentid' => null, 'pos' => 1],
		]);
		$backend->method('fetchGroupMembers')->willReturn([]);

		$result = $this->service->syncGroupsByIds([$voGroupId], $backend, nonBlocking: true);
		$this->assertTrue($result['success'], $result['error'] ?? '');

		[$dirtyAfter, $cleanAfter] = $this->readSeqs($voGroupId);
		$this->assertGreaterThan($cleanAfter, $dirtyAfter, 'The login path must explicitly re-dirty a group whose empty membership result it distrusts, not leave it however clean it already was');
	}

	// --- $mayAdvanceClean: the login path's lease-reassignment self-heal ---

	/**
	 * The lease-reassignment self-heal inside markCleanIfStillOwned() (mark
	 * dirty + warn when the held token no longer matches) must still fire on
	 * the login (mayAdvanceClean: false) path, not just the blocking path
	 * covered by testSyncWhoseLeaseExpiredMidBodyDoesNotClaimClean -
	 * mayAdvanceClean only skips the *optimistic* clean_seq UPDATE, the
	 * mismatch fallthrough itself is shared, unconditional code. Exercised
	 * end-to-end via the real nonBlocking entry point rather than assuming
	 * that sharing holds.
	 *
	 * fetchGroupMembers() is mocked non-empty (matching nobody) rather than
	 * [] specifically so this takes the normal reconcile path (which calls
	 * IGroup::getUsers() - the lease-reassignment hook - before
	 * markCleanIfStillOwned() runs), not the login path's empty-result-skip
	 * branch, whose only getUsers() call happens after.
	 */
	public function testNonBlockingSyncWithReassignedLeaseStillRedirtiesForTheSweep(): void {
		$voGroupId = 'test_nb_lease_reassigned';
		$ncGroupId = 'uservo_test_nb_lease_reassigned';
		$this->createTestGroupInDB($voGroupId, $ncGroupId, 'Test NB Lease Reassigned Group');
		$this->groupManager->createGroup($ncGroupId);

		$lockService = new GroupSyncLockService($this->connection);

		$mockGroup = $this->createMock(\OCP\IGroup::class);
		$mockGroup->method('getUsers')->willReturnCallback(function () use ($voGroupId, $lockService) {
			$past = (new \DateTime())->modify('-1 second');
			$qb = $this->connection->getQueryBuilder();
			$qb->update('user_vo_groups')
				->set('sync_lock_until', $qb->createNamedParameter($past, 'datetime'))
				->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter($voGroupId)))
				->executeStatement();
			$otherToken = $lockService->tryAcquire($voGroupId, 60);
			$this->assertNotNull($otherToken, 'Second worker should acquire once the first lease is forced to expire');
			return [];
		});

		$mockGroupManager = $this->createMock(IGroupManager::class);
		$mockGroupManager->method('get')->willReturn($mockGroup);

		$service = new GroupSyncService(
			$this->connection,
			$mockGroupManager,
			$this->userManager,
			new GroupNameHarmonizer(),
			$lockService,
			$this->ledgerService,
			\OC::$server->get(AuditLogService::class)
		);

		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchAllGroups')->willReturn([
			['id' => $voGroupId, 'name' => 'Test NB Lease Reassigned Group', 'parentid' => null, 'pos' => 1],
		]);
		// Non-empty (matching nobody) so this takes the normal path - see
		// docblock above.
		$backend->method('fetchGroupMembers')->willReturn([
			['id' => 'nonexistent_vo_id', 'name' => 'Nobody Here'],
		]);

		$result = $service->syncGroupsByIds([$voGroupId], $backend, nonBlocking: true);
		$this->assertTrue($result['success'], $result['error'] ?? '');

		[$dirty, $clean] = $this->readSeqs($voGroupId);
		$this->assertGreaterThan($clean, $dirty, 'A login-triggered sync whose lease was reassigned mid-body must not claim clean either');
	}

	// --- vo_group_size: populated by a live fetch, untouched by the skip rule ---

	public function testVoGroupSizeIsPopulatedFromTheLiveFetchedMemberCount(): void {
		$voGroupId = 'test_vgs_populated';
		$ncGroupId = 'uservo_test_vgs_populated';
		$this->groupManager->createGroup($ncGroupId);
		$this->createTestGroupInDB($voGroupId, $ncGroupId, 'Test VGS Populated Group');

		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchAllGroups')->willReturn([
			['id' => $voGroupId, 'name' => 'Test VGS Populated Group', 'parentid' => null, 'pos' => 1],
		]);
		// VO's own reported membership - three, even though none resolve to
		// an NC uid - vo_group_size reflects VO's count, not NC's.
		$backend->method('fetchGroupMembers')->willReturn([
			['id' => 'vgs_1', 'name' => 'One'],
			['id' => 'vgs_2', 'name' => 'Two'],
			['id' => 'vgs_3', 'name' => 'Three'],
		]);

		$result = $this->service->syncSingleGroupById($voGroupId, $backend);
		$this->assertTrue($result['success'], $result['error'] ?? '');

		$qb = $this->connection->getQueryBuilder();
		$row = $qb->select('vo_group_size')->from('user_vo_groups')
			->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter($voGroupId)))
			->executeQuery()->fetch();
		$this->assertEquals(3, (int)$row['vo_group_size'], 'vo_group_size must reflect VO\'s own reported count, independent of how many of those ids resolve to NC uids');
	}

	/**
	 * Complementary case: a group skipped via the deleted_in_vo rule (see
	 * the "deleted_in_vo skip rule" section above) must leave a
	 * previously-recorded vo_group_size untouched, not zero it out - the
	 * skip branch never calls fetchGroupMembers() at all, so it has no new
	 * count to report.
	 */
	public function testVoGroupSizeIsUntouchedWhenTheDeletedInVoSkipRuleApplies(): void {
		$voGroupId = 'test_vgs_skip_untouched';
		$ncGroupId = 'uservo_test_vgs_skip_untouched';
		$this->groupManager->createGroup($ncGroupId);
		$this->createTestGroupInDB($voGroupId, $ncGroupId, 'Test VGS Skip Untouched Group');

		$qb = $this->connection->getQueryBuilder();
		$qb->update('user_vo_groups')
			->set('deleted_in_vo', $qb->createNamedParameter(1, \PDO::PARAM_INT))
			->set('vo_group_size', $qb->createNamedParameter(42, \PDO::PARAM_INT))
			->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter($voGroupId)))
			->executeStatement();

		// Non-empty and absent this group - an empty listing is itself
		// treated as an API failure elsewhere in this service, unrelated to
		// what this test is checking.
		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchAllGroups')->willReturn([
			['id' => 'some_other_group', 'name' => 'Still There', 'parentid' => null, 'pos' => 1],
		]);
		$backend->expects($this->never())->method('fetchGroupMembers');

		$result = $this->service->syncSingleGroupById($voGroupId, $backend);
		$this->assertTrue($result['success'], $result['error'] ?? '');

		$qb = $this->connection->getQueryBuilder();
		$row = $qb->select('vo_group_size')->from('user_vo_groups')
			->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter($voGroupId)))
			->executeQuery()->fetch();
		$this->assertEquals(42, (int)$row['vo_group_size'], 'A skipped (deleted_in_vo) group must keep its last-known vo_group_size, not have it cleared');
	}
}
