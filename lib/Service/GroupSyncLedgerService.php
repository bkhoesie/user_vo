<?php
declare(strict_types=1);

namespace OCA\UserVO\Service;

use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Per-group monotonic dirty/clean sequence ledger (see
 * Version1005Date20260803000000 migration for the schema and full B1
 * background). Closes the one remaining gap the sync lease
 * (GroupSyncLockService) doesn't: a user's own VO-metadata write isn't
 * synchronized with a concurrent full sync's read of VO membership for that
 * group, so a write landing in that window could otherwise be silently lost.
 *
 * markDirty() is called by the metadata writer (UserVOAuth::updateVOMetadata())
 * whenever a write may have changed a group's membership predicate.
 * markCleanIfStillOwned() is called by a completed sync
 * (GroupSyncService::syncSingleGroupFullLocked()) with the dirty_seq value it
 * captured *before* its own read of VO membership - never after. dirty_seq >
 * clean_seq then means "this group needs a resync", which GroupSyncSweepJob
 * periodically acts on through the normal lease-protected sync path.
 *
 * Only the *comparison* dirty_seq > clean_seq is ever meaningful - the exact
 * counter values and how many increments happened are not. A boolean or
 * timestamp can't express "this mark is newer than the snapshot I acted on";
 * a monotonic pair can, which is the entire reason this exists instead of a
 * simpler flag.
 */
class GroupSyncLedgerService {
    private IDBConnection $connection;
    private LoggerInterface $logger;

    public function __construct(IDBConnection $connection, LoggerInterface $logger) {
        $this->connection = $connection;
        $this->logger = $logger;
    }

    /**
     * Marks the given groups dirty via a single atomic SQL-level increment
     * (never a PHP read-modify-write - two concurrent callers must each
     * apply their own increment, not clobber one another). A single UPDATE
     * against a unique index locks in a deterministic order for all callers
     * regardless of the IDs' input order, so writer-vs-writer deadlock on
     * this table is structurally impossible; IDs are still deduplicated and
     * sorted for a stable, readable statement, not because sorting itself is
     * what prevents deadlock.
     *
     * @param string[] $voGroupIds Managed VO group IDs; unmanaged IDs simply
     *   match no row and are silently ignored.
     */
    public function markDirty(array $voGroupIds): void {
        $ids = array_values(array_unique(array_filter($voGroupIds, static fn ($id) => $id !== '')));
        if (empty($ids)) {
            return;
        }
        sort($ids);

        $qb = $this->connection->getQueryBuilder();
        $qb->update('user_vo_groups')
            ->set('dirty_seq', $qb->createFunction('dirty_seq + 1'))
            ->where($qb->expr()->in('vo_group_id', $qb->createNamedParameter($ids, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)));
        $qb->executeStatement();
    }

    /**
     * Advances clean_seq to $seqAtStart, but only if:
     *  - $lockToken still matches the current lease holder (otherwise this
     *    sync's own work may have outlived its lease and be based on a
     *    snapshot older than whatever the current holder is doing - see the
     *    Version1005 migration doc-comment for the full interleaving
     *    argument), and
     *  - clean_seq hasn't already been advanced past $seqAtStart by another
     *    (necessarily earlier-capturing) sync.
     *
     * If the token no longer matches, this call re-dirties the group instead
     * of silently doing nothing: this sync may have just applied membership
     * computed from a stale snapshot, so the sweep must revisit it.
     *
     * If the token still matches but clean_seq is already >= $seqAtStart,
     * that has exactly two possible causes, both handled here:
     *  - clean_seq == seqAtStart: a redundant sync (nothing changed since the
     *    last one completed) - genuinely benign, no action needed.
     *  - clean_seq > seqAtStart: under correct operation this cannot happen
     *    on its own - mutual exclusion means nothing else could have
     *    advanced clean_seq past our own seqAtStart while we still hold the
     *    one lease unbroken, and clean_seq is only ever set to a value that
     *    was itself once a valid dirty_seq snapshot, so dirty_seq >=
     *    clean_seq should always hold. Reaching this case therefore means
     *    dirty_seq was reset by something outside the normal sync path
     *    (e.g. a past repair-step bug directly resetting it without
     *    justification - see ForceInitialGroupSweep's history). Rather than
     *    silently tolerating that and waiting for enough further markDirty()
     *    calls to organically grow dirty_seq back past clean_seq, self-heal
     *    it immediately: any successful sync completion is exactly the right
     *    moment to notice and repair this, not just the next app upgrade.
     */
    public function markCleanIfStillOwned(string $voGroupId, string $lockToken, int $seqAtStart): void {
        $qb = $this->connection->getQueryBuilder();
        $qb->update('user_vo_groups')
            ->set('clean_seq', $qb->createNamedParameter($seqAtStart, \PDO::PARAM_INT))
            ->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter($voGroupId)))
            ->andWhere($qb->expr()->eq('sync_lock_token', $qb->createNamedParameter($lockToken)))
            ->andWhere($qb->expr()->lt('clean_seq', $qb->createNamedParameter($seqAtStart, \PDO::PARAM_INT)));

        if ($qb->executeStatement() > 0) {
            return;
        }

        $selectQb = $this->connection->getQueryBuilder();
        $selectQb->select('sync_lock_token')
            ->from('user_vo_groups')
            ->where($selectQb->expr()->eq('vo_group_id', $selectQb->createNamedParameter($voGroupId)));
        $result = $selectQb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        // No row: the group was deleted mid-sync, nothing left to mark.
        if ($row === false) {
            return;
        }

        if ($row['sync_lock_token'] !== $lockToken) {
            $this->markDirty([$voGroupId]);
            $this->logger->warning('Group sync lease expired before completion - marking dirty for the sweep to repair', [
                'vo_group_id' => $voGroupId,
            ]);
            return;
        }

        // Token still ours, clean_seq already >= seqAtStart: only worth
        // acting on if dirty_seq is *behind* clean_seq (see the doc-comment
        // above) - a normal redundant sync (clean_seq == seqAtStart) must not
        // be perturbed.
        $healQb = $this->connection->getQueryBuilder();
        $healQb->update('user_vo_groups')
            ->set('dirty_seq', $healQb->createFunction('clean_seq + 1'))
            ->where($healQb->expr()->eq('vo_group_id', $healQb->createNamedParameter($voGroupId)))
            ->andWhere($healQb->expr()->lt('dirty_seq', 'clean_seq'));
        if ($healQb->executeStatement() > 0) {
            $this->logger->warning('Detected and repaired an inverted dirty/clean ledger state (dirty_seq was behind clean_seq)', [
                'vo_group_id' => $voGroupId,
            ]);
        }
    }

    /**
     * Finds groups needing a resync (dirty_seq > clean_seq), oldest-synced
     * first so a hot group can't starve others out of the sweep's per-run
     * batch.
     *
     * @return string[] VO group IDs.
     */
    public function findDirtyGroups(int $limit): array {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('vo_group_id')
            ->from('user_vo_groups')
            ->where($qb->expr()->gt('dirty_seq', 'clean_seq'))
            ->orderBy('last_synced', 'ASC')
            ->setMaxResults($limit);

        $result = $qb->executeQuery();
        $ids = array_column($result->fetchAll(), 'vo_group_id');
        $result->closeCursor();
        return $ids;
    }
}
