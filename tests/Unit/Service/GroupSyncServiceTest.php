<?php
namespace OCA\UserVO\Tests\Unit\Service;

use OCA\UserVO\Service\GroupSyncService;
use OCA\UserVO\Service\GroupNameHarmonizer;
use OCA\UserVO\Service\GroupSyncLockService;
use OCA\UserVO\UserVOAuth;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\IResult;
use PHPUnit\Framework\TestCase;

class GroupSyncServiceTest extends TestCase {
	private $connection;
	private $groupManager;
	private $userManager;
	private $harmonizer;
	private $lockService;
	private $service;

	protected function setUp(): void {
		$this->connection = $this->createMock(IDBConnection::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->harmonizer = $this->createMock(GroupNameHarmonizer::class);
		$this->lockService = $this->createMock(GroupSyncLockService::class);
		// Unit tests exercise sync logic, not lock contention - default to
		// "lock always available" so existing behavior is unaffected.
		$this->lockService->method('tryAcquire')->willReturn('test-token');
		$this->lockService->method('acquireWithBoundedWait')->willReturn('test-token');

		$this->service = new GroupSyncService(
			$this->connection,
			$this->groupManager,
			$this->userManager,
			$this->harmonizer,
			$this->lockService
		);
	}

	public function testSyncSingleGroupByIdValidatesGroupId() {
		$backend = $this->createMock(UserVOAuth::class);
		$result = $this->service->syncSingleGroupById('', $backend);

		$this->assertFalse($result['success']);
		$this->assertEquals('VO group ID is required', $result['error']);
		$this->assertEquals(400, $result['status_code']);
	}

	public function testSyncSingleGroupByIdReturns404ForUnmanagedGroup() {
		$backend = $this->createMock(UserVOAuth::class);

		// Mock query builder that returns no rows
		$qb = $this->createMock(IQueryBuilder::class);
		$queryResult = $this->createMock(IResult::class);
		$queryResult->method('fetch')->willReturn(false);
		$queryResult->expects($this->once())->method('closeCursor');

		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('expr')->willReturn($this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class));
		$qb->method('createNamedParameter')->willReturnArgument(0);
		$qb->method('executeQuery')->willReturn($queryResult);

		$this->connection->method('getQueryBuilder')->willReturn($qb);

		$result = $this->service->syncSingleGroupById('12345', $backend);

		$this->assertFalse($result['success']);
		$this->assertEquals('Group is not managed', $result['error']);
		$this->assertEquals(404, $result['status_code']);
	}

	public function testSyncSingleGroupByIdReturns500OnAPIFailure() {
		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchAllGroups')->willReturn(null);

		// Mock database query that returns group data
		$qb = $this->createMock(IQueryBuilder::class);
		$queryResult = $this->createMock(IResult::class);
		$queryResult->method('fetch')->willReturn([
			'nc_group_id' => 'uservo_123',
			'vo_group_name' => 'Test Group',
			'vo_parent_id' => null,
			'vo_position' => 1
		]);
		$queryResult->expects($this->once())->method('closeCursor');

		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('expr')->willReturn($this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class));
		$qb->method('createNamedParameter')->willReturnArgument(0);
		$qb->method('executeQuery')->willReturn($queryResult);

		$this->connection->method('getQueryBuilder')->willReturn($qb);

		$result = $this->service->syncSingleGroupById('123', $backend);

		$this->assertFalse($result['success']);
		$this->assertEquals('Failed to fetch groups from VereinOnline', $result['error']);
		$this->assertEquals(500, $result['status_code']);
	}

	public function testSyncAllManagedGroupsReturnsEmptyForNoGroups() {
		$backend = $this->createMock(UserVOAuth::class);

		// Mock query builder that returns empty array
		$qb = $this->createMock(IQueryBuilder::class);
		$queryResult = $this->createMock(IResult::class);
		$queryResult->method('fetchAll')->willReturn([]);
		$queryResult->expects($this->once())->method('closeCursor');

		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('orderBy')->willReturnSelf();
		$qb->method('executeQuery')->willReturn($queryResult);

		$this->connection->method('getQueryBuilder')->willReturn($qb);

		$result = $this->service->syncAllManagedGroups($backend);

		$this->assertTrue($result['success']);
		$this->assertEquals('No managed groups to sync', $result['message']);
		$this->assertEquals(0, $result['summary']['total']);
		$this->assertEquals(0, $result['summary']['succeeded']);
		$this->assertEquals(0, $result['summary']['failed']);
		$this->assertEmpty($result['results']);
	}

	public function testSyncAllManagedGroupsHandlesAPIFailure() {
		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchAllGroups')->willReturn(null);

		// Mock query builder that returns groups
		$qb = $this->createMock(IQueryBuilder::class);
		$queryResult = $this->createMock(IResult::class);
		$queryResult->method('fetchAll')->willReturn([
			['vo_group_id' => '123', 'vo_group_name' => 'Group 1', 'nc_group_id' => 'uservo_123']
		]);
		$queryResult->expects($this->once())->method('closeCursor');

		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('orderBy')->willReturnSelf();
		$qb->method('executeQuery')->willReturn($queryResult);

		$this->connection->method('getQueryBuilder')->willReturn($qb);

		$result = $this->service->syncAllManagedGroups($backend);

		$this->assertFalse($result['success']);
		$this->assertEquals('Failed to fetch groups from VereinOnline', $result['error']);
		$this->assertEquals(500, $result['status_code']);
	}

	public function testSyncGroupsByIdsReturnsEmptyForEmptyInput() {
		$backend = $this->createMock(UserVOAuth::class);
		$result = $this->service->syncGroupsByIds([], $backend);

		$this->assertTrue($result['success']);
		$this->assertEquals(0, $result['synced']);
		$this->assertEquals(0, $result['failed']);
		$this->assertEmpty($result['results']);
	}

	public function testSyncGroupsByIdsReturnsEmptyWhenNoManagedGroups() {
		// Mock query builder that finds no managed groups
		$qb = $this->createMock(IQueryBuilder::class);
		$queryResult = $this->createMock(IResult::class);
		$queryResult->method('fetchAll')->willReturn([]);
		$queryResult->expects($this->once())->method('closeCursor');

		$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnArgument(0);
		$qb->method('executeQuery')->willReturn($queryResult);

		$this->connection->method('getQueryBuilder')->willReturn($qb);

		$backend = $this->createMock(UserVOAuth::class);
		$result = $this->service->syncGroupsByIds(['123', '456'], $backend);

		$this->assertTrue($result['success']);
		$this->assertEquals(0, $result['synced']);
		$this->assertEquals(0, $result['failed']);
		$this->assertEmpty($result['results']);
	}

	/**
	 * A group skipped due to lock contention (non-blocking mode) must be
	 * counted and reported distinctly from an actual successful sync - not
	 * indistinguishable "success" with fabricated empty results. Otherwise a
	 * perpetually-contended group's login-time syncs would look healthy in
	 * logs/summaries while never actually running.
	 */
	public function testSyncGroupsByIdsReportsSkippedSeparatelyFromSynced() {
		$qb = $this->createMock(IQueryBuilder::class);
		$queryResult = $this->createMock(IResult::class);
		$queryResult->method('fetchAll')->willReturn([
			['vo_group_id' => '123', 'vo_group_name' => 'Group 123', 'nc_group_id' => 'uservo_123'],
		]);

		$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnArgument(0);
		$qb->method('executeQuery')->willReturn($queryResult);

		$this->connection->method('getQueryBuilder')->willReturn($qb);

		$backend = $this->createMock(UserVOAuth::class);
		$backend->method('fetchAllGroups')->willReturn([
			['id' => '123', 'name' => 'Group 123', 'parentid' => null, 'pos' => 1]
		]);

		// Deny lock acquisition entirely, forcing every group down the skip path.
		$lockService = $this->createMock(GroupSyncLockService::class);
		$lockService->method('tryAcquire')->willReturn(null);
		$lockService->method('acquireWithBoundedWait')->willReturn(null);
		$service = new GroupSyncService($this->connection, $this->groupManager, $this->userManager, $this->harmonizer, $lockService);

		$result = $service->syncGroupsByIds(['123'], $backend, nonBlocking: true);

		$this->assertTrue($result['success']);
		$this->assertEquals(0, $result['synced']);
		$this->assertEquals(1, $result['skipped']);
		$this->assertEquals(0, $result['failed']);
		$this->assertEquals('skipped', $result['results'][0]['status']);
	}
}
