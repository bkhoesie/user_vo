<?php

namespace OCA\UserVO\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IConfig;
use OCA\UserVO\UserVOAuth;
use OCA\UserVO\Service\ApiClient;
use OCA\UserVO\Service\ConfigService;
use OCA\UserVO\Service\GroupNameHarmonizer;
use OCA\UserVO\Service\GroupSyncService;
use OCA\UserVO\Service\UserProvisioningService;
use OCA\UserVO\Service\UserAccountService;
use OCA\UserVO\Service\GroupManagementService;
use Psr\Log\LoggerInterface;

class AdminController extends Controller {

    private $connection;
    private $logger;
    private $groupManager;
    private $config;
    private $configService;
    private $groupNameHarmonizer;
    private $groupSyncService;
    private $apiClient;
    private $userProvisioningService;
    private $userAccountService;
    private $groupManagementService;

    public function __construct(
        $appName,
        IRequest $request,
        IDBConnection $connection,
        LoggerInterface $logger,
        IGroupManager $groupManager,
        IConfig $config,
        ConfigService $configService,
        GroupNameHarmonizer $groupNameHarmonizer,
        GroupSyncService $groupSyncService,
        ApiClient $apiClient,
        UserProvisioningService $userProvisioningService,
        UserAccountService $userAccountService,
        GroupManagementService $groupManagementService
    ) {
        parent::__construct($appName, $request);
        $this->connection = $connection;
        $this->logger = $logger;
        $this->groupManager = $groupManager;
        $this->config = $config;
        $this->configService = $configService;
        $this->groupNameHarmonizer = $groupNameHarmonizer;
        $this->groupSyncService = $groupSyncService;
        $this->apiClient = $apiClient;
        $this->userProvisioningService = $userProvisioningService;
        $this->userAccountService = $userAccountService;
        $this->groupManagementService = $groupManagementService;
    }

    /**
     * Factory method to create UserVOAuth backend instance
     *
     * Eliminates repetitive instantiation code throughout the controller.
     *
     * @return UserVOAuth Configured backend instance
     */
    private function createBackend(): UserVOAuth {
        $configuration = $this->configService->loadConfiguration(maskPassword: false);
        return new UserVOAuth(
            $configuration['api_url'],
            $configuration['api_username'],
            $configuration['api_password'],
            $this->config
        );
    }

    /**
     * Admin settings page
     */
    public function index() {
        // Get current configuration status from service
        $configStatus = $this->configService->getConfigurationStatus();

        return new TemplateResponse('user_vo', 'admin', [
            'config_status' => $configStatus
        ], 'admin');
    }

    /**
     * Helper method to get managed group information for a user
     *
     * @param string $uid Nextcloud username
     * @param string $voGroupIds Comma-separated VO group IDs
     * @return array ['count' => int, 'names' => string] Count and comma-separated display names
     */
    private function getManagedGroupsForUser(string $uid, string $voGroupIds): array {
        // If no VO group IDs, return empty
        if (empty($voGroupIds)) {
            return ['count' => 0, 'names' => ''];
        }

        // Parse VO group IDs
        $groupIds = array_filter(array_map('trim', explode(',', $voGroupIds)));
        if (empty($groupIds)) {
            return ['count' => 0, 'names' => ''];
        }

        // Query managed groups table for these VO group IDs
        $qb = $this->connection->getQueryBuilder();
        $qb->select('nc_display_name', 'vo_group_id')
            ->from('user_vo_groups')
            ->where($qb->expr()->in('vo_group_id', $qb->createNamedParameter($groupIds, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)))
            ->orderBy('nc_display_name', 'ASC');

        $result = $qb->executeQuery();
        $managedGroups = $result->fetchAll();
        $result->closeCursor();

        // Extract display names
        $displayNames = array_map(function($row) {
            return $row['nc_display_name'];
        }, $managedGroups);

        return [
            'count' => count($displayNames),
            'names' => implode(', ', $displayNames)
        ];
    }

    /**
     * Preview local user data (no API calls)
     */
    public function previewLocalUsers() {
        try {
            $results = [];

            // Get all users from user_vo table
            $qb = $this->connection->getQueryBuilder();
            $qb->select('uid', 'vo_user_id', 'vo_username', 'displayname', 'vo_group_ids', 'last_synced')
                ->from('user_vo')
                ->where($qb->expr()->eq('backend', $qb->createNamedParameter('user_vo')))
                ->orderBy('uid', 'ASC');
            $result = $qb->executeQuery();
            $users = $result->fetchAll();
            $result->closeCursor();

            foreach ($users as $userRow) {
                $uid = $userRow['uid'];

                // Skip users with !duplicate marker
                if (str_ends_with($uid, '!duplicate')) {
                    continue;
                }

                $voUserId = $userRow['vo_user_id'];
                $voUsername = $userRow['vo_username'];
                $voGroupIds = $userRow['vo_group_ids'] ?: '';

                // Get user email
                $user = \OC::$server->getUserManager()->get($uid);
                $email = $user ? $user->getSystemEMailAddress() : '';

                // Get managed groups for this user
                $managedGroupsInfo = $this->getManagedGroupsForUser($uid, $voGroupIds);

                // Check if user has a custom photo (not generated/default)
                $photoStatus = '-';
                if ($user) {
                    $avatarManager = \OC::$server->getAvatarManager();
                    try {
                        $avatar = $avatarManager->getAvatar($uid);
                        // Check if avatar is user-uploaded (not generated)
                        if ($avatar->isCustomAvatar()) {
                            $photoStatus = 'Available';
                        }
                    } catch (\Exception $e) {
                        // Avatar check failed, keep default status
                    }
                }

                $results[] = [
                    'uid' => $uid,
                    'vo_username' => $voUsername ?: '-',
                    'vo_user_id' => $voUserId ?: '-',
                    'vo_group_ids' => $voGroupIds,
                    'managed_groups_count' => $managedGroupsInfo['count'],
                    'managed_groups_names' => $managedGroupsInfo['names'],
                    'display_name' => $userRow['displayname'] ?: '-',
                    'email' => $email ?: '-',
                    'photo_status' => $photoStatus,
                    'last_synced' => $userRow['last_synced'] ?: '-',
                    'status' => 'info',
                    'message' => $voUserId ? 'Has VO ID' : 'No VO user ID'
                ];
            }

            return new JSONResponse([
                'success' => true,
                'results' => $results,
                'total' => count($results)
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Error in viewLocalData: ' . $e->getMessage(), ['app' => 'user_vo']);
            return new JSONResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Preview user data from VO API without syncing
     */
    public function previewVOUsers() {
        try {
            $results = [];

            // Get all users from user_vo table
            $qb = $this->connection->getQueryBuilder();
            $qb->select('uid', 'vo_user_id', 'displayname', 'last_synced')
                ->from('user_vo')
                ->where($qb->expr()->eq('backend', $qb->createNamedParameter('user_vo')))
                ->orderBy('uid', 'ASC');
            $result = $qb->executeQuery();
            $users = $result->fetchAll();
            $result->closeCursor();

            // Get UserVOAuth instance to fetch data from VO
            $auth = $this->createBackend();

            foreach ($users as $userRow) {
                $uid = $userRow['uid'];

                // Skip users with !duplicate marker
                if (str_ends_with($uid, '!duplicate')) {
                    continue;
                }

                $voUserId = $userRow['vo_user_id'];

                if (!$voUserId) {
                    $results[] = [
                        'uid' => $uid,
                        'vo_username' => '',
                        'vo_user_id' => '',
                        'vo_group_ids' => '',
                        'managed_groups_count' => 0,
                        'managed_groups_names' => '',
                        'display_name' => '',
                        'email' => '',
                        'photo_status' => '',
                        'last_synced' => $userRow['last_synced'] ?? null,
                        'status' => 'skipped',
                        'message' => 'No VO user ID in local database'
                    ];
                    continue;
                }

                // Fetch data from VO API
                $voUserData = $auth->fetchUserDataFromVO($voUserId);

                if ($voUserData === null) {
                    $results[] = [
                        'uid' => $uid,
                        'vo_username' => '',
                        'vo_user_id' => $voUserId,
                        'vo_group_ids' => '',
                        'managed_groups_count' => 0,
                        'managed_groups_names' => '',
                        'display_name' => '',
                        'email' => '',
                        'photo_status' => '',
                        'last_synced' => $userRow['last_synced'] ?? null,
                        'status' => 'failed',
                        'message' => 'User not found in VO'
                    ];
                    continue;
                }

                if (isset($voUserData['_error'])) {
                    $errorType = $voUserData['_error'];

                    // Sanitize API error message - don't expose internal API details
                    $message = $errorType === 'api_error' ? 'VO API error' :
                              ($errorType === 'no_login' ? 'No login credentials in VO' : 'Error fetching user data');

                    $results[] = [
                        'uid' => $uid,
                        'vo_username' => '',
                        'vo_user_id' => $voUserId,
                        'vo_group_ids' => '',
                        'managed_groups_count' => 0,
                        'managed_groups_names' => '',
                        'display_name' => '',
                        'email' => '',
                        'photo_status' => '',
                        'last_synced' => $userRow['last_synced'] ?? null,
                        'status' => 'failed',
                        'message' => $message
                    ];
                    continue;
                }

                // Get photo status
                $photoStatus = '';
                if (!empty($voUserData['foto']) && $voUserData['foto'] !== 'anonym.gif') {
                    $photoStatus = 'Available in VO';
                } else {
                    $photoStatus = '-';
                }

                // Check if user is deleted
                $isDeleted = $voUserData['_deleted'] ?? false;

                // Get managed groups for this user
                $voGroupIds = $voUserData['group_ids'] ?? '';
                $managedGroupsInfo = $this->getManagedGroupsForUser($uid, $voGroupIds);

                $results[] = [
                    'uid' => $uid,
                    'vo_username' => $voUserData['username'] ?? '',
                    'vo_user_id' => $voUserId,
                    'vo_group_ids' => $voGroupIds,
                    'managed_groups_count' => $managedGroupsInfo['count'],
                    'managed_groups_names' => $managedGroupsInfo['names'],
                    'display_name' => trim($voUserData['firstname'] . ' ' . $voUserData['lastname']),
                    'email' => $voUserData['email'] ?? '',
                    'photo_status' => $photoStatus,
                    'last_synced' => $userRow['last_synced'] ?? null,
                    'status' => $isDeleted ? 'deleted' : 'info',
                    'message' => $isDeleted ? 'User marked as deleted in VO' : 'Ready to sync'
                ];
            }

            return new JSONResponse([
                'success' => true,
                'results' => $results,
                'total' => count($results)
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Error in viewUserMetadata: ' . $e->getMessage(), ['app' => 'user_vo']);
            return new JSONResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Sync all users from VO API
     */
    public function syncFromVO() {
        try {
            $results = [];
            $successCount = 0;
            $failureCount = 0;
            $skippedCount = 0;
            $photoErrorCount = 0;

            // Get all users from user_vo table
            $qb = $this->connection->getQueryBuilder();
            $qb->select('uid', 'vo_user_id')
                ->from('user_vo')
                ->where($qb->expr()->eq('backend', $qb->createNamedParameter('user_vo')))
                ->orderBy('uid', 'ASC');
            $result = $qb->executeQuery();
            $users = $result->fetchAll();
            $result->closeCursor();

            // Get UserVOAuth instance to access sync methods
            $auth = $this->createBackend();

            // Auto-populate missing vo_user_ids from GetMembers (one-time migration after upgrade)
            $usersNeedingIds = array_filter($users, fn($u) => empty($u['vo_user_id']) && !str_ends_with($u['uid'], '!duplicate'));

            if (!empty($usersNeedingIds)) {
                $this->logger->info("Auto-populating VO user IDs for existing users", ['count' => count($usersNeedingIds)]);

                // Build list of target usernames (lowercase)
                $targetUsernames = array_map(fn($u) => strtolower($u['uid']), $usersNeedingIds);

                // Fetch members map from VO API (optimized to stop early)
                $membersMap = $auth->fetchMembersMapForUsers($targetUsernames);

                // Update database with vo_user_ids
                $populated = 0;
                foreach ($usersNeedingIds as $user) {
                    $uid = $user['uid'];
                    $uidLower = strtolower($uid);

                    if (isset($membersMap[$uidLower])) {
                        $updateQb = $this->connection->getQueryBuilder();
                        $updateQb->update('user_vo')
                            ->set('vo_user_id', $updateQb->createNamedParameter($membersMap[$uidLower]['vo_user_id']))
                            ->set('vo_username', $updateQb->createNamedParameter($membersMap[$uidLower]['vo_username']))
                            ->where($updateQb->expr()->eq('uid', $updateQb->createNamedParameter($uid)))
                            ->andWhere($updateQb->expr()->eq('backend', $updateQb->createNamedParameter('user_vo')));
                        $updateQb->executeStatement();
                        $populated++;
                    }
                }

                $this->logger->info("Auto-populated VO user IDs", ['populated' => $populated]);

                // Re-query users to get updated vo_user_ids
                $qb = $this->connection->getQueryBuilder();
                $qb->select('uid', 'vo_user_id')
                    ->from('user_vo')
                    ->where($qb->expr()->eq('backend', $qb->createNamedParameter('user_vo')))
                    ->orderBy('uid', 'ASC');
                $result = $qb->executeQuery();
                $users = $result->fetchAll();
                $result->closeCursor();
            }

            foreach ($users as $userRow) {
                $uid = $userRow['uid'];

                // Skip users with !duplicate marker (don't count them at all)
                if (str_ends_with($uid, '!duplicate')) {
                    continue;
                }

                $voUserId = $userRow['vo_user_id'];

                // If no VO user ID stored, skip (user hasn't logged in with new version yet)
                if (empty($voUserId)) {
                    $results[] = [
                        'uid' => $uid,
                        'vo_username' => '',
                        'vo_user_id' => '',
                        'vo_group_ids' => '',
                        'managed_groups_count' => 0,
                        'managed_groups_names' => '',
                        'display_name' => '',
                        'email' => '',
                        'photo_status' => '',
                        'last_synced' => '',
                        'status' => 'skipped',
                        'message' => 'No VO user ID - will sync on next login'
                    ];
                    $skippedCount++;
                    continue;
                }

                // Fetch user data from VO
                $voUserData = $auth->fetchUserDataFromVO($voUserId);

                if ($voUserData === null) {
                    $results[] = [
                        'uid' => $uid,
                        'vo_username' => '',
                        'vo_user_id' => $voUserId,
                        'vo_group_ids' => '',
                        'managed_groups_count' => 0,
                        'managed_groups_names' => '',
                        'display_name' => '',
                        'email' => '',
                        'photo_status' => '',
                        'last_synced' => '',
                        'status' => 'failed',
                        'message' => 'User not found in VO'
                    ];
                    $failureCount++;
                    continue;
                }

                // Check for specific error cases
                if (isset($voUserData['_error'])) {
                    $errorType = $voUserData['_error'];

                    // Sanitize API error message - don't expose internal API details
                    $message = $errorType === 'api_error' ? 'VO API error' :
                              ($errorType === 'no_login' ? 'No login credentials in VO' : 'Error fetching user data');

                    $results[] = [
                        'uid' => $uid,
                        'vo_username' => '',
                        'vo_user_id' => $voUserId,
                        'vo_group_ids' => '',
                        'managed_groups_count' => 0,
                        'managed_groups_names' => '',
                        'display_name' => '',
                        'email' => '',
                        'photo_status' => '',
                        'last_synced' => '',
                        'status' => 'failed',
                        'message' => $message
                    ];
                    $failureCount++;
                    continue;
                }

                // Check if user is deleted in VO
                $isDeleted = $voUserData['_deleted'] ?? false;

                // Sync user data (even for deleted users to update metadata)
                $syncResult = $auth->syncUserData($uid, $voUserData);
                $success = $syncResult['success'];
                $photoError = $syncResult['photo_error'];

                if ($success || $isDeleted) {
                    // Get last_synced from database
                    $qb = $this->connection->getQueryBuilder();
                    $qb->select('vo_username', 'displayname', 'vo_group_ids', 'last_synced')
                        ->from('user_vo')
                        ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
                    $userResult = $qb->executeQuery();
                    $userData = $userResult->fetch();
                    $userResult->closeCursor();

                    // Get user email
                    $user = \OC::$server->getUserManager()->get($uid);
                    $email = $user ? $user->getSystemEMailAddress() : '';

                    // Determine photo sync status based on actual result
                    $photoStatus = '-';
                    $syncPhoto = $this->config->getAppValue('user_vo', 'sync_photo', 'false') === 'true';
                    $hasPhoto = !empty($voUserData['foto']) && $voUserData['foto'] !== 'anonym.gif';

                    if ($syncPhoto && $hasPhoto && !$isDeleted) {
                        if ($photoError) {
                            $photoStatus = 'Sync error';
                            $photoErrorCount++;
                        } else {
                            $photoStatus = 'Synced';
                        }
                    } elseif ($hasPhoto) {
                        $photoStatus = 'Available (not synced)';
                    }

                    // Get managed groups for this user
                    $voGroupIds = $userData['vo_group_ids'] ?? '';
                    $managedGroupsInfo = $this->getManagedGroupsForUser($uid, $voGroupIds);

                    if ($isDeleted) {
                        $results[] = [
                            'uid' => $uid,
                            'vo_username' => $voUserData['username'] ?? '',
                            'vo_user_id' => $voUserId,
                            'vo_group_ids' => $voGroupIds,
                            'managed_groups_count' => $managedGroupsInfo['count'],
                            'managed_groups_names' => $managedGroupsInfo['names'],
                            'display_name' => trim($voUserData['firstname'] . ' ' . $voUserData['lastname']),
                            'email' => $voUserData['email'] ?? '',
                            'photo_status' => $photoStatus,
                            'photo_error' => $photoError,
                            'last_synced' => $userData['last_synced'] ?? null,
                            'status' => 'deleted',
                            'message' => 'User marked as deleted in VO'
                        ];
                        $failureCount++; // Count as failure for summary purposes
                    } else {
                        $results[] = [
                            'uid' => $uid,
                            'vo_username' => $userData['vo_username'] ?? '',
                            'vo_user_id' => $voUserId,
                            'vo_group_ids' => $voGroupIds,
                            'managed_groups_count' => $managedGroupsInfo['count'],
                            'managed_groups_names' => $managedGroupsInfo['names'],
                            'display_name' => $userData['displayname'] ?? '',
                            'email' => $email,
                            'photo_status' => $photoStatus,
                            'photo_error' => $photoError,
                            'last_synced' => $userData['last_synced'] ?? null,
                            'status' => 'success',
                            'message' => 'Synced successfully'
                        ];
                        $successCount++;
                    }
                } else {
                    $results[] = [
                        'uid' => $uid,
                        'vo_username' => '',
                        'vo_user_id' => $voUserId,
                        'vo_group_ids' => '',
                        'display_name' => '',
                        'email' => '',
                        'photo_status' => '',
                        'photo_error' => null,
                        'last_synced' => '',
                        'status' => 'failed',
                        'message' => 'Sync method returned false'
                    ];
                    $failureCount++;
                }
            }

            return new JSONResponse([
                'success' => true,
                'summary' => [
                    'total' => $successCount + $failureCount + $skippedCount,
                    'success' => $successCount,
                    'failed' => $failureCount,
                    'skipped' => $skippedCount,
                    'photo_errors' => $photoErrorCount
                ],
                'results' => $results
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Error in syncAllUsers: ' . $e->getMessage(), ['app' => 'user_vo']);
            return new JSONResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Sync selected users from VereinOnline
     * @NoCSRFRequired
     */
    public function syncSelectedUsers() {
        try {
            $userIds = $this->request->getParam('user_ids', []);

            if (empty($userIds) || !is_array($userIds)) {
                return new JSONResponse([
                    'success' => false,
                    'message' => 'No user IDs provided'
                ], 400);
            }

            $results = [];
            $successCount = 0;
            $failureCount = 0;
            $skippedCount = 0;
            $photoErrorCount = 0;

            // Get total count of all users in table (for "X out of Y" display)
            $qbCount = $this->connection->getQueryBuilder();
            $qbCount->select($qbCount->func()->count('*'))
                ->from('user_vo')
                ->where($qbCount->expr()->eq('backend', $qbCount->createNamedParameter('user_vo')));
            $totalInTable = (int) $qbCount->executeQuery()->fetchOne();

            // Get selected users from user_vo table
            $qb = $this->connection->getQueryBuilder();
            $qb->select('uid', 'vo_user_id')
                ->from('user_vo')
                ->where($qb->expr()->eq('backend', $qb->createNamedParameter('user_vo')))
                ->andWhere($qb->expr()->in('uid', $qb->createNamedParameter($userIds, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)))
                ->orderBy('uid', 'ASC');
            $result = $qb->executeQuery();
            $users = $result->fetchAll();
            $result->closeCursor();

            // Get UserVOAuth instance to access sync methods
            $auth = $this->createBackend();

            foreach ($users as $userRow) {
                $uid = $userRow['uid'];

                // Skip users with !duplicate marker (don't count them at all)
                if (str_ends_with($uid, '!duplicate')) {
                    continue;
                }

                $voUserId = $userRow['vo_user_id'];

                // If no VO user ID stored, skip (user hasn't logged in with new version yet)
                if (empty($voUserId)) {
                    $results[] = [
                        'uid' => $uid,
                        'vo_username' => '',
                        'vo_user_id' => '',
                        'vo_group_ids' => '',
                        'managed_groups_count' => 0,
                        'managed_groups_names' => '',
                        'display_name' => '',
                        'email' => '',
                        'photo_status' => '',
                        'last_synced' => '',
                        'status' => 'skipped',
                        'message' => 'No VO user ID - will sync on next login'
                    ];
                    $skippedCount++;
                    continue;
                }

                // Fetch user data from VO
                $voUserData = $auth->fetchUserDataFromVO($voUserId);

                if ($voUserData === null) {
                    $results[] = [
                        'uid' => $uid,
                        'vo_username' => '',
                        'vo_user_id' => $voUserId,
                        'vo_group_ids' => '',
                        'managed_groups_count' => 0,
                        'managed_groups_names' => '',
                        'display_name' => '',
                        'email' => '',
                        'photo_status' => '',
                        'last_synced' => '',
                        'status' => 'failed',
                        'message' => 'User not found in VO'
                    ];
                    $failureCount++;
                    continue;
                }

                // Check for specific error cases
                if (isset($voUserData['_error'])) {
                    $errorType = $voUserData['_error'];

                    // Sanitize API error message - don't expose internal API details
                    $message = $errorType === 'api_error' ? 'VO API error' :
                              ($errorType === 'no_login' ? 'No login credentials in VO' : 'Error fetching user data');

                    $results[] = [
                        'uid' => $uid,
                        'vo_username' => '',
                        'vo_user_id' => $voUserId,
                        'vo_group_ids' => '',
                        'managed_groups_count' => 0,
                        'managed_groups_names' => '',
                        'display_name' => '',
                        'email' => '',
                        'photo_status' => '',
                        'last_synced' => '',
                        'status' => 'failed',
                        'message' => $message
                    ];
                    $failureCount++;
                    continue;
                }

                // Check if user is deleted in VO
                $isDeleted = $voUserData['_deleted'] ?? false;

                // Sync user data (even for deleted users to update metadata)
                $syncResult = $auth->syncUserData($uid, $voUserData);
                $success = $syncResult['success'];
                $photoError = $syncResult['photo_error'];

                if ($success || $isDeleted) {
                    // Get last_synced from database
                    $qb = $this->connection->getQueryBuilder();
                    $qb->select('vo_username', 'displayname', 'vo_group_ids', 'last_synced')
                        ->from('user_vo')
                        ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
                    $userResult = $qb->executeQuery();
                    $userData = $userResult->fetch();
                    $userResult->closeCursor();

                    // Get user email
                    $user = \OC::$server->getUserManager()->get($uid);
                    $email = $user ? $user->getSystemEMailAddress() : '';

                    // Determine photo sync status based on actual result
                    $photoStatus = '-';
                    $syncPhoto = $this->config->getAppValue('user_vo', 'sync_photo', 'false') === 'true';
                    $hasPhoto = !empty($voUserData['foto']) && $voUserData['foto'] !== 'anonym.gif';

                    if ($syncPhoto && $hasPhoto && !$isDeleted) {
                        if ($photoError) {
                            $photoStatus = 'Sync error';
                            $photoErrorCount++;
                        } else {
                            $photoStatus = 'Synced';
                        }
                    } elseif ($hasPhoto) {
                        $photoStatus = 'Available (not synced)';
                    }

                    // Get managed groups for this user
                    $voGroupIds = $userData['vo_group_ids'] ?? '';
                    $managedGroupsInfo = $this->getManagedGroupsForUser($uid, $voGroupIds);

                    if ($isDeleted) {
                        $results[] = [
                            'uid' => $uid,
                            'vo_username' => $voUserData['username'] ?? '',
                            'vo_user_id' => $voUserId,
                            'vo_group_ids' => $voGroupIds,
                            'managed_groups_count' => $managedGroupsInfo['count'],
                            'managed_groups_names' => $managedGroupsInfo['names'],
                            'display_name' => trim($voUserData['firstname'] . ' ' . $voUserData['lastname']),
                            'email' => $voUserData['email'] ?? '',
                            'photo_status' => $photoStatus,
                            'photo_error' => $photoError,
                            'last_synced' => $userData['last_synced'] ?? null,
                            'status' => 'deleted',
                            'message' => 'User marked as deleted in VO'
                        ];
                        $failureCount++; // Count as failure for summary purposes
                    } else {
                        $results[] = [
                            'uid' => $uid,
                            'vo_username' => $userData['vo_username'] ?? '',
                            'vo_user_id' => $voUserId,
                            'vo_group_ids' => $voGroupIds,
                            'managed_groups_count' => $managedGroupsInfo['count'],
                            'managed_groups_names' => $managedGroupsInfo['names'],
                            'display_name' => $userData['displayname'] ?? '',
                            'email' => $email,
                            'photo_status' => $photoStatus,
                            'photo_error' => $photoError,
                            'last_synced' => $userData['last_synced'] ?? null,
                            'status' => 'success',
                            'message' => 'Synced successfully'
                        ];
                        $successCount++;
                    }
                } else {
                    $results[] = [
                        'uid' => $uid,
                        'vo_username' => '',
                        'vo_user_id' => $voUserId,
                        'vo_group_ids' => '',
                        'display_name' => '',
                        'email' => '',
                        'photo_status' => '',
                        'photo_error' => null,
                        'last_synced' => '',
                        'status' => 'failed',
                        'message' => 'Sync method returned false'
                    ];
                    $failureCount++;
                }
            }

            return new JSONResponse([
                'success' => true,
                'summary' => [
                    'total' => $successCount + $failureCount + $skippedCount,
                    'synced' => $successCount,
                    'failed' => $failureCount,
                    'skipped' => $skippedCount,
                    'photo_errors' => $photoErrorCount,
                    'total_in_table' => $totalInTable
                ],
                'results' => $results
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Error in syncSelectedUsers: ' . $e->getMessage(), ['app' => 'user_vo']);
            return new JSONResponse([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

}
