<?php

declare(strict_types=1);

namespace OCA\UserVO\Listener;

use OCA\UserVO\Service\AuditLogService;
use OCA\UserVO\Service\GroupSyncLockService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\GroupDeletedEvent;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Listener for group deletion events
 * Cleans up user_vo_groups table when a managed group is deleted
 */
class GroupDeletedListener implements IEventListener {
    // Same bound GroupManagementService::deleteGroup() and GroupSyncService's
    // blocking sync callers use.
    private const LOCK_WAIT_SECONDS = 3.0;

    private $connection;
    private $logger;
    private GroupSyncLockService $lockService;
    private AuditLogService $auditLogService;

    public function __construct(
        IDBConnection $connection,
        LoggerInterface $logger,
        GroupSyncLockService $lockService,
        AuditLogService $auditLogService
    ) {
        $this->connection = $connection;
        $this->logger = $logger;
        $this->lockService = $lockService;
        $this->auditLogService = $auditLogService;
    }

    public function handle(Event $event): void {
        if (!($event instanceof GroupDeletedEvent)) {
            return;
        }

        $group = $event->getGroup();
        $groupId = $group->getGID();

        // Check if this is a managed group (exists in our database)
        $qb = $this->connection->getQueryBuilder();
        $qb->select('vo_group_id', 'vo_group_name')
            ->from('user_vo_groups')
            ->where($qb->expr()->eq('nc_group_id', $qb->createNamedParameter($groupId)));
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        if (!$row) {
            // Not a managed group, nothing to do
            return;
        }

        $voGroupId = $row['vo_group_id'];

        // The NC group is already gone by the time this event fires (it fires
        // post-deletion), so this can't prevent a sync that's already mutating
        // its membership from racing a deletion triggered outside our own
        // GroupManagementService::deleteGroup() (e.g. via NC's native group
        // admin UI) - that's a structural limit of hooking a post-deletion
        // event, not something closeable here. What holding the lease before
        // removing the tracking row *does* close: if a sync is still holding
        // it, that sync's own release() (in its finally block) only happens
        // after all of its writes/ledger updates for this group are done, so
        // waiting for the lease first guarantees this cleanup can't race a
        // sync's trailing write and pull the row out from under it.
        $lockToken = $this->lockService->acquireWithBoundedWait($voGroupId, self::LOCK_WAIT_SECONDS);
        if ($lockToken === null) {
            // Leave the row in place rather than force a cleanup mid-sync -
            // the existing "NC group no longer exists" detection (admin UI /
            // group_sync_failed audit entries) surfaces this until an admin
            // resolves it via recreateGroup()/deleteGroup().
            $this->logger->warning('Skipped tracking-row cleanup after group deletion - sync in progress', [
                'app' => 'user_vo',
                'nc_group_id' => $groupId,
                'vo_group_id' => $voGroupId
            ]);
            return;
        }

        try {
            // Remove from our tracking table
            $deleteQb = $this->connection->getQueryBuilder();
            $deleteQb->delete('user_vo_groups')
                ->where($deleteQb->expr()->eq('nc_group_id', $deleteQb->createNamedParameter($groupId)));
            $deleteQb->executeStatement();
        } finally {
            $this->lockService->release($voGroupId, $lockToken);
        }

        $this->logger->info('Cleaned up managed group after deletion', [
            'app' => 'user_vo',
            'nc_group_id' => $groupId,
            'vo_group_id' => $voGroupId,
            'vo_group_name' => $row['vo_group_name']
        ]);

        // Same event GroupManagementService::deleteGroup() logs for its own
        // deletion path - without this, the same logical event (a managed
        // group disappearing) would be invisible in the audit log whenever
        // it happens via NC's native group admin UI instead.
        $this->auditLogService->log('group_deleted', null, $voGroupId, "Group '{$row['vo_group_name']}' (NC group '$groupId') deleted via Nextcloud's own group management");
    }
}
