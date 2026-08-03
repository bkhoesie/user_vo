<?php
declare(strict_types=1);

namespace OCA\UserVO\Service;

use OCP\IDBConnection;

/**
 * DB-backed audit trail (user_vo_audit_log, see Version1006 migration) for
 * state changes and failures this plugin is responsible for - not a general
 * activity feed, and deliberately not a log file. The driving constraint:
 * the production deployment has no direct nextcloud.log access without going
 * through support, so anything worth debugging later needs to be queryable
 * through the DB access this deployment does have.
 *
 * What gets logged is deliberately scoped to real state changes and
 * failures, not routine successful no-ops: login failures and backend-
 * conflict refusals (not successful logins - NC's periodic credential-token
 * revalidation calls the exact same code path as a real login with no way to
 * tell them apart, so "login succeeded" isn't a meaningful discrete event
 * and would mostly be revalidation noise), account/group creation and
 * deletion, group membership actually changing (one entry per sync that
 * changed something, not one per no-op sync), sync failures, and config
 * changes. See AuditLogCleanupJob for retention.
 *
 * @psalm-type AuditLogEntry = array{id: int, created_at: string, event_type: string, uid: ?string, group_id: ?string, message: string}
 */
class AuditLogService {
    private IDBConnection $connection;

    public function __construct(IDBConnection $connection) {
        $this->connection = $connection;
    }

    public function log(string $eventType, ?string $uid, ?string $groupId, string $message): void {
        $qb = $this->connection->getQueryBuilder();
        $qb->insert('user_vo_audit_log')
            ->values([
                // Literal 'datetime' rather than IQueryBuilder::PARAM_DATE: see
                // GroupSyncLockService for why (NC 28-30 compat).
                'created_at' => $qb->createNamedParameter(new \DateTime(), 'datetime'),
                'event_type' => $qb->createNamedParameter($eventType),
                'uid' => $qb->createNamedParameter($uid),
                'group_id' => $qb->createNamedParameter($groupId),
                'message' => $qb->createNamedParameter($message),
            ])
            ->executeStatement();
    }

    /**
     * @return list<array{id: int, created_at: string, event_type: string, uid: ?string, group_id: ?string, message: string}>
     */
    public function getRecentEntries(int $limit = 500): array {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('id', 'created_at', 'event_type', 'uid', 'group_id', 'message')
            ->from('user_vo_audit_log')
            ->orderBy('created_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setMaxResults($limit);

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        return array_map(static fn (array $row): array => [
            'id' => (int)$row['id'],
            'created_at' => (string)$row['created_at'],
            'event_type' => (string)$row['event_type'],
            'uid' => $row['uid'] !== null ? (string)$row['uid'] : null,
            'group_id' => $row['group_id'] !== null ? (string)$row['group_id'] : null,
            'message' => (string)$row['message'],
        ], $rows);
    }

    /**
     * Plain-text rendering of the full log (oldest first), for the admin
     * download action. Not paginated/limited - the 7-day-default retention
     * (AuditLogCleanupJob) is what keeps this bounded, not this method.
     */
    public function getAllEntriesAsText(): string {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('created_at', 'event_type', 'uid', 'group_id', 'message')
            ->from('user_vo_audit_log')
            ->orderBy('created_at', 'ASC')
            ->addOrderBy('id', 'ASC');

        $result = $qb->executeQuery();
        $lines = [];
        while ($row = $result->fetch()) {
            $lines[] = sprintf(
                '%s [%s] uid=%s group=%s: %s',
                $row['created_at'],
                $row['event_type'],
                $row['uid'] ?? '-',
                $row['group_id'] ?? '-',
                $row['message']
            );
        }
        $result->closeCursor();

        return implode("\n", $lines) . "\n";
    }

    /**
     * Deletes entries older than $retentionDays. Returns the number of rows
     * deleted (for the cleanup job's own logging).
     */
    public function cleanupOlderThan(int $retentionDays): int {
        $cutoff = (new \DateTime())->modify("-{$retentionDays} days");

        $qb = $this->connection->getQueryBuilder();
        $qb->delete('user_vo_audit_log')
            ->where($qb->expr()->lt('created_at', $qb->createNamedParameter($cutoff, 'datetime')));

        return $qb->executeStatement();
    }

    /**
     * Deletes every entry (an explicit admin action, not retention policy) -
     * then logs a single fresh entry recording the clear itself, so the log
     * isn't just silently empty with no explanation of why. Returns the
     * number of rows deleted (not counting the new entry).
     */
    public function clearAll(): int {
        $qb = $this->connection->getQueryBuilder();
        $qb->delete('user_vo_audit_log');
        $deleted = $qb->executeStatement();

        $this->log('audit_log_cleared', null, null, "Audit log cleared manually via admin interface ($deleted entries removed)");

        return $deleted;
    }
}
