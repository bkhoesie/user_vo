<?php
/**
 * @author Nikolaus Demmel <nikolaus@nikolaus-demmel.de>
 * @copyright (c) 2025 Nikolaus Demmel <nikolaus@nikolaus-demmel.de>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the LICENSE file.
 */

declare(strict_types=1);

namespace OCA\UserVO\Service;

use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCA\UserVO\UserVOAuth;
use OCA\UserVO\Service\ConfigService;
use OCA\UserVO\Service\GroupNameHarmonizer;
use function OCP\Log\logger;

/**
 * Shared service for syncing VO group memberships to Nextcloud groups
 */
class GroupSyncService {
    private IDBConnection $connection;
    private IGroupManager $groupManager;
    private IUserManager $userManager;
    private ConfigService $configService;
    private GroupNameHarmonizer $groupNameHarmonizer;

    public function __construct(
        IDBConnection $connection,
        IGroupManager $groupManager,
        IUserManager $userManager,
        ConfigService $configService,
        GroupNameHarmonizer $groupNameHarmonizer
    ) {
        $this->connection = $connection;
        $this->groupManager = $groupManager;
        $this->userManager = $userManager;
        $this->configService = $configService;
        $this->groupNameHarmonizer = $groupNameHarmonizer;
    }

    /**
     * Sync a single group's membership from VO data
     *
     * @param string $ncGroupId NC group ID
     * @param array $voGroupData VO group data (must include 'member_ids')
     * @return array Result with 'success', 'added', 'removed'
     */
    public function syncGroupMembership(string $ncGroupId, array $voGroupData): array {
        try {
            $group = $this->groupManager->get($ncGroupId);
            if (!$group) {
                return [
                    'success' => false,
                    'error' => 'Group not found in Nextcloud',
                    'added' => 0,
                    'removed' => 0
                ];
            }

            // Get VO member IDs
            $voMemberIds = !empty($voGroupData['member_ids'])
                ? explode(',', $voGroupData['member_ids'])
                : [];

            // Map VO user IDs to NC usernames
            $voUserIdToUid = $this->mapVOUserIdsToNCUsernames($voMemberIds);

            // Get current NC group members
            $currentMembers = $group->getUsers();
            $currentMemberUids = array_map(function($user) {
                return $user->getUID();
            }, $currentMembers);

            // Determine who should be members (based on VO data)
            $shouldBeMembers = array_values($voUserIdToUid);

            // Add missing members
            $addedCount = 0;
            foreach ($shouldBeMembers as $uid) {
                if (!in_array($uid, $currentMemberUids)) {
                    $user = $this->userManager->get($uid);
                    if ($user) {
                        $group->addUser($user);
                        $addedCount++;
                        logger('user_vo')->debug("Added user to group", [
                            'uid' => $uid,
                            'group' => $ncGroupId
                        ]);
                    }
                }
            }

            // Remove users who shouldn't be members
            // Only remove user_vo users (check if they have vo_user_id in user_vo table)
            $removedCount = 0;
            foreach ($currentMemberUids as $uid) {
                if (!in_array($uid, $shouldBeMembers)) {
                    // Check if this is a user_vo managed user
                    if ($this->isVOUser($uid)) {
                        $user = $this->userManager->get($uid);
                        if ($user) {
                            $group->removeUser($user);
                            $removedCount++;
                            logger('user_vo')->debug("Removed user from group", [
                                'uid' => $uid,
                                'group' => $ncGroupId
                            ]);
                        }
                    }
                }
            }

            return [
                'success' => true,
                'added' => $addedCount,
                'removed' => $removedCount
            ];

        } catch (\Exception $e) {
            logger('user_vo')->error("Failed to sync group membership", [
                'nc_group_id' => $ncGroupId,
                'error' => $e->getMessage()
            ]);
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'added' => 0,
                'removed' => 0
            ];
        }
    }

    /**
     * Map VO user IDs to NC usernames
     *
     * @param array $voUserIds Array of VO user IDs
     * @return array Map of vo_user_id => nc_username
     */
    private function mapVOUserIdsToNCUsernames(array $voUserIds): array {
        if (empty($voUserIds)) {
            return [];
        }

        $qb = $this->connection->getQueryBuilder();
        $qb->select('uid', 'vo_user_id')
            ->from('user_vo')
            ->where($qb->expr()->in('vo_user_id', $qb->createNamedParameter($voUserIds, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)));

        $result = $qb->executeQuery();
        $userRows = $result->fetchAll();
        $result->closeCursor();

        $voUserIdToUid = [];
        foreach ($userRows as $row) {
            $voUserIdToUid[$row['vo_user_id']] = $row['uid'];
        }

        return $voUserIdToUid;
    }

    /**
     * Check if a user is managed by user_vo backend
     *
     * @param string $uid NC username
     * @return bool True if user is managed by user_vo
     */
    private function isVOUser(string $uid): bool {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('vo_user_id')
            ->from('user_vo')
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
        $result = $qb->executeQuery();
        $isVOUser = $result->fetchOne() !== false;
        $result->closeCursor();

        return $isVOUser;
    }

    /**
     * Sync specific groups by VO group IDs with full metadata updates
     *
     * This method performs a complete sync for the specified groups:
     * - Fetches fresh VO data via API
     * - Updates group display names
     * - Syncs membership (add/remove users based on cached vo_group_ids)
     * - Updates database metadata (last_synced, member counts, etc.)
     *
     * @param array $voGroupIds Array of VO group IDs to sync
     * @return array Result with 'success', 'synced', 'failed', 'results'
     */
    public function syncGroupsByIds(array $voGroupIds): array {
        try {
            if (empty($voGroupIds)) {
                return [
                    'success' => true,
                    'synced' => 0,
                    'failed' => 0,
                    'results' => []
                ];
            }

            // Get managed groups that match the provided IDs
            $qb = $this->connection->getQueryBuilder();
            $qb->select('vo_group_id', 'vo_group_name', 'nc_group_id')
                ->from('user_vo_groups')
                ->where($qb->expr()->isNotNull('nc_group_id'))
                ->andWhere($qb->expr()->in('vo_group_id', $qb->createNamedParameter($voGroupIds, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)));
            $result = $qb->executeQuery();
            $managedGroups = $result->fetchAll();
            $result->closeCursor();

            if (empty($managedGroups)) {
                return [
                    'success' => true,
                    'synced' => 0,
                    'failed' => 0,
                    'results' => []
                ];
            }

            // Fetch all VO groups to build the group map (needed for metadata sync)
            $configuration = $this->configService->loadConfiguration(maskPassword: false);
            $backend = new UserVOAuth(
                $configuration['api_url'],
                $configuration['api_username'],
                $configuration['api_password']
            );
            $allVOGroups = $backend->fetchAllGroups();

            if (!$allVOGroups) {
                return [
                    'success' => false,
                    'error' => 'Failed to fetch groups from VereinOnline',
                    'synced' => 0,
                    'failed' => 0,
                    'results' => []
                ];
            }

            // Build map of VO group data for quick lookup
            $voGroupMap = [];
            foreach ($allVOGroups as $group) {
                if (isset($group['id'])) {
                    $voGroupMap[$group['id']] = $group;
                }
            }

            // Sync each group
            $syncedCount = 0;
            $failedCount = 0;
            $results = [];

            foreach ($managedGroups as $groupRow) {
                $voGroupId = $groupRow['vo_group_id'];
                $ncGroupId = $groupRow['nc_group_id'];
                $voGroupName = $groupRow['vo_group_name'];

                try {
                    $syncResult = $this->syncSingleGroupFull($voGroupId, $ncGroupId, $voGroupName, $voGroupMap);
                    $results[] = [
                        'vo_group_id' => $voGroupId,
                        'vo_group_name' => $voGroupName,
                        'nc_group_id' => $ncGroupId,
                        'status' => 'success',
                        'added' => $syncResult['added'],
                        'removed' => $syncResult['removed']
                    ];
                    $syncedCount++;
                } catch (\Exception $e) {
                    logger('user_vo')->error('Failed to sync group', [
                        'vo_group_id' => $voGroupId,
                        'error' => $e->getMessage()
                    ]);
                    $results[] = [
                        'vo_group_id' => $voGroupId,
                        'vo_group_name' => $voGroupName,
                        'nc_group_id' => $ncGroupId,
                        'status' => 'error',
                        'error' => $e->getMessage()
                    ];
                    $failedCount++;
                }
            }

            return [
                'success' => true,
                'synced' => $syncedCount,
                'failed' => $failedCount,
                'results' => $results
            ];

        } catch (\Exception $e) {
            logger('user_vo')->error('Failed to sync groups by IDs', [
                'vo_group_ids' => $voGroupIds,
                'error' => $e->getMessage()
            ]);
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'synced' => 0,
                'failed' => 0,
                'results' => []
            ];
        }
    }

    /**
     * Helper method to sync a single group with full metadata updates
     * This is extracted from AdminController for reusability
     */
    private function syncSingleGroupFull(string $voGroupId, string $ncGroupId, string $storedVOName, array $voGroupMap): array {
        // Get stored metadata
        $qb = $this->connection->getQueryBuilder();
        $qb->select('vo_parent_id', 'vo_position')
            ->from('user_vo_groups')
            ->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter($voGroupId)));
        $result = $qb->executeQuery();
        $groupRow = $result->fetch();
        $result->closeCursor();

        if (!$groupRow) {
            throw new \Exception('Group not found in database');
        }

        $storedVOParentId = $groupRow['vo_parent_id'];
        $storedVOPosition = $groupRow['vo_position'] ? (int)$groupRow['vo_position'] : null;

        // Get current VO metadata (or use stored values if group deleted in VO)
        $currentVOGroup = $voGroupMap[$voGroupId] ?? null;
        $groupDeletedInVO = ($currentVOGroup === null);

        if ($groupDeletedInVO) {
            $currentVOName = $storedVOName;
            $currentVOParentId = $storedVOParentId;
            $currentVOPosition = $storedVOPosition;
        } else {
            $currentVOName = $currentVOGroup['name'] ?? '';
            $currentVOParentId = $currentVOGroup['parentid'] ?? null;
            $currentVOPosition = isset($currentVOGroup['pos']) ? (int)$currentVOGroup['pos'] : null;
        }

        // Get the NC group
        $ncGroup = $this->groupManager->get($ncGroupId);
        if (!$ncGroup) {
            throw new \Exception('NC group does not exist');
        }

        // Auto-update display name to match current VO name
        $expectedDisplayName = $this->groupNameHarmonizer->harmonize($currentVOName);
        $currentDisplayName = $ncGroup->getDisplayName();
        if ($currentDisplayName !== $expectedDisplayName) {
            $ncGroup->setDisplayName($expectedDisplayName);
        }

        // Get all VO users who should be in this group (based on cached vo_group_ids)
        $qb = $this->connection->getQueryBuilder();
        $qb->select('uid', 'vo_user_id', 'vo_group_ids')
            ->from('user_vo')
            ->where($qb->expr()->eq('backend', $qb->createNamedParameter('user_vo')));
        $result = $qb->executeQuery();
        $allUsers = $result->fetchAll();
        $result->closeCursor();

        // Filter users who should be in this group
        $expectedVOUsernames = [];
        foreach ($allUsers as $userRow) {
            $uid = $userRow['uid'];
            $voGroupIds = $userRow['vo_group_ids'];

            // Skip users with !duplicate marker
            if (str_ends_with($uid, '!duplicate')) {
                continue;
            }

            // Skip users without vo_group_ids
            if (empty($voGroupIds)) {
                continue;
            }

            // Parse group IDs (comma-separated)
            $groupIdArray = array_map('trim', explode(',', $voGroupIds));

            // Check if this group is in the user's group list
            if (in_array($voGroupId, $groupIdArray, true)) {
                $expectedVOUsernames[] = $uid;
            }
        }

        // Get current NC group members
        $currentMembers = $ncGroup->getUsers();
        $currentMemberIds = array_map(fn($user) => $user->getUID(), $currentMembers);

        // Sync: add missing users, remove departed users
        $added = [];
        $removed = [];

        // Add users who should be in the group but aren't
        foreach ($expectedVOUsernames as $username) {
            if (!in_array($username, $currentMemberIds, true)) {
                $user = $this->userManager->get($username);
                if ($user) {
                    $ncGroup->addUser($user);
                    $added[] = $username;
                }
            }
        }

        // Remove users who are no longer in VO group
        foreach ($currentMemberIds as $username) {
            if (!in_array($username, $expectedVOUsernames, true)) {
                // Only remove if user is from user_vo backend
                $user = $this->userManager->get($username);
                if ($user && $user->getBackendClassName() === 'OCA\\UserVO\\UserVOAuth') {
                    $ncGroup->removeUser($user);
                    $removed[] = $username;
                }
            }
        }

        // Calculate member counts
        $allMembers = $ncGroup->getUsers();
        $totalCount = count($allMembers);
        $voCount = 0;
        $nonVoCount = 0;

        foreach ($allMembers as $member) {
            if ($member->getBackendClassName() === 'OCA\\UserVO\\UserVOAuth') {
                $voCount++;
            } else {
                $nonVoCount++;
            }
        }

        // Update metadata in database
        $now = new \DateTime();
        $updateQb = $this->connection->getQueryBuilder();
        $updateQb->update('user_vo_groups')
            ->set('last_synced', $updateQb->createNamedParameter($now->format('Y-m-d H:i:s')))
            ->set('nc_display_name', $updateQb->createNamedParameter($expectedDisplayName))
            ->set('vo_group_name', $updateQb->createNamedParameter($currentVOName))
            ->set('vo_parent_id', $updateQb->createNamedParameter($currentVOParentId))
            ->set('vo_position', $updateQb->createNamedParameter($currentVOPosition, \PDO::PARAM_INT))
            ->set('deleted_in_vo', $updateQb->createNamedParameter($groupDeletedInVO ? 1 : 0, \PDO::PARAM_INT))
            ->set('member_count', $updateQb->createNamedParameter($totalCount, \PDO::PARAM_INT))
            ->set('vo_member_count', $updateQb->createNamedParameter($voCount, \PDO::PARAM_INT))
            ->set('non_vo_member_count', $updateQb->createNamedParameter($nonVoCount, \PDO::PARAM_INT))
            ->where($updateQb->expr()->eq('vo_group_id', $updateQb->createNamedParameter($voGroupId)));

        // Update position index if parent or position changed (and group not deleted)
        if (!$groupDeletedInVO && ($currentVOParentId !== $storedVOParentId || $currentVOPosition !== $storedVOPosition)) {
            // Rebuild full VO groups array from map
            $allVOGroups = array_values($voGroupMap);
            $newPositionIndex = $this->calculatePositionIndex($currentVOParentId, $currentVOPosition, $allVOGroups);
            $updateQb->set('vo_position_index', $updateQb->createNamedParameter($newPositionIndex));
        }

        $updateQb->executeStatement();

        return [
            'added' => $added,
            'removed' => $removed,
            'member_count' => $totalCount,
            'vo_member_count' => $voCount,
            'non_vo_member_count' => $nonVoCount
        ];
    }

    /**
     * Calculate position index for hierarchical sorting
     * This is extracted from AdminController for reusability
     */
    private function calculatePositionIndex(?string $parentId, ?int $position, array $allGroups): int {
        // Build hierarchy map
        $childrenMap = [];
        foreach ($allGroups as $g) {
            $pid = $g['parentid'] ?? null;
            if (!isset($childrenMap[$pid])) {
                $childrenMap[$pid] = [];
            }
            $childrenMap[$pid][] = $g;
        }

        // Sort children by position
        foreach ($childrenMap as $pid => $children) {
            usort($childrenMap[$pid], function($a, $b) {
                $posA = $a['pos'] ?? 0;
                $posB = $b['pos'] ?? 0;
                return $posA <=> $posB;
            });
        }

        // Calculate position index via depth-first traversal
        $index = 0;
        $targetId = null;
        $targetIndex = 0;

        $traverse = function($pid) use (&$traverse, &$index, $childrenMap, $parentId, $position, &$targetId, &$targetIndex) {
            if (!isset($childrenMap[$pid])) {
                return;
            }

            foreach ($childrenMap[$pid] as $child) {
                $index++;
                $childId = $child['id'] ?? null;

                // Check if this is our target position
                if ($pid === $parentId && ($child['pos'] ?? 0) === $position) {
                    $targetIndex = $index;
                    $targetId = $childId;
                }

                // Recurse into children
                $traverse($childId);
            }
        };

        $traverse(null); // Start from root

        return $targetIndex;
    }
}
