<?php
declare(strict_types=1);

namespace OCA\UserVO\Service;

use OCA\UserVO\UserVOAuth;
use OCP\IDBConnection;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;

/**
 * Service for pre-provisioning user accounts from VereinOnline
 *
 * Handles searching for VO users and creating Nextcloud accounts
 * before their first login.
 */
class UserProvisioningService {
    private IDBConnection $connection;
    private IGroupManager $groupManager;
    private LoggerInterface $logger;

    public function __construct(
        IDBConnection $connection,
        IGroupManager $groupManager,
        LoggerInterface $logger
    ) {
        $this->connection = $connection;
        $this->groupManager = $groupManager;
        $this->logger = $logger;
    }

    /**
     * Search for VO users by name or login
     *
     * @param string $searchTerm Search string (searches firstname, lastname, userlogin)
     * @param UserVOAuth $backend Configured backend instance
     * @return array Result with 'success', 'users', 'total', or 'error'
     */
    public function searchVOUsers(string $searchTerm, UserVOAuth $backend): array {
        try {
            // Fetch all members from VO API
            $allMembers = $backend->fetchAllMembers();

            if ($allMembers === null) {
                return [
                    'success' => false,
                    'error' => 'Failed to fetch members from VereinOnline API'
                ];
            }

            $results = [];
            $searchLower = mb_strtolower(trim($searchTerm), 'UTF-8');
            $userManager = \OC::$server->get(\OCP\IUserManager::class);

            // If search term contains dots, also prepare a space-separated version
            // This allows searching for usernames like "john.doe" to match "John Doe"
            $searchAlternative = str_contains($searchLower, '.') ? str_replace('.', ' ', $searchLower) : null;

            // Filter and check each member
            foreach ($allMembers as $member) {
                $memberId = $member['id'];

                // Apply search filter if provided
                // GetMembers returns 'name' field like "Lastname, Firstname"
                // Support flexible search: "Firstname Lastname" should match "Lastname, Firstname"
                if (!empty($searchTerm)) {
                    if (empty($member['name'])) {
                        continue; // Skip if no name
                    }

                    $nameLower = mb_strtolower($member['name'], 'UTF-8');

                    // Try matching with original search term
                    $searchParts = preg_split('/\s+/', $searchLower, -1, PREG_SPLIT_NO_EMPTY);
                    $allPartsMatch = true;

                    foreach ($searchParts as $part) {
                        if (mb_strpos($nameLower, $part) === false) {
                            $allPartsMatch = false;
                            break;
                        }
                    }

                    // If original didn't match and we have an alternative (dots replaced with spaces), try that
                    if (!$allPartsMatch && $searchAlternative !== null) {
                        $searchParts = preg_split('/\s+/', $searchAlternative, -1, PREG_SPLIT_NO_EMPTY);
                        $allPartsMatch = true;

                        foreach ($searchParts as $part) {
                            if (mb_strpos($nameLower, $part) === false) {
                                $allPartsMatch = false;
                                break;
                            }
                        }
                    }

                    if (!$allPartsMatch) {
                        continue; // Skip non-matching
                    }
                }

                // Fetch full member details to check userlogin and deleted status
                $memberData = $backend->fetchUserDataFromVO($memberId);

                if (!$memberData) {
                    continue; // Skip if can't fetch details
                }

                // Filter: Must have username and not be deleted
                // Note: fetchUserDataFromVO() returns normalized field 'username', not 'userlogin'
                if (empty($memberData['username'])) {
                    continue; // Skip passive members without login
                }

                if (!empty($memberData['_deleted']) && $memberData['_deleted'] !== false) {
                    continue; // Skip deleted users
                }

                // Check if NC account exists
                // For legacy users (pre-v0.2.0), the NC username may have mixed case
                // while vo_username is lowercase. Check user_vo table for existing accounts.
                $voUsername = $memberData['username'];

                // Query user_vo table to find canonical NC username for this VO user (case-insensitive)
                // Exclude !duplicate markers to only show canonical users
                $qb = $this->connection->getQueryBuilder();
                $qb->select('uid')
                    ->from('user_vo')
                    ->where($qb->expr()->eq(
                        $qb->func()->lower('vo_username'),
                        $qb->createNamedParameter(strtolower($voUsername))
                    ))
                    ->andWhere($qb->expr()->notLike('uid', $qb->createNamedParameter('%!duplicate')))
                    ->setMaxResults(1);
                $result = $qb->executeQuery();
                $row = $result->fetch();
                $result->closeCursor();

                $ncUsername = $row ? $row['uid'] : null;
                $ncUser = $ncUsername ? $userManager->get($ncUsername) : null;

                $results[] = [
                    'vo_user_id' => $memberId,
                    'vo_username' => $voUsername,
                    'vo_name' => $member['name'] ?? '', // Name from GetMembers (e.g., "Lastname, Firstname")
                    'display_name' => trim($memberData['firstname'] . ' ' . $memberData['lastname']), // Normalized fields
                    'email' => $memberData['email'] ?? '', // Normalized field
                    'nc_account_exists' => ($ncUser !== null),
                    'nc_username' => $ncUser ? $ncUser->getUID() : null,
                ];
            }

            return [
                'success' => true,
                'users' => $results,
                'total' => count($results)
            ];

        } catch (\Exception $e) {
            $this->logger->error('Error searching VO users: ' . $e->getMessage(), ['app' => 'user_vo']);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Create a Nextcloud account from VO user data
     *
     * @param string $voUserId VO user ID to create account for
     * @param UserVOAuth $backend Configured backend instance
     * @return array Result with 'success', 'username', 'message', or 'error'
     */
    public function createAccountFromVO(string $voUserId, UserVOAuth $backend): array {
        try {
            // Fetch user data from VO
            $memberData = $backend->fetchUserDataFromVO($voUserId);

            if (!$memberData) {
                return [
                    'success' => false,
                    'error' => 'Failed to fetch user data from VereinOnline'
                ];
            }

            // Validate user has login credentials (normalized field name)
            if (empty($memberData['username'])) {
                return [
                    'success' => false,
                    'error' => 'User does not have login credentials in VereinOnline'
                ];
            }

            // Check if deleted (normalized field name)
            if (!empty($memberData['_deleted']) && $memberData['_deleted'] === true) {
                return [
                    'success' => false,
                    'error' => 'User is marked as deleted in VereinOnline'
                ];
            }

            $voUsername = $memberData['username'];  // Normalized field
            $ncUsername = strtolower($voUsername);

            // Check if account already exists
            $userManager = \OC::$server->get(\OCP\IUserManager::class);
            if ($userManager->get($ncUsername)) {
                return [
                    'success' => false,
                    'error' => "Account '$ncUsername' already exists"
                ];
            }

            // Create account using existing storeUser logic
            $backend->storeUser($ncUsername);

            // Sync user data (already normalized from fetchUserDataFromVO)
            $backend->syncUserData($ncUsername, $memberData);

            // Sync group memberships (same as login-time sync)
            $groupsSynced = 0;
            $groupsFailed = 0;
            $groupSyncError = null;

            try {
                $voGroupIds = !empty($memberData['group_ids'])
                    ? array_map('trim', explode(',', $memberData['group_ids']))
                    : [];

                if (!empty($voGroupIds)) {
                    $groupSyncService = \OC::$server->get(\OCA\UserVO\Service\GroupSyncService::class);
                    $result = $groupSyncService->syncGroupsByIds($voGroupIds);

                    if ($result['success']) {
                        $groupsSynced = $result['synced'] ?? 0;
                        $groupsFailed = $result['failed'] ?? 0;

                        $this->logger->info('Synced groups during pre-provisioning', [
                            'app' => 'user_vo',
                            'nc_username' => $ncUsername,
                            'synced' => $groupsSynced,
                            'failed' => $groupsFailed
                        ]);
                    } else {
                        $groupSyncError = $result['error'] ?? 'Unknown error';
                        $this->logger->warning('Group sync failed during pre-provisioning', [
                            'app' => 'user_vo',
                            'nc_username' => $ncUsername,
                            'error' => $groupSyncError
                        ]);
                    }
                }
            } catch (\Exception $e) {
                $groupSyncError = $e->getMessage();
                $this->logger->warning('Exception during group sync in pre-provisioning', [
                    'app' => 'user_vo',
                    'nc_username' => $ncUsername,
                    'error' => $groupSyncError
                ]);
                // Don't fail provisioning - continue
            }

            $this->logger->info('Pre-provisioned NC account for VO user', [
                'app' => 'user_vo',
                'nc_username' => $ncUsername,
                'vo_user_id' => $voUserId
            ]);

            return [
                'success' => true,
                'username' => $ncUsername,
                'message' => "Account '$ncUsername' created successfully",
                'groups_synced' => $groupsSynced,
                'groups_failed' => $groupsFailed,
                'group_sync_error' => $groupSyncError
            ];

        } catch (\Exception $e) {
            $this->logger->error('Error creating account from VO: ' . $e->getMessage(), [
                'app' => 'user_vo',
                'vo_user_id' => $voUserId
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Create multiple accounts in bulk
     *
     * @param array $voUserIds Array of VO user IDs
     * @param UserVOAuth $backend Configured backend instance
     * @return array Result with categorized results (created, skipped, errors)
     */
    public function bulkCreateAccounts(array $voUserIds, UserVOAuth $backend): array {
        $results = [
            'created' => [],
            'skipped' => [],
            'errors' => []
        ];

        foreach ($voUserIds as $voUserId) {
            $result = $this->createAccountFromVO($voUserId, $backend);

            if ($result['success']) {
                $results['created'][] = [
                    'vo_user_id' => $voUserId,
                    'nc_username' => $result['username']
                ];
            } elseif (isset($result['error']) && str_contains($result['error'], 'already exists')) {
                $results['skipped'][] = [
                    'vo_user_id' => $voUserId,
                    'reason' => 'Already exists'
                ];
            } else {
                $results['errors'][] = [
                    'vo_user_id' => $voUserId,
                    'error' => $result['error'] ?? 'Unknown error'
                ];
            }
        }

        return $results;
    }

}
