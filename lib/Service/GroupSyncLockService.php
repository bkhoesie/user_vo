<?php
declare(strict_types=1);

namespace OCA\UserVO\Service;

use OCP\IDBConnection;

/**
 * Per-group DB-backed sync lease, serializing concurrent syncs of the same
 * VO-managed group (see Version1004Date20260802000000 migration for why).
 *
 * Acquire is a single atomic conditional UPDATE (same family as Nextcloud
 * core's own JobList row reservation) - portable across all supported DBs,
 * works with zero memcache configured, and closes the web-vs-CLI visibility
 * gap a plain APCu lock can't. Callers must always release in a `finally`
 * block; a lease that's never released still self-expires after its TTL,
 * so a crashed/killed worker can't wedge a group's sync forever.
 *
 * Assumes the group's row in user_vo_groups already exists - true for every
 * current caller, which all look the group up from that table first.
 */
class GroupSyncLockService {
    private IDBConnection $connection;

    public function __construct(IDBConnection $connection) {
        $this->connection = $connection;
    }

    /**
     * Attempts to acquire the lease for a group, once, without waiting.
     *
     * @param int $leaseSeconds How long the lease is held before it self-expires.
     * @return bool True if acquired.
     */
    public function tryAcquire(string $voGroupId, int $leaseSeconds = 60): bool {
        $now = new \DateTime();
        $expiresAt = (clone $now)->modify("+{$leaseSeconds} seconds");

        $qb = $this->connection->getQueryBuilder();
        $qb->update('user_vo_groups')
            ->set('sync_lock_until', $qb->createNamedParameter($expiresAt, 'datetime'))
            ->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter($voGroupId)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('sync_lock_until'),
                $qb->expr()->lt('sync_lock_until', $qb->createNamedParameter($now, 'datetime'))
            ));

        return $qb->executeStatement() > 0;
    }

    /**
     * Repeatedly attempts to acquire the lease, polling until it succeeds or
     * $maxWaitSeconds elapses.
     *
     * @return bool True if acquired within the wait window.
     */
    public function acquireWithBoundedWait(string $voGroupId, float $maxWaitSeconds, int $leaseSeconds = 60): bool {
        $deadline = microtime(true) + $maxWaitSeconds;
        do {
            if ($this->tryAcquire($voGroupId, $leaseSeconds)) {
                return true;
            }
            usleep(250_000);
        } while (microtime(true) < $deadline);

        return false;
    }

    /**
     * Releases the lease. Safe to call even if the caller never held it
     * (e.g. a non-blocking tryAcquire() that failed) - it's a no-op update.
     */
    public function release(string $voGroupId): void {
        $qb = $this->connection->getQueryBuilder();
        $qb->update('user_vo_groups')
            ->set('sync_lock_until', $qb->createNamedParameter(null))
            ->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter($voGroupId)));
        $qb->executeStatement();
    }
}
