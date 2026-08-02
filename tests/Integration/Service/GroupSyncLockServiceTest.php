<?php
namespace OCA\UserVO\Tests\Integration\Service;

use OCA\UserVO\Service\GroupSyncLockService;
use OCP\IDBConnection;
use Test\TestCase;

/**
 * Integration tests for GroupSyncLockService's DB-backed lease mechanism -
 * acquire, contend, expire, release, and the fencing token that protects
 * release() from clearing a lease it no longer owns - against a real
 * database.
 *
 * @group DB
 */
class GroupSyncLockServiceTest extends TestCase {
	private const VO_GROUP_ID = 'test_lock_group';

	private GroupSyncLockService $service;
	private IDBConnection $connection;

	protected function setUp(): void {
		parent::setUp();
		$this->connection = \OC::$server->get(IDBConnection::class);
		$this->service = new GroupSyncLockService($this->connection);
		$this->cleanupTestData();
		$this->insertTestGroupRow();
	}

	protected function tearDown(): void {
		$this->cleanupTestData();
		parent::tearDown();
	}

	private function cleanupTestData(): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->delete('user_vo_groups')
			->where($qb->expr()->like('vo_group_id', $qb->createNamedParameter('test_lock_%')))
			->executeStatement();
	}

	private function insertTestGroupRow(): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->insert('user_vo_groups')
			->values([
				'vo_group_id' => $qb->createNamedParameter(self::VO_GROUP_ID),
				'vo_group_name' => $qb->createNamedParameter('Test Lock Group'),
				'nc_group_id' => $qb->createNamedParameter('uservo_test_lock_group'),
				'deleted_in_vo' => $qb->createNamedParameter(0, \PDO::PARAM_INT),
			])
			->executeStatement();
	}

	private function readLockUntil(): ?string {
		$qb = $this->connection->getQueryBuilder();
		$row = $qb->select('sync_lock_until')
			->from('user_vo_groups')
			->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter(self::VO_GROUP_ID)))
			->executeQuery()->fetch();
		return $row['sync_lock_until'] ?? null;
	}

	public function testAcquireSucceedsOnUnlockedGroup(): void {
		$token = $this->service->tryAcquire(self::VO_GROUP_ID);
		$this->assertNotNull($token);
		$this->assertNotNull($this->readLockUntil(), 'Lease expiry should be recorded on acquire');
	}

	public function testSecondAcquireFailsWhileLocked(): void {
		$this->assertNotNull($this->service->tryAcquire(self::VO_GROUP_ID, 60));
		$this->assertNull($this->service->tryAcquire(self::VO_GROUP_ID, 60), 'A held, unexpired lease must reject a second acquire');
	}

	public function testAcquireSucceedsAfterRelease(): void {
		$token = $this->service->tryAcquire(self::VO_GROUP_ID);
		$this->assertNotNull($token);
		$this->service->release(self::VO_GROUP_ID, $token);
		$this->assertNotNull($this->service->tryAcquire(self::VO_GROUP_ID), 'Acquire should succeed again once released');
	}

	public function testReleaseIsSafeWhenNeverAcquired(): void {
		// Must not throw - a non-blocking tryAcquire() that failed still calls
		// release() unconditionally in its caller's finally block. An empty
		// token can never match a real one, so this is a guaranteed no-op.
		$this->service->release(self::VO_GROUP_ID, 'not-a-real-token');
		$this->assertNotNull($this->service->tryAcquire(self::VO_GROUP_ID));
	}

	public function testStaleReleaseDoesNotStealANewlyAcquiredLease(): void {
		// Worker A acquires, then (simulating its work outliving the lease)
		// the lease expires and worker B legitimately acquires next.
		$tokenA = $this->service->tryAcquire(self::VO_GROUP_ID, 2);
		$this->assertNotNull($tokenA);

		$past = (new \DateTime())->modify('-1 second');
		$qb = $this->connection->getQueryBuilder();
		$qb->update('user_vo_groups')
			->set('sync_lock_until', $qb->createNamedParameter($past, 'datetime'))
			->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter(self::VO_GROUP_ID)))
			->executeStatement();

		$tokenB = $this->service->tryAcquire(self::VO_GROUP_ID, 60);
		$this->assertNotNull($tokenB, 'Worker B should acquire once A\'s lease has expired');
		$this->assertNotEquals($tokenA, $tokenB);

		// Worker A finally finishes and releases using its own (now stale) token.
		$this->service->release(self::VO_GROUP_ID, $tokenA);

		// B's lease must still be held - A's stale release must not have cleared it.
		$this->assertNull(
			$this->service->tryAcquire(self::VO_GROUP_ID, 60),
			"A's stale release() must not clear B's still-active lease"
		);

		// B's own release, with the correct token, does work.
		$this->service->release(self::VO_GROUP_ID, $tokenB);
		$this->assertNotNull($this->service->tryAcquire(self::VO_GROUP_ID), 'B\'s own release should free the lease');
	}

	public function testAcquireSucceedsOnceAnExpiredLeaseIsPresent(): void {
		// Simulates a crashed worker that never released: write an
		// already-past expiry directly, bypassing tryAcquire()'s own clock.
		$past = (new \DateTime())->modify('-5 seconds');
		$qb = $this->connection->getQueryBuilder();
		$qb->update('user_vo_groups')
			->set('sync_lock_until', $qb->createNamedParameter($past, 'datetime'))
			->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter(self::VO_GROUP_ID)))
			->executeStatement();

		$this->assertNotNull($this->service->tryAcquire(self::VO_GROUP_ID), 'An expired lease must not block a new acquire');
	}

	public function testAcquireWithBoundedWaitSucceedsImmediatelyWhenUnlocked(): void {
		$start = microtime(true);
		$this->assertNotNull($this->service->acquireWithBoundedWait(self::VO_GROUP_ID, 2.0));
		$this->assertLessThan(0.5, microtime(true) - $start, 'Should not wait at all when the lease is free');
	}

	public function testAcquireWithBoundedWaitFailsAfterTimeoutWhileLocked(): void {
		$this->assertNotNull($this->service->tryAcquire(self::VO_GROUP_ID, 60));

		$start = microtime(true);
		$this->assertNull($this->service->acquireWithBoundedWait(self::VO_GROUP_ID, 0.6));
		$this->assertGreaterThanOrEqual(0.5, microtime(true) - $start, 'Should have actually waited close to the bound before giving up');
	}

	public function testAcquireWithBoundedWaitSucceedsOnceShortLeaseSelfExpiresDuringWait(): void {
		// sync_lock_until is a DATETIME column (whole-second precision), so a
		// 1-2s margin is needed between lease and wait to avoid truncation
		// noise - not relevant at the real default (300s lease, ~3s wait).
		$this->assertNotNull($this->service->tryAcquire(self::VO_GROUP_ID, 2));

		$this->assertNotNull(
			$this->service->acquireWithBoundedWait(self::VO_GROUP_ID, 4.0),
			'A short-lived lease should self-expire and let a bounded wait succeed within the window'
		);
	}
}
