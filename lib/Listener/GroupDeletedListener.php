<?php

declare(strict_types=1);

namespace OCA\UserVO\Listener;

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

    private $connection;
    private $logger;

    public function __construct(
        IDBConnection $connection,
        LoggerInterface $logger
    ) {
        $this->connection = $connection;
        $this->logger = $logger;
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

        // Remove from our tracking table
        $deleteQb = $this->connection->getQueryBuilder();
        $deleteQb->delete('user_vo_groups')
            ->where($deleteQb->expr()->eq('nc_group_id', $deleteQb->createNamedParameter($groupId)));
        $deleteQb->executeStatement();

        $this->logger->info('Cleaned up managed group after deletion', [
            'app' => 'user_vo',
            'nc_group_id' => $groupId,
            'vo_group_id' => $row['vo_group_id'],
            'vo_group_name' => $row['vo_group_name']
        ]);
    }
}
