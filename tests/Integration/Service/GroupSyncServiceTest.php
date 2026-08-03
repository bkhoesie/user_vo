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
		$testGroups = ['uservo_test_123', 'uservo_test_456', 'uservo_test_556', 'uservo_test_789', 'uservo_test_lockrace', 'uservo_test_contended', 'uservo_test_deleted_midsync', 'uservo_test_bulk_locked', 'uservo_test_bulk_free', 'uservo_test_nonblocking_missing', 'uservo_test_nonblocking_api_down', 'uservo_test_concurrent_write', 'uservo_test_no_concurrent_write', 'uservo_test_contended_ledger', 'uservo_test_throws_adduser', 'uservo_test_lease_expire_mid', 'uservo_test_seq_after_wait'];
		foreach ($testGroups as $groupId) {
			if ($this->groupManager->groupExists($groupId)) {
				$group = $this->groupManager->get($groupId);
				if ($group) {
					$group->delete();
				}
			}
		}

		// Delete test users
		$testUsers = ['testuser1', 'testuser2', 'testuser3', 'testuser_lockrace', 'testuser_nonblocking_api_down', 'testuser_concurrent_write', 'testuser_throw_a', 'testuser_throw_b'];
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
			'vo_group_ids' => $qb->createNamedParameter($voGroupId),
		])->executeStatement();

		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchAllGroups')->willReturn(null);

		$result = $this->service->syncGroupsByIds([$voGroupId], $backend, nonBlocking: true);

		$this->assertTrue($result['success'], 'Membership sync must still succeed despite the metadata fetch failure');
		$this->assertEquals(1, $result['synced']);
		$this->assertContains($uid, $result['results'][0]['added']);

		$ncGroup = $this->groupManager->get($ncGroupId);
		$members = array_map(fn ($u) => $u->getUID(), $ncGroup->getUsers());
		$this->assertContains($uid, $members, 'User should actually be added to the NC group');

		$this->userManager->get($uid)?->delete();
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
			$lockToken = $lockService->tryAcquire($voGroupId);
			$this->assertNotNull($lockToken);

			$result = $this->service->syncGroupsByIds([$voGroupId], $backend, nonBlocking: true);
			$this->assertTrue($result['success']);
			$this->assertEquals(1, $result['skipped'], 'A lock-contended sync must be counted as skipped, not synced');
			$this->assertEquals(0, $result['synced']);
			$this->assertEquals('skipped', $result['results'][0]['status'], 'Must be reported distinctly from a real success - not indistinguishable from an empty sync');

			$ncGroup = $this->groupManager->get($ncGroupId);
			$members = array_map(fn ($u) => $u->getUID(), $ncGroup->getUsers());
			$this->assertNotContains($uid, $members, 'User must NOT have been added while the group was locked');
		} finally {
			$lockService->release($voGroupId, $lockToken);
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
	 * Headline regression test for B1: syncSingleGroupFullLocked() reads
	 * user_vo *before* calling IGroup::getUsers() and mutating NC membership.
	 * If a user's own metadata write lands in that window, this sync's own
	 * snapshot is already stale by the time it mutates - previously there was
	 * no way to detect that, and the write could be silently lost until the
	 * next full sync. The ledger must catch it: the group must end dirty, not
	 * falsely clean, so the sweep repairs it.
	 *
	 * PHPUnit can't run genuinely concurrent syncs (same caveat as
	 * testNonBlockingSyncNeverMutatesMembershipWhileGroupIsLocked above), so
	 * this drives the actual race window directly via a mocked IGroup::getUsers()
	 * callback - called at exactly the point a concurrent write would land -
	 * which performs the write's real-world effect (updating user_vo and
	 * marking the group dirty, same as UserVOAuth::updateVOMetadata() will
	 * once wired up) before returning control to the sync.
	 */
	public function testConcurrentUserWriteDuringSyncLeavesGroupDirtyRatherThanFalselyClean(): void {
		$voGroupId = 'test_concurrent_write';
		$ncGroupId = 'uservo_test_concurrent_write';
		$uid = 'testuser_concurrent_write';

		$this->createTestGroupInDB($voGroupId, $ncGroupId, 'Test Concurrent Write Group');
		if (!$this->userManager->userExists($uid)) {
			$this->userManager->createUser($uid, 'ATestPassword123!');
		}
		// Not (yet) a member as far as this sync's own read of user_vo is
		// concerned - the concurrent write below adds it, but only after
		// that read has already happened.
		$qb = $this->connection->getQueryBuilder();
		$qb->insert('user_vo')->values([
			'uid' => $qb->createNamedParameter($uid),
			'backend' => $qb->createNamedParameter('user_vo'),
			'vo_group_ids' => $qb->createNamedParameter(''),
		])->executeStatement();

		$mockGroup = $this->createMock(\OCP\IGroup::class);
		$mockGroup->method('getUsers')->willReturnCallback(function () use ($uid, $voGroupId) {
			$updateQb = $this->connection->getQueryBuilder();
			$updateQb->update('user_vo')
				->set('vo_group_ids', $updateQb->createNamedParameter($voGroupId))
				->where($updateQb->expr()->eq('uid', $updateQb->createNamedParameter($uid)))
				->executeStatement();
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

		$result = $service->syncSingleGroupById($voGroupId, $backend);
		$this->assertTrue($result['success'], $result['error'] ?? '');

		[$dirty, $clean] = $this->readSeqs($voGroupId);
		$this->assertGreaterThan($clean, $dirty, 'A write landing during the sync window must leave the group dirty, not falsely clean');
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
		foreach ([$uidA, $uidB] as $uid) {
			if (!$this->userManager->userExists($uid)) {
				$this->userManager->createUser($uid, 'ATestPassword123!');
			}
			$qb = $this->connection->getQueryBuilder();
			$qb->insert('user_vo')->values([
				'uid' => $qb->createNamedParameter($uid),
				'backend' => $qb->createNamedParameter('user_vo'),
				'vo_group_ids' => $qb->createNamedParameter($voGroupId),
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

		$result = $this->service->syncSingleGroupById($voGroupId, $backend);
		$this->assertTrue($result['success'], $result['error'] ?? '');

		[$dirty, $clean] = $this->readSeqs($voGroupId);
		$this->assertSame($dirty, $clean, 'A mark that landed before the eventual acquire must be folded into the captured seq, not left dangling as a false dirty');
	}
}
