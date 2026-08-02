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
use OCA\UserVO\Service\Exception\GroupSyncLockContentionException;
use OCA\UserVO\Service\GroupNameHarmonizer;
use function OCP\Log\logger;

/**
 * Shared service for syncing VO group memberships to Nextcloud groups
 *
 * @psalm-type GroupSyncSingleSummary = array{
 *     added: int,
 *     removed: int,
 *     skipped: int,
 *     total_members: int,
 *     vo_members: int,
 *     non_vo_members: int
 * }
 * @psalm-type GroupSyncSingleSuccess = array{
 *     success: true,
 *     message: string,
 *     vo_group_id: string,
 *     nc_group_id: string,
 *     vo_group_name: string,
 *     added: array,
 *     removed: array,
 *     skipped: array,
 *     member_count: int,
 *     vo_member_count: int,
 *     non_vo_member_count: int,
 *     summary: GroupSyncSingleSummary
 * }
 * @psalm-type GroupSyncError = array{success: false, error: string, status_code?: 400|404|409|500}
 * @psalm-type GroupSyncSingleResult = GroupSyncSingleSuccess|GroupSyncError
 * @psalm-type GroupSyncAllSummary = array{total: int, succeeded: int, failed: int}
 * @psalm-type GroupSyncAllSuccess = array{success: true, message?: string, summary: GroupSyncAllSummary, results: array}
 * @psalm-type GroupSyncAllResult = GroupSyncAllSuccess|GroupSyncError
 * @psalm-type GroupSyncByIdsResult = array{success: bool, error?: string, synced: int, failed: int, skipped: int, results: array}
 */
class GroupSyncService {
    /** Bounded wait for admin/cron syncs contending on an already-locked group. */
    private const LOCK_WAIT_SECONDS = 3.0;

    private IDBConnection $connection;
    private IGroupManager $groupManager;
    private IUserManager $userManager;
    private GroupNameHarmonizer $groupNameHarmonizer;
    private GroupSyncLockService $lockService;

    public function __construct(
        IDBConnection $connection,
        IGroupManager $groupManager,
        IUserManager $userManager,
        GroupNameHarmonizer $groupNameHarmonizer,
        GroupSyncLockService $lockService
    ) {
        $this->connection = $connection;
        $this->groupManager = $groupManager;
        $this->userManager = $userManager;
        $this->groupNameHarmonizer = $groupNameHarmonizer;
        $this->lockService = $lockService;
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
     * @param UserVOAuth $backend Backend instance for API access
     * @param bool $nonBlocking Login-time sync must never block a login: skip a
     *        group (once, no wait) if another sync already holds its lease,
     *        rather than the default bounded wait admin/provisioning callers get.
     * @return GroupSyncByIdsResult
     */
    public function syncGroupsByIds(array $voGroupIds, UserVOAuth $backend, bool $nonBlocking = false): array {
        try {
            if (empty($voGroupIds)) {
                return [
                    'success' => true,
                    'synced' => 0,
                    'failed' => 0,
                    'skipped' => 0,
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
                    'skipped' => 0,
                    'results' => []
                ];
            }

            // Fetch all VO groups to build the group map (needed for metadata sync)
            $allVOGroups = $backend->fetchAllGroups();

            if (!$allVOGroups) {
                return [
                    'success' => false,
                    'error' => 'Failed to fetch groups from VereinOnline',
                    'synced' => 0,
                    'failed' => 0,
                    'skipped' => 0,
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
            $skippedCount = 0;
            $results = [];

            foreach ($managedGroups as $groupRow) {
                $voGroupId = $groupRow['vo_group_id'];
                $ncGroupId = $groupRow['nc_group_id'];
                $voGroupName = $groupRow['vo_group_name'];

                try {
                    $syncResult = $this->syncSingleGroupFull($voGroupId, $ncGroupId, $voGroupName, $voGroupMap, $nonBlocking);

                    if ($syncResult['locked'] ?? false) {
                        // Another sync already holds this group's lease (only reachable
                        // in non-blocking/login mode) - nothing was touched, so this must
                        // not be counted or reported the same as an actual successful sync.
                        $results[] = [
                            'vo_group_id' => $voGroupId,
                            'vo_group_name' => $voGroupName,
                            'nc_group_id' => $ncGroupId,
                            'status' => 'skipped',
                            'reason' => 'Sync already in progress for this group'
                        ];
                        $skippedCount++;
                        continue;
                    }

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

            if ($skippedCount > 0) {
                logger('user_vo')->info('Some group syncs were skipped due to lock contention', [
                    'skipped' => $skippedCount,
                    'synced' => $syncedCount,
                    'failed' => $failedCount
                ]);
            }

            return [
                'success' => true,
                'synced' => $syncedCount,
                'failed' => $failedCount,
                'skipped' => $skippedCount,
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
                'skipped' => 0,
                'results' => []
            ];
        }
    }

    /**
     * Sync a single group by VO group ID with full metadata and detailed response
     *
     * This is the main entry point for UI-triggered single group sync.
     * Returns detailed results suitable for JSON API response.
     *
     * @param string $voGroupId VO group ID to sync
     * @param UserVOAuth $backend Backend instance for API access
     * @return GroupSyncSingleResult
     */
    public function syncSingleGroupById(string $voGroupId, UserVOAuth $backend): array {
        try {
            if (empty($voGroupId)) {
                return [
                    'success' => false,
                    'error' => 'VO group ID is required',
                    'status_code' => 400
                ];
            }

            // Check if group is managed
            $qb = $this->connection->getQueryBuilder();
            $qb->select('nc_group_id', 'vo_group_name', 'vo_parent_id', 'vo_position')
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
            $storedVOName = $groupRow['vo_group_name'];

            // Fetch all VO groups (needed for metadata sync)
            $allVOGroups = $backend->fetchAllGroups();
            if (!$allVOGroups) {
                return [
                    'success' => false,
                    'error' => 'Failed to fetch groups from VereinOnline',
                    'status_code' => 500
                ];
            }

            // Build map for quick lookup
            $voGroupMap = [];
            foreach ($allVOGroups as $group) {
                if (isset($group['id'])) {
                    $voGroupMap[$group['id']] = $group;
                }
            }

            // Perform sync using unified helper
            $syncResult = $this->syncSingleGroupFull($voGroupId, $ncGroupId, $storedVOName, $voGroupMap);

            // Get current VO name for response
            $currentVOGroup = $voGroupMap[$voGroupId] ?? null;
            $currentVOName = $currentVOGroup ? ($currentVOGroup['name'] ?? $storedVOName) : $storedVOName;

            return [
                'success' => true,
                'message' => 'Group synced successfully',
                'vo_group_id' => $voGroupId,
                'nc_group_id' => $ncGroupId,
                'vo_group_name' => $currentVOName,
                'added' => $syncResult['added'],
                'removed' => $syncResult['removed'],
                'skipped' => $syncResult['skipped'],
                'member_count' => $syncResult['member_count'],
                'vo_member_count' => $syncResult['vo_member_count'],
                'non_vo_member_count' => $syncResult['non_vo_member_count'],
                'summary' => [
                    'added' => count($syncResult['added']),
                    'removed' => count($syncResult['removed']),
                    'skipped' => count($syncResult['skipped']),
                    'total_members' => $syncResult['member_count'],
                    'vo_members' => $syncResult['vo_member_count'],
                    'non_vo_members' => $syncResult['non_vo_member_count']
                ]
            ];

        } catch (GroupSyncLockContentionException $e) {
            logger('user_vo')->info('Group sync skipped - already in progress', [
                'vo_group_id' => $voGroupId ?? 'unknown'
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'status_code' => 409
            ];
        } catch (\Exception $e) {
            logger('user_vo')->error('Failed to sync group', [
                'vo_group_id' => $voGroupId ?? 'unknown',
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'status_code' => 500
            ];
        }
    }

    /**
     * Sync all managed groups with full metadata updates
     *
     * This is used by both UI bulk sync and nightly cron job.
     * Returns summary suitable for JSON API response.
     *
     * @param UserVOAuth $backend Backend instance for API access
     * @return GroupSyncAllResult
     */
    public function syncAllManagedGroups(UserVOAuth $backend): array {
        try {
            // Get all managed groups from database
            $qb = $this->connection->getQueryBuilder();
            $qb->select('vo_group_id', 'vo_group_name', 'nc_group_id')
                ->from('user_vo_groups')
                ->orderBy('vo_position_index', 'ASC');
            $result = $qb->executeQuery();
            $managedGroups = $result->fetchAll();
            $result->closeCursor();

            if (empty($managedGroups)) {
                return [
                    'success' => true,
                    'message' => 'No managed groups to sync',
                    'summary' => [
                        'total' => 0,
                        'succeeded' => 0,
                        'failed' => 0
                    ],
                    'results' => []
                ];
            }

            // Fetch VO groups once to avoid repeated API calls
            $allVOGroups = $backend->fetchAllGroups();

            if (!$allVOGroups) {
                return [
                    'success' => false,
                    'error' => 'Failed to fetch groups from VereinOnline',
                    'status_code' => 500
                ];
            }

            // Build map of VO group data for quick lookup
            $voGroupMap = [];
            foreach ($allVOGroups as $group) {
                if (isset($group['id'])) {
                    $voGroupMap[$group['id']] = $group;
                }
            }

            // Sync each group and collect results
            $results = [];
            $successCount = 0;
            $failureCount = 0;

            foreach ($managedGroups as $groupRow) {
                $voGroupId = $groupRow['vo_group_id'];
                $voGroupName = $groupRow['vo_group_name'];
                $ncGroupId = $groupRow['nc_group_id'];

                try {
                    // Perform sync using unified helper
                    $syncResult = $this->syncSingleGroupFull($voGroupId, $ncGroupId, $voGroupName, $voGroupMap);

                    $results[] = [
                        'vo_group_id' => $voGroupId,
                        'vo_group_name' => $voGroupName,
                        'nc_group_id' => $ncGroupId,
                        'status' => 'success',
                        'added' => $syncResult['added'],
                        'removed' => $syncResult['removed'],
                        'skipped' => $syncResult['skipped'],
                        'member_count' => $syncResult['member_count'],
                        'vo_member_count' => $syncResult['vo_member_count'],
                        'non_vo_member_count' => $syncResult['non_vo_member_count']
                    ];

                    $successCount++;
                } catch (\Exception $e) {
                    logger('user_vo')->error('Failed to sync group during bulk sync', [
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

                    $failureCount++;
                }
            }

            return [
                'success' => true,
                'message' => 'Bulk sync completed',
                'summary' => [
                    'total' => count($managedGroups),
                    'succeeded' => $successCount,
                    'failed' => $failureCount
                ],
                'results' => $results
            ];

        } catch (\Exception $e) {
            logger('user_vo')->error('Failed to sync all groups', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'status_code' => 500
            ];
        }
    }

    /**
     * Helper method to sync a single group with full metadata updates
     * This is extracted from AdminController for reusability
     *
     * Serializes concurrent syncs of the same group via GroupSyncLockService -
     * without it, two overlapping syncs can read different snapshots of
     * user_vo (rewritten by other concurrent logins) and the losing thread's
     * stale read can silently restore a user's membership in a group they
     * were just removed from in VO. $nonBlocking controls what happens when
     * the group is already locked: login-time sync (via syncGroupsByIds())
     * must never block, so it skips this group once and lets whichever sync
     * is already running finish; every other caller waits briefly, then
     * throws so the caller can surface a clear per-group failure.
     */
    private function syncSingleGroupFull(string $voGroupId, string $ncGroupId, string $storedVOName, array $voGroupMap, bool $nonBlocking = false): array {
        if ($nonBlocking) {
            $lockToken = $this->lockService->tryAcquire($voGroupId);
            if ($lockToken === null) {
                logger('user_vo')->debug('Skipping group sync - already in progress elsewhere', ['vo_group_id' => $voGroupId]);
                return [
                    'added' => [],
                    'removed' => [],
                    'skipped' => [],
                    'member_count' => 0,
                    'vo_member_count' => 0,
                    'non_vo_member_count' => 0,
                    'locked' => true,
                ];
            }
        } else {
            // Check existence first, before waiting: a failed acquire can't otherwise
            // tell "still locked" apart from "the group's row is simply gone" (deleted
            // mid-sync) - both look identical (0 affected rows) to the conditional
            // UPDATE, and waiting the full bound just to report the wrong reason isn't
            // worth it.
            if (!$this->lockService->groupExists($voGroupId)) {
                throw new \Exception('Group no longer exists');
            }

            $lockToken = $this->lockService->acquireWithBoundedWait($voGroupId, self::LOCK_WAIT_SECONDS);
            if ($lockToken === null) {
                throw new GroupSyncLockContentionException('Group sync already in progress, please try again shortly');
            }
        }

        try {
            return $this->syncSingleGroupFullLocked($voGroupId, $ncGroupId, $storedVOName, $voGroupMap);
        } finally {
            $this->lockService->release($voGroupId, $lockToken);
        }
    }

    /**
     * The actual sync body, only ever called with the group's sync lease held.
     */
    private function syncSingleGroupFullLocked(string $voGroupId, string $ncGroupId, string $storedVOName, array $voGroupMap): array {
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
        $skipped = [];

        // Add users who should be in the group but aren't
        foreach ($expectedVOUsernames as $username) {
            if (!in_array($username, $currentMemberIds, true)) {
                $user = $this->userManager->get($username);
                if ($user) {
                    $ncGroup->addUser($user);
                    $added[] = $username;
                } else {
                    $skipped[] = [
                        'username' => $username,
                        'reason' => 'User not found in NC'
                    ];
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
            'skipped' => $skipped,
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
