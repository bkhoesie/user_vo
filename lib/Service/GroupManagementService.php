<?php
declare(strict_types=1);

namespace OCA\UserVO\Service;

use OCP\IDBConnection;
use OCP\IGroupManager;
use OCA\UserVO\UserVOAuth;
use Psr\Log\LoggerInterface;

/**
 * Service for group CRUD operations
 *
 * Handles creating and deleting Nextcloud groups from VereinOnline groups,
 * including hierarchical position calculation.
 */
class GroupManagementService {
    private IDBConnection $connection;
    private IGroupManager $groupManager;
    private GroupNameHarmonizer $groupNameHarmonizer;
    private LoggerInterface $logger;

    public function __construct(
        IDBConnection $connection,
        IGroupManager $groupManager,
        GroupNameHarmonizer $groupNameHarmonizer,
        LoggerInterface $logger
    ) {
        $this->connection = $connection;
        $this->groupManager = $groupManager;
        $this->groupNameHarmonizer = $groupNameHarmonizer;
        $this->logger = $logger;
    }

    /**
     * Create a Nextcloud group from a VereinOnline group
     *
     * @param string $voGroupId VO group ID
     * @param UserVOAuth $backend Backend instance for API access
     * @return array Result with success, nc_group_id, vo_group_name, or error
     */
    public function createGroup(string $voGroupId, UserVOAuth $backend): array {
        // Check if group is already managed
        $qb = $this->connection->getQueryBuilder();
        $qb->select('vo_group_id')
            ->from('user_vo_groups')
            ->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter($voGroupId)));
        $result = $qb->executeQuery();
        $existing = $result->fetch();
        $result->closeCursor();

        if ($existing) {
            return [
                'success' => false,
                'error' => 'Group is already managed',
                'status_code' => 409
            ];
        }

        // Fetch all groups from VO to find this specific group
        $allGroups = $backend->fetchAllGroups();

        if (!$allGroups) {
            return [
                'success' => false,
                'error' => 'Failed to fetch groups from VereinOnline',
                'status_code' => 500
            ];
        }

        // Find the specific group
        $groupData = null;
        foreach ($allGroups as $group) {
            if ($group['id'] === $voGroupId) {
                $groupData = $group;
                break;
            }
        }

        if (!$groupData) {
            return [
                'success' => false,
                'error' => 'Group not found in VereinOnline',
                'status_code' => 404
            ];
        }

        $voGroupName = $groupData['name'] ?? '';
        $voParentId = $groupData['parentid'] ?? null;
        $voPosition = isset($groupData['pos']) ? (int)$groupData['pos'] : null;

        // Use uservo_ prefix for NC group ID (clear ownership, no collisions)
        $ncGroupId = "uservo_" . $voGroupId;
        $ncDisplayName = $this->groupNameHarmonizer->harmonize($voGroupName);

        // Check if NC group already exists
        if ($this->groupManager->groupExists($ncGroupId)) {
            // Group exists in NC but not in our database - we can still proceed to track it
            $this->logger->info("NC group already exists, adding to management", [
                'app' => 'user_vo',
                'nc_group_id' => $ncGroupId,
                'vo_group_id' => $voGroupId
            ]);
        } else {
            // Create the NC group
            $ncGroup = $this->groupManager->createGroup($ncGroupId);
            if (!$ncGroup) {
                return [
                    'success' => false,
                    'error' => 'Failed to create Nextcloud group',
                    'status_code' => 500
                ];
            }

            // Set the display name (harmonized from VO name)
            $ncGroup->setDisplayName($ncDisplayName);

            $this->logger->info("Created NC group", [
                'app' => 'user_vo',
                'nc_group_id' => $ncGroupId,
                'nc_display_name' => $ncDisplayName,
                'vo_group_id' => $voGroupId
            ]);
        }

        // Calculate position index using the full VO groups data
        $positionIndex = $this->calculatePositionIndex($voParentId, $voPosition, $allGroups);

        // Insert record into database
        $insertQb = $this->connection->getQueryBuilder();
        $insertQb->insert('user_vo_groups')
            ->values([
                'vo_group_id' => $insertQb->createNamedParameter($voGroupId),
                'vo_group_name' => $insertQb->createNamedParameter($voGroupName),
                'nc_group_id' => $insertQb->createNamedParameter($ncGroupId),
                'nc_display_name' => $insertQb->createNamedParameter($ncDisplayName),
                'vo_parent_id' => $insertQb->createNamedParameter($voParentId),
                'vo_position' => $insertQb->createNamedParameter($voPosition, \PDO::PARAM_INT),
                'vo_position_index' => $insertQb->createNamedParameter($positionIndex),
                'deleted_in_vo' => $insertQb->createNamedParameter(0, \PDO::PARAM_INT),
                'member_count' => $insertQb->createNamedParameter(0, \PDO::PARAM_INT),
                'vo_member_count' => $insertQb->createNamedParameter(0, \PDO::PARAM_INT),
                'non_vo_member_count' => $insertQb->createNamedParameter(0, \PDO::PARAM_INT),
            ]);
        $insertQb->executeStatement();

        return [
            'success' => true,
            'message' => "Group created successfully",
            'nc_group_id' => $ncGroupId,
            'vo_group_id' => $voGroupId,
            'vo_group_name' => $voGroupName
        ];
    }

    /**
     * Delete a managed group
     *
     * @param string $voGroupId VO group ID
     * @return array Result with success, nc_group_id, vo_group_name, or error
     */
    public function deleteGroup(string $voGroupId): array {
        // Get group info from database
        $qb = $this->connection->getQueryBuilder();
        $qb->select('nc_group_id', 'vo_group_name')
            ->from('user_vo_groups')
            ->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter($voGroupId)));
        $result = $qb->executeQuery();
        $groupRow = $result->fetch();
        $result->closeCursor();

        if (!$groupRow) {
            return [
                'success' => false,
                'error' => 'Group is not managed',
                'status_code' => 404
            ];
        }

        $ncGroupId = $groupRow['nc_group_id'];
        $voGroupName = $groupRow['vo_group_name'];

        // Delete the NC group (if it exists)
        if ($this->groupManager->groupExists($ncGroupId)) {
            $ncGroup = $this->groupManager->get($ncGroupId);
            if ($ncGroup) {
                $ncGroup->delete();
                $this->logger->info("Deleted NC group", [
                    'app' => 'user_vo',
                    'nc_group_id' => $ncGroupId,
                    'vo_group_id' => $voGroupId
                ]);
            }
        }

        // Remove from tracking table (GroupDeletedListener will handle this,
        // but we do it explicitly here in case the event doesn't fire)
        $deleteQb = $this->connection->getQueryBuilder();
        $deleteQb->delete('user_vo_groups')
            ->where($deleteQb->expr()->eq('vo_group_id', $deleteQb->createNamedParameter($voGroupId)));
        $deleteQb->executeStatement();

        return [
            'success' => true,
            'message' => "Group deleted successfully",
            'nc_group_id' => $ncGroupId,
            'vo_group_id' => $voGroupId,
            'vo_group_name' => $voGroupName
        ];
    }

    /**
     * Calculate hierarchical position index for a group
     *
     * @param string|null $voParentId Parent group ID in VO
     * @param int|null $voPosition Position within siblings
     * @param array $allGroups All groups from VO API (for calculating full path when parent not in DB)
     * @return string Position index (e.g., "2", "2.5", "2.5.1")
     */
    private function calculatePositionIndex(?string $voParentId, ?int $voPosition, array $allGroups = []): string {
        // If no parent (root group), position index is just the position number
        if (empty($voParentId) || $voParentId === '0') {
            return (string)($voPosition ?? 0);
        }

        // Try to get parent's position index from database first (most efficient)
        $qb = $this->connection->getQueryBuilder();
        $qb->select('vo_position_index')
            ->from('user_vo_groups')
            ->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter($voParentId)));
        $result = $qb->executeQuery();
        $parentRow = $result->fetch();
        $result->closeCursor();

        if ($parentRow && !empty($parentRow['vo_position_index'])) {
            // Parent exists in database, append our position to parent's index
            return $parentRow['vo_position_index'] . '.' . ($voPosition ?? 0);
        }

        // Parent not in database - use VO data to calculate full hierarchical path
        if (!empty($allGroups)) {
            // Build index by walking up the parent chain using VO data
            $parentChain = $this->buildParentChainFromVO($voParentId, $allGroups);
            if (!empty($parentChain)) {
                // Reverse to get root-to-current order
                $parentChain = array_reverse($parentChain);
                // Build position index from chain
                $indexParts = array_map(fn($g) => (string)($g['pos'] ?? 0), $parentChain);
                $indexParts[] = (string)($voPosition ?? 0);
                return implode('.', $indexParts);
            }
        }

        // Fallback: Parent not in database and no VO data available
        // Just use position as index (best-effort for backward compatibility)
        return (string)($voPosition ?? 0);
    }

    /**
     * Build parent chain by walking up the VO group hierarchy
     *
     * @param string $groupId Starting group ID
     * @param array $allGroups All groups from VO API
     * @return array Array of parent groups from immediate parent to root
     */
    private function buildParentChainFromVO(string $groupId, array $allGroups): array {
        // Build a map for quick lookup
        $groupMap = [];
        foreach ($allGroups as $group) {
            if (isset($group['id'])) {
                $groupMap[$group['id']] = $group;
            }
        }

        $chain = [];
        $currentId = $groupId;

        // Walk up the parent chain (max 100 levels to prevent infinite loops)
        $maxDepth = 100;
        while ($maxDepth-- > 0 && !empty($currentId) && $currentId !== '0') {
            if (!isset($groupMap[$currentId])) {
                break; // Parent not found in VO data
            }

            $currentGroup = $groupMap[$currentId];
            $chain[] = $currentGroup;

            // Move to parent
            $currentId = $currentGroup['parentid'] ?? null;
            if (empty($currentId) || $currentId === '0') {
                break; // Reached root
            }
        }

        return $chain;
    }
}
