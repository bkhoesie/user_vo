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
 *
 * @psalm-import-type GroupSyncSingleSuccess from GroupSyncService
 */
class GroupManagementService {
    private IDBConnection $connection;
    private IGroupManager $groupManager;
    private GroupNameHarmonizer $groupNameHarmonizer;
    private LoggerInterface $logger;
    private GroupSyncService $groupSyncService;

    public function __construct(
        IDBConnection $connection,
        IGroupManager $groupManager,
        GroupNameHarmonizer $groupNameHarmonizer,
        LoggerInterface $logger,
        GroupSyncService $groupSyncService
    ) {
        $this->connection = $connection;
        $this->groupManager = $groupManager;
        $this->groupNameHarmonizer = $groupNameHarmonizer;
        $this->logger = $logger;
        $this->groupSyncService = $groupSyncService;
    }

    /**
     * Fetch all groups from VereinOnline with managed status
     *
     * @param UserVOAuth $backend Backend instance for API access
     * @return array Result with success, groups array, count, or error
     */
    public function fetchAllVOGroups(UserVOAuth $backend): array {
        try {
            // Fetch all groups from VO
            $allGroups = $backend->fetchAllGroups();

            if (!$allGroups) {
                return [
                    'success' => false,
                    'error' => 'Failed to fetch groups from VereinOnline'
                ];
            }

            // Build set of VO group IDs that exist in the API response
            $voGroupIds = [];
            foreach ($allGroups as $group) {
                if (isset($group['id'])) {
                    $voGroupIds[] = $group['id'];
                }
            }

            // Detect deleted groups: managed groups in DB but not in VO API response
            // Also restore groups: managed groups that were marked deleted but are now back in VO
            $qb = $this->connection->getQueryBuilder();
            $qb->select('vo_group_id', 'deleted_in_vo')
                ->from('user_vo_groups');
            $result = $qb->executeQuery();
            $managedGroups = $result->fetchAll();
            $result->closeCursor();

            foreach ($managedGroups as $managedGroup) {
                $voGroupId = $managedGroup['vo_group_id'];
                $currentlyDeleted = (bool)$managedGroup['deleted_in_vo'];
                $existsInVO = in_array($voGroupId, $voGroupIds, true);

                // Group was deleted in VO (exists in DB but not in API)
                if (!$existsInVO && !$currentlyDeleted) {
                    $updateQb = $this->connection->getQueryBuilder();
                    $updateQb->update('user_vo_groups')
                        ->set('deleted_in_vo', $updateQb->createNamedParameter(1, \PDO::PARAM_INT))
                        ->where($updateQb->expr()->eq('vo_group_id', $updateQb->createNamedParameter($voGroupId)));
                    $updateQb->executeStatement();

                    $this->logger->info('Marked group as deleted in VO', [
                        'app' => 'user_vo',
                        'vo_group_id' => $voGroupId
                    ]);
                }

                // Group was restored in VO (was marked deleted but now exists in API)
                if ($existsInVO && $currentlyDeleted) {
                    $updateQb = $this->connection->getQueryBuilder();
                    $updateQb->update('user_vo_groups')
                        ->set('deleted_in_vo', $updateQb->createNamedParameter(0, \PDO::PARAM_INT))
                        ->where($updateQb->expr()->eq('vo_group_id', $updateQb->createNamedParameter($voGroupId)));
                    $updateQb->executeStatement();

                    $this->logger->info('Restored group (no longer deleted in VO)', [
                        'app' => 'user_vo',
                        'vo_group_id' => $voGroupId
                    ]);
                }
            }

            // For each group, determine if it's already managed (created in NC)
            $results = [];
            foreach ($allGroups as $group) {
                $voGroupId = $group['id'] ?? null;
                $voGroupName = $group['name'] ?? '';
                $voParentId = $group['parentid'] ?? null;
                $voPosition = isset($group['pos']) ? (int)$group['pos'] : null;

                if (!$voGroupId) {
                    continue; // Skip groups without ID
                }

                // Check if this group exists in our database (is managed)
                $qb = $this->connection->getQueryBuilder();
                $qb->select('nc_group_id', 'nc_display_name', 'vo_group_name', 'deleted_in_vo', 'last_synced', 'member_count', 'vo_member_count', 'non_vo_member_count')
                    ->from('user_vo_groups')
                    ->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter($voGroupId)));
                $result = $qb->executeQuery();
                $dbRow = $result->fetch();
                $result->closeCursor();

                $isManaged = ($dbRow !== false);
                $deletedInVO = $isManaged && $dbRow['deleted_in_vo'];

                // Update stored VO name if changed in VO
                if ($isManaged && $dbRow['vo_group_name'] !== $voGroupName) {
                    $updateQb = $this->connection->getQueryBuilder();
                    $updateQb->update('user_vo_groups')
                        ->set('vo_group_name', $updateQb->createNamedParameter($voGroupName))
                        ->where($updateQb->expr()->eq('vo_group_id', $updateQb->createNamedParameter($voGroupId)));
                    $updateQb->executeStatement();

                    $this->logger->info('Detected group name change in VO', [
                        'app' => 'user_vo',
                        'vo_group_id' => $voGroupId,
                        'old_name' => $dbRow['vo_group_name'],
                        'new_name' => $voGroupName
                    ]);
                }

                $results[] = [
                    'vo_group_id' => $voGroupId,
                    'vo_group_name' => $voGroupName,
                    'vo_parent_id' => $voParentId,
                    'vo_position' => $voPosition,
                    'nc_group_id' => $isManaged ? $dbRow['nc_group_id'] : null,
                    'nc_display_name' => $isManaged ? $dbRow['nc_display_name'] : null,
                    'is_managed' => $isManaged,
                    'deleted_in_vo' => $deletedInVO,
                    'last_synced' => $isManaged ? $dbRow['last_synced'] : null,
                    'member_count' => $isManaged ? (int)$dbRow['member_count'] : null,
                    'vo_member_count' => $isManaged ? (int)$dbRow['vo_member_count'] : null,
                    'non_vo_member_count' => $isManaged ? (int)$dbRow['non_vo_member_count'] : null,
                ];
            }

            return [
                'success' => true,
                'groups' => $results,
                'count' => count($results)
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch VO groups', [
                'app' => 'user_vo',
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Fetch managed groups from database
     *
     * @param UserVOAuth $backend Backend instance for API access (to detect deletions)
     * @return array Result with success, groups array, count, or error
     */
    public function fetchManagedGroups(UserVOAuth $backend): array {
        try {
            // Fetch all groups from VO to detect deletions
            $allVOGroups = $backend->fetchAllGroups();
            $voGroupIds = [];
            if ($allVOGroups) {
                foreach ($allVOGroups as $group) {
                    if (isset($group['id'])) {
                        $voGroupIds[] = $group['id'];
                    }
                }
            }

            // Update deleted_in_vo status for all managed groups
            $qb = $this->connection->getQueryBuilder();
            $qb->select('vo_group_id', 'deleted_in_vo')
                ->from('user_vo_groups');
            $result = $qb->executeQuery();
            $managedGroups = $result->fetchAll();
            $result->closeCursor();

            foreach ($managedGroups as $managedGroup) {
                $voGroupId = $managedGroup['vo_group_id'];
                $currentlyDeleted = (bool)$managedGroup['deleted_in_vo'];
                $existsInVO = in_array($voGroupId, $voGroupIds, true);

                // Mark as deleted if no longer in VO
                if (!$existsInVO && !$currentlyDeleted) {
                    $updateQb = $this->connection->getQueryBuilder();
                    $updateQb->update('user_vo_groups')
                        ->set('deleted_in_vo', $updateQb->createNamedParameter(1, \PDO::PARAM_INT))
                        ->where($updateQb->expr()->eq('vo_group_id', $updateQb->createNamedParameter($voGroupId)));
                    $updateQb->executeStatement();
                }

                // Restore if back in VO
                if ($existsInVO && $currentlyDeleted) {
                    $updateQb = $this->connection->getQueryBuilder();
                    $updateQb->update('user_vo_groups')
                        ->set('deleted_in_vo', $updateQb->createNamedParameter(0, \PDO::PARAM_INT))
                        ->where($updateQb->expr()->eq('vo_group_id', $updateQb->createNamedParameter($voGroupId)));
                    $updateQb->executeStatement();
                }
            }

            // Now fetch all managed groups with updated status
            $qb = $this->connection->getQueryBuilder();
            $qb->select('*')
                ->from('user_vo_groups')
                ->orderBy('vo_position_index', 'ASC');
            $result = $qb->executeQuery();
            $groups = $result->fetchAll();
            $result->closeCursor();

            // Format results
            $results = [];
            foreach ($groups as $group) {
                $results[] = [
                    'vo_group_id' => $group['vo_group_id'],
                    'vo_group_name' => $group['vo_group_name'],
                    'nc_group_id' => $group['nc_group_id'],
                    'nc_display_name' => $group['nc_display_name'],
                    'vo_parent_id' => $group['vo_parent_id'],
                    'vo_position' => (int)$group['vo_position'],
                    'vo_position_index' => $group['vo_position_index'],
                    'deleted_in_vo' => (bool)$group['deleted_in_vo'],
                    'last_synced' => $group['last_synced'],
                    'member_count' => (int)$group['member_count'],
                    'vo_member_count' => (int)$group['vo_member_count'],
                    'non_vo_member_count' => (int)$group['non_vo_member_count'],
                    'is_managed' => true,  // All groups from this endpoint are managed
                ];
            }

            return [
                'success' => true,
                'groups' => $results,
                'count' => count($results)
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch managed groups', [
                'app' => 'user_vo',
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Bulk create multiple Nextcloud groups from VereinOnline groups
     *
     * Optimized to fetch all groups once from VO API instead of N times.
     *
     * @param array $voGroupIds Array of VO group IDs
     * @param UserVOAuth $backend Backend instance for API access
     * @return array Result with created, skipped, errors arrays
     */
    public function bulkCreateGroups(array $voGroupIds, UserVOAuth $backend): array {
        $results = [
            'created' => [],
            'skipped' => [],
            'errors' => []
        ];

        if (empty($voGroupIds)) {
            return $results;
        }

        // Fetch all groups once for efficiency (key optimization!)
        $allGroups = $backend->fetchAllGroups();

        if (!$allGroups) {
            // If we can't fetch groups, all operations fail
            foreach ($voGroupIds as $voGroupId) {
                $results['errors'][] = [
                    'vo_group_id' => $voGroupId,
                    'error' => 'Failed to fetch groups from VereinOnline'
                ];
            }
            return $results;
        }

        // Build a map for quick lookup
        $groupMap = [];
        foreach ($allGroups as $group) {
            if (isset($group['id'])) {
                $groupMap[$group['id']] = $group;
            }
        }

        foreach ($voGroupIds as $voGroupId) {
            try {
                // Check if already managed
                $qb = $this->connection->getQueryBuilder();
                $qb->select('vo_group_id')
                    ->from('user_vo_groups')
                    ->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter($voGroupId)));
                $result = $qb->executeQuery();
                $existing = $result->fetch();
                $result->closeCursor();

                if ($existing) {
                    $results['skipped'][] = [
                        'vo_group_id' => $voGroupId,
                        'reason' => 'Already managed'
                    ];
                    continue;
                }

                // Find the specific group in our pre-fetched data
                if (!isset($groupMap[$voGroupId])) {
                    $results['errors'][] = [
                        'vo_group_id' => $voGroupId,
                        'error' => 'Group not found in VereinOnline'
                    ];
                    continue;
                }

                $groupData = $groupMap[$voGroupId];

                // Use shared creation logic
                $createResult = $this->createGroupFromData($voGroupId, $groupData, $allGroups);

                if ($createResult['success']) {
                    $createdEntry = [
                        'vo_group_id' => $voGroupId,
                        'nc_group_id' => $createResult['nc_group_id'],
                        'vo_group_name' => $createResult['vo_group_name']
                    ];

                    // Auto-sync group members after creation, matching single createGroup()
                    try {
                        $syncResult = $this->groupSyncService->syncSingleGroupById($voGroupId, $backend);
                        $createdEntry['synced'] = $syncResult['success'];
                        if (!$syncResult['success']) {
                            $createdEntry['sync_error'] = $syncResult['error'] ?? 'Unknown sync error';
                            $this->logger->warning('Group created but auto-sync failed (bulk)', [
                                'app' => 'user_vo',
                                'vo_group_id' => $voGroupId,
                                'sync_error' => $createdEntry['sync_error']
                            ]);
                        }
                    } catch (\Exception $e) {
                        // Sync failed but group still created - log error but don't fail the batch
                        $createdEntry['synced'] = false;
                        $createdEntry['sync_error'] = $e->getMessage();
                        $this->logger->error('Exception during group auto-sync (bulk)', [
                            'app' => 'user_vo',
                            'vo_group_id' => $voGroupId,
                            'error' => $e->getMessage()
                        ]);
                    }

                    $results['created'][] = $createdEntry;
                } else {
                    $results['errors'][] = [
                        'vo_group_id' => $voGroupId,
                        'error' => $createResult['error']
                    ];
                }

            } catch (\Exception $e) {
                $this->logger->error('Error creating group in bulk operation', [
                    'app' => 'user_vo',
                    'vo_group_id' => $voGroupId,
                    'error' => $e->getMessage()
                ]);

                $results['errors'][] = [
                    'vo_group_id' => $voGroupId,
                    'error' => $e->getMessage()
                ];
            }
        }

        return $results;
    }

    /**
     * Bulk delete groups
     *
     * Note: Unlike bulk create, this doesn't fetch from VO API - we just need
     * to delete from NC and our database based on vo_group_id.
     *
     * @param array $voGroupIds Array of VO group IDs to delete
     * @return array Result with deleted, errors arrays
     */
    public function bulkDeleteGroups(array $voGroupIds): array {
        $results = [
            'deleted' => [],
            'errors' => []
        ];

        foreach ($voGroupIds as $voGroupId) {
            $result = $this->deleteGroup($voGroupId);

            if ($result['success']) {
                $results['deleted'][] = [
                    'vo_group_id' => $voGroupId,
                    'nc_group_id' => $result['nc_group_id'],
                    'vo_group_name' => $result['vo_group_name']
                ];
            } else {
                $results['errors'][] = [
                    'vo_group_id' => $voGroupId,
                    'error' => $result['error']
                ];
            }
        }

        return $results;
    }

    /**
     * Core logic for creating a group from VO data
     *
     * @param string $voGroupId VO group ID
     * @param array $groupData Group data from VO API
     * @param array $allGroups All groups from VO (for position calculation)
     * @return array Result with success, nc_group_id, vo_group_name, or error
     */
    private function createGroupFromData(string $voGroupId, array $groupData, array $allGroups): array {
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
                    'error' => 'Failed to create Nextcloud group'
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
        try {
            $insertQb->executeStatement();
        } catch (\OCP\DB\Exception $e) {
            if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                throw $e;
            }
            // Lost a race with a concurrent create for the same VO group - the other
            // request's row wins; the NC group we may have just created above is picked
            // up and adopted by the "already exists" branch on any later retry.
            return [
                'success' => false,
                'error' => 'Group is already managed',
                'status_code' => 409
            ];
        }

        return [
            'success' => true,
            'nc_group_id' => $ncGroupId,
            'vo_group_id' => $voGroupId,
            'vo_group_name' => $voGroupName
        ];
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

        // Use shared creation logic
        $result = $this->createGroupFromData($voGroupId, $groupData, $allGroups);

        // Add status codes for HTTP responses
        if (!$result['success']) {
            $result['status_code'] = 500;
        } else {
            // Auto-sync group members after successful creation
            try {
                $this->logger->info('Auto-syncing group members after creation', [
                    'app' => 'user_vo',
                    'vo_group_id' => $voGroupId,
                    'nc_group_id' => $result['nc_group_id']
                ]);

                $syncResult = $this->groupSyncService->syncSingleGroupById($voGroupId, $backend);

                if ($syncResult['success']) {
                    /** @var GroupSyncSingleSuccess $syncResult */
                    // Merge sync results into response
                    $result['synced'] = true;
                    $result['sync_summary'] = $syncResult['summary'];
                    $result['message'] = "Group created and synced successfully";

                    $this->logger->info('Group auto-sync successful', [
                        'app' => 'user_vo',
                        'vo_group_id' => $voGroupId,
                        'added' => count($syncResult['added']),
                        'removed' => count($syncResult['removed']),
                        'total_members' => $syncResult['summary']['total_members']
                    ]);
                } else {
                    // Sync failed but group still created - log warning
                    $this->logger->warning('Group created but auto-sync failed', [
                        'app' => 'user_vo',
                        'vo_group_id' => $voGroupId,
                        'sync_error' => $syncResult['error'] ?? 'Unknown error'
                    ]);

                    $result['synced'] = false;
                    $result['sync_error'] = $syncResult['error'] ?? 'Unknown sync error';
                    $result['message'] = "Group created successfully, but member sync failed";
                }
            } catch (\Exception $e) {
                // Sync failed but group still created - log error but don't fail request
                $this->logger->error('Exception during group auto-sync', [
                    'app' => 'user_vo',
                    'vo_group_id' => $voGroupId,
                    'error' => $e->getMessage()
                ]);

                $result['synced'] = false;
                $result['sync_error'] = $e->getMessage();
                $result['message'] = "Group created successfully, but member sync encountered an error";
            }
        }

        return $result;
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
        // (PHP's empty() already treats '0' as empty, so that's covered here too)
        if (empty($voParentId)) {
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
