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
     * Get configuration status (API endpoint)
     */
    public function getConfigurationStatus() {
        return $this->configService->getConfigurationStatus();
    }

    /**
     * Save configuration via admin interface
     */
    public function saveConfiguration() {
        $apiUrl = $this->request->getParam('api_url', '');
        $apiUsername = $this->request->getParam('api_username', '');
        $apiPassword = $this->request->getParam('api_password', '');

        // Check if we have partial configuration in database
        $existingUrl = $this->config->getAppValue('user_vo', 'api_url', '');
        $existingUsername = $this->config->getAppValue('user_vo', 'api_username', '');
        $existingPassword = $this->config->getAppValue('user_vo', 'api_password', '');

        $hasPartialConfig = !empty($existingUrl) || !empty($existingUsername) || !empty($existingPassword);
        $hasIncompleteConfig = empty($apiUrl) || empty($apiUsername) || (empty($apiPassword) && empty($existingPassword));

        if ($hasPartialConfig && $hasIncompleteConfig) {
            return new JSONResponse([
                'success' => false,
                'message' => 'Configuration is incomplete. Please provide all required fields (API URL, Username, and Password).'
            ], 400);
        }

        // Validate required fields
        if (empty($apiUrl) || empty($apiUsername) || (empty($apiPassword) && empty($existingPassword))) {
            return new JSONResponse([
                'success' => false,
                'message' => 'API URL and Username are required. Password is required if not already set.'
            ], 400);
        }

        // Validate URL format
        if (!filter_var($apiUrl, FILTER_VALIDATE_URL)) {
            return new JSONResponse([
                'success' => false,
                'message' => 'Invalid API URL format.'
            ], 400);
        }

        // Save to database using service
        $this->configService->saveConfiguration($apiUrl, $apiUsername, $apiPassword);

        $this->logger->info('UserVO configuration updated via admin interface');

        return new JSONResponse([
            'success' => true,
            'message' => 'Configuration saved successfully.'
        ]);
    }

    /**
     * Test configuration by making a test API request
     */
    public function testConfiguration() {
        $apiUrl = $this->request->getParam('api_url', '');
        $apiUsername = $this->request->getParam('api_username', '');
        $apiPassword = $this->request->getParam('api_password', '');

        // If no password provided, get the actual password from configuration
        // For admin interface mode (URL/username provided), get from database only
        // For config.php mode (no URL/username), get from full configuration
        if (empty($apiPassword)) {
            if (!empty($apiUrl) && !empty($apiUsername)) {
                // Admin interface mode - get password from database only
                $apiPassword = $this->config->getAppValue('user_vo', 'api_password', '');
            } else {
                // Config.php mode - get everything from configuration (respects precedence)
                $configuration = $this->configService->loadConfiguration(maskPassword: false);
                $apiPassword = $configuration['api_password'];

                if (empty($apiUrl)) {
                    $apiUrl = $configuration['api_url'];
                }
                if (empty($apiUsername)) {
                    $apiUsername = $configuration['api_username'];
                }
            }
        }

        // Validate required fields
        if (empty($apiUrl) || empty($apiUsername) || empty($apiPassword)) {
            return new JSONResponse([
                'success' => false,
                'message' => 'API URL, Username, and Password are required for testing.'
            ], 400);
        }

        // Validate URL format
        if (!filter_var($apiUrl, FILTER_VALIDATE_URL)) {
            return new JSONResponse([
                'success' => false,
                'message' => 'Invalid API URL format.'
            ], 400);
        }

        try {
            // Test the API connection
            $result = $this->testApiConnection($apiUrl, $apiUsername, $apiPassword);

            if ($result['success']) {
                return new JSONResponse([
                    'success' => true,
                    'message' => 'Configuration test successful: ' . $result['message']
                ]);
            } else {
                return new JSONResponse([
                    'success' => false,
                    'message' => 'Configuration test failed: ' . $result['message']
                ], 400);
            }
        } catch (\Exception $e) {
            $this->logger->error('Configuration test error: ' . $e->getMessage());
            return new JSONResponse([
                'success' => false,
                'message' => 'Configuration test failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Test API connection with provided credentials
     */
    private function testApiConnection($apiUrl, $username, $password) {
        $token = 'A/' . $username . '/' . md5($password);
        $url = rtrim($apiUrl, '/') . '/?api=VerifyLogin';

        // Test with a dummy user to verify credentials without creating real users
        $data = [
            'user' => 'test_user_that_should_not_exist',
            'password' => 'dummy_password',
            'result' => 'id',
        ];

        $response = $this->makeApiRequest($url, $data, $token);

        if ($response === null) {
            return [
                'success' => false,
                'message' => 'Unable to connect to API. Please check the API URL and network connectivity.'
            ];
        }

        // Check for authentication/authorization errors
        // VereinOnline API returns {"error":"Zugriff verweigert..."} for invalid credentials
        if (is_array($response) && isset($response['error'])) {
            $errorMessage = $response['error'];
            // Check for German "Zugriff verweigert" (Access denied) or English auth errors
            if (stripos($errorMessage, 'zugriff verweigert') !== false ||
                stripos($errorMessage, 'access denied') !== false ||
                stripos($errorMessage, 'authentication') !== false ||
                stripos($errorMessage, 'credential') !== false ||
                stripos($errorMessage, 'unauthorized') !== false) {
                return [
                    'success' => false,
                    'message' => 'Invalid API credentials. Please check your username and password.'
                ];
            }
            // Other API errors - include the message but ensure proper encoding
            return [
                'success' => false,
                'message' => 'API error: ' . $errorMessage
            ];
        }

        // If we get here, the API is reachable and credentials are valid
        // (even if the test user doesn't exist, which is expected - API returns [""] in this case)
        return [
            'success' => true,
            'message' => 'API connection successful. Credentials are valid.'
        ];
    }

    /**
     * Make a request to the VereinOnline API
     */
    /**
     * Make API request using centralized ApiClient service
     *
     * @deprecated Use $this->apiClient->makeRequest() directly
     */
    private function makeApiRequest($url, $data, $token) {
        return $this->apiClient->makeRequest($url, $data, $token, throwOnError: true);
    }

    /**
     * Clear configuration from admin interface
     */
    public function clearConfiguration() {
        // Check if config.php has settings
        $auth = new UserVOAuth(null, null, null, $this->config);
        $configSource = $auth->getConfigurationSource();

        // Clear admin interface settings from database using service
        $this->configService->clearConfiguration();

        $this->logger->info('UserVO admin interface configuration cleared');

        if ($configSource === 'config.php') {
            return new JSONResponse([
                'success' => true,
                'message' => 'Admin interface configuration cleared successfully. Note: Configuration is still active via config.php file - remove the user_backends entry from config.php to fully disable the plugin.'
            ]);
        } else {
            return new JSONResponse([
                'success' => true,
                'message' => 'Configuration cleared successfully. The plugin is now unconfigured.'
            ]);
        }
    }

    /**
     * Save user sync settings
     */
    public function saveUserSyncSettings() {
        $syncEmail = $this->request->getParam('sync_email', 'false');
        $syncPhoto = $this->request->getParam('sync_photo', 'false');

        // Convert to string 'true' or 'false' for consistency
        $emailValue = $syncEmail === 'true' || $syncEmail === true ? 'true' : 'false';
        $photoValue = $syncPhoto === 'true' || $syncPhoto === true ? 'true' : 'false';

        // Store as string 'true' or 'false' for consistency
        $this->configService->set('sync_email', $emailValue);
        $this->configService->set('sync_photo', $photoValue);

        $this->logger->info('User sync settings updated', [
            'sync_email' => $emailValue,
            'sync_photo' => $photoValue
        ]);

        return new JSONResponse([
            'success' => true,
            'message' => 'Sync settings saved successfully.'
        ]);
    }

    /**
     * Save nightly sync setting
     */
    public function saveNightlySyncSetting() {
        $enabled = $this->request->getParam('enabled', false);
        $syncType = $this->request->getParam('sync_type', 'user'); // 'user' or 'group'

        if ($syncType === 'group') {
            $this->config->setAppValue('user_vo', 'enable_nightly_group_sync', $enabled ? 'true' : 'false');
        } else {
            // User sync - also update new config key for consistency
            $this->config->setAppValue('user_vo', 'enable_nightly_user_sync', $enabled ? 'true' : 'false');
            // Keep legacy key for backward compatibility
            $this->config->setAppValue('user_vo', 'enable_nightly_sync', $enabled ? 'true' : 'false');
        }

        return new JSONResponse([
            'success' => true,
            'message' => 'Nightly sync setting saved successfully.'
        ]);
    }

    /**
     * Get nightly sync status
     */
    public function getNightlySyncStatus() {
        // Get both user and group sync settings (backward compatible)
        $legacyEnabled = $this->config->getAppValue('user_vo', 'enable_nightly_sync', 'false') === 'true';
        $userSyncEnabled = $this->config->getAppValue('user_vo', 'enable_nightly_user_sync', $legacyEnabled ? 'true' : 'false') === 'true';
        $groupSyncEnabled = $this->config->getAppValue('user_vo', 'enable_nightly_group_sync', 'false') === 'true';

        $lastRun = $this->config->getAppValue('user_vo', 'nightly_sync_last_run', '');
        $lastStatus = $this->config->getAppValue('user_vo', 'nightly_sync_last_status', 'never');
        $lastError = $this->config->getAppValue('user_vo', 'nightly_sync_last_error', '');
        $lastSummary = $this->config->getAppValue('user_vo', 'nightly_sync_last_summary', '{}');

        $summary = json_decode($lastSummary, true);

        return new JSONResponse([
            'success' => true,
            'user_sync_enabled' => $userSyncEnabled,
            'group_sync_enabled' => $groupSyncEnabled,
            'last_run' => $lastRun ? (int)$lastRun : null,
            'last_status' => $lastStatus,
            'last_error' => $lastError,
            'last_summary' => $summary
        ]);
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
