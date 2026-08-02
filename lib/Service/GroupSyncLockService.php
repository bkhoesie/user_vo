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
 * Fenced with a per-acquire random token: release() only clears the lease if
 * the token still matches. Without this, a holder whose work outlives the
 * TTL would clear whatever lease is present when it finally releases - even
 * one a *different* worker legitimately acquired in the meantime after the
 * first one expired - letting a third worker in immediately after. With
 * fencing, that stale release is a safe no-op instead: mutual exclusion
 * degrades to "the overrunning holder and the next legitimate one briefly
 * overlap" rather than "a third worker gets let in on top of both".
 *
 * The default lease is deliberately generous (see LEASE_SECONDS) rather than
 * tight, since a too-short lease is what creates the overrun scenario above
 * in the first place - there's no VO network I/O under the lock, but a
 * large group's auto-sync-after-creation can still do one NC group-API call
 * per member.
 *
 * Assumes the group's row in user_vo_groups already exists - true for every
 * current caller, which all look the group up from that table first.
 */
class GroupSyncLockService {
    private const LEASE_SECONDS = 300;

    private IDBConnection $connection;

    public function __construct(IDBConnection $connection) {
        $this->connection = $connection;
    }

    /**
     * Attempts to acquire the lease for a group, once, without waiting.
     *
     * @param int $leaseSeconds How long the lease is held before it self-expires.
     * @return string|null The fencing token to pass to release() if acquired, else null.
     */
    public function tryAcquire(string $voGroupId, int $leaseSeconds = self::LEASE_SECONDS): ?string {
        $now = new \DateTime();
        $expiresAt = (clone $now)->modify("+{$leaseSeconds} seconds");
        $token = bin2hex(random_bytes(16));

        $qb = $this->connection->getQueryBuilder();
        $qb->update('user_vo_groups')
            ->set('sync_lock_until', $qb->createNamedParameter($expiresAt, 'datetime'))
            ->set('sync_lock_token', $qb->createNamedParameter($token))
            ->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter($voGroupId)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('sync_lock_until'),
                $qb->expr()->lt('sync_lock_until', $qb->createNamedParameter($now, 'datetime'))
            ));

        return $qb->executeStatement() > 0 ? $token : null;
    }

    /**
     * Repeatedly attempts to acquire the lease, polling until it succeeds or
     * $maxWaitSeconds elapses.
     *
     * @return string|null The fencing token to pass to release() if acquired, else null.
     */
    public function acquireWithBoundedWait(string $voGroupId, float $maxWaitSeconds, int $leaseSeconds = self::LEASE_SECONDS): ?string {
        $deadline = microtime(true) + $maxWaitSeconds;
        do {
            $token = $this->tryAcquire($voGroupId, $leaseSeconds);
            if ($token !== null) {
                return $token;
            }
            usleep(250_000);
        } while (microtime(true) < $deadline);

        return null;
    }

    /**
     * Releases the lease, but only if $token still matches the current
     * holder's token - if the lease was already reassigned to someone else
     * (this acquirer's own lease outlived its TTL before it got here), this
     * is a safe no-op rather than clearing the new holder's lease out from
     * under it.
     */
    public function release(string $voGroupId, string $token): void {
        $qb = $this->connection->getQueryBuilder();
        $qb->update('user_vo_groups')
            ->set('sync_lock_until', $qb->createNamedParameter(null))
            ->set('sync_lock_token', $qb->createNamedParameter(null))
            ->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter($voGroupId)))
            ->andWhere($qb->expr()->eq('sync_lock_token', $qb->createNamedParameter($token)));
        $qb->executeStatement();
    }
}
