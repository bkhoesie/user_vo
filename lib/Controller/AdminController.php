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
use OCA\UserVO\Service\ConfigService;
use OCA\UserVO\Service\GroupNameHarmonizer;
use Psr\Log\LoggerInterface;

class AdminController extends Controller {

    private $connection;
    private $logger;
    private $groupManager;
    private $config;
    private $configService;
    private $groupNameHarmonizer;

    public function __construct(
        $appName,
        IRequest $request,
        IDBConnection $connection,
        LoggerInterface $logger,
        IGroupManager $groupManager,
        IConfig $config,
        ConfigService $configService,
        GroupNameHarmonizer $groupNameHarmonizer
    ) {
        parent::__construct($appName, $request);
        $this->connection = $connection;
        $this->logger = $logger;
        $this->groupManager = $groupManager;
        $this->config = $config;
        $this->configService = $configService;
        $this->groupNameHarmonizer = $groupNameHarmonizer;
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
    private function makeApiRequest($url, $data, $token) {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: ' . $token,
        ]);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_TIMEOUT, 10); // 10 second timeout
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5); // 5 second connection timeout

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);

        curl_close($curl);

        if ($response === false) {
            throw new \Exception('API request failed: ' . $error);
        }

        if ($httpCode === 401 || $httpCode === 403) {
            throw new \Exception('Authentication failed (HTTP ' . $httpCode . ')');
        }

        if ($httpCode !== 200) {
            throw new \Exception('API request returned HTTP ' . $httpCode);
        }

        return json_decode($response, true);
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

        $this->config->setAppValue('user_vo', 'enable_nightly_sync', $enabled ? 'true' : 'false');

        return new JSONResponse([
            'success' => true,
            'message' => 'Nightly sync setting saved successfully.'
        ]);
    }

    /**
     * Get nightly sync status
     */
    public function getNightlySyncStatus() {
        $enabled = $this->config->getAppValue('user_vo', 'enable_nightly_sync', 'false') === 'true';
        $lastRun = $this->config->getAppValue('user_vo', 'nightly_sync_last_run', '');
        $lastStatus = $this->config->getAppValue('user_vo', 'nightly_sync_last_status', 'never');
        $lastError = $this->config->getAppValue('user_vo', 'nightly_sync_last_error', '');
        $lastSummary = $this->config->getAppValue('user_vo', 'nightly_sync_last_summary', '{}');

        $summary = json_decode($lastSummary, true);

        return new JSONResponse([
            'success' => true,
            'enabled' => $enabled,
            'last_run' => $lastRun ? (int)$lastRun : null,
            'last_status' => $lastStatus,
            'last_error' => $lastError,
            'last_summary' => $summary
        ]);
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

                // Get user email
                $user = \OC::$server->getUserManager()->get($uid);
                $email = $user ? $user->getSystemEMailAddress() : '';

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
                    'vo_group_ids' => $userRow['vo_group_ids'] ?: '',
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
            $configuration = $this->configService->loadConfiguration(maskPassword: false);
            $auth = new UserVOAuth(
                $configuration['api_url'],
                $configuration['api_username'],
                $configuration['api_password'],
                $this->config
            );

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

                $results[] = [
                    'uid' => $uid,
                    'vo_username' => $voUserData['username'] ?? '',
                    'vo_user_id' => $voUserId,
                    'vo_group_ids' => $voUserData['group_ids'] ?? '',
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
            $configuration = $this->configService->loadConfiguration(maskPassword: false);
            $auth = new UserVOAuth(
                $configuration['api_url'],
                $configuration['api_username'],
                $configuration['api_password'],
                $this->config
            );

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

                    if ($isDeleted) {
                        $results[] = [
                            'uid' => $uid,
                            'vo_username' => $voUserData['username'] ?? '',
                            'vo_user_id' => $voUserId,
                            'vo_group_ids' => $userData['vo_group_ids'] ?? '',
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
                            'vo_group_ids' => $userData['vo_group_ids'] ?? '',
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
            $configuration = $this->configService->loadConfiguration(maskPassword: false);
            $auth = new UserVOAuth(
                $configuration['api_url'],
                $configuration['api_username'],
                $configuration['api_password'],
                $this->config
            );

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

                    if ($isDeleted) {
                        $results[] = [
                            'uid' => $uid,
                            'vo_username' => $voUserData['username'] ?? '',
                            'vo_user_id' => $voUserId,
                            'vo_group_ids' => $userData['vo_group_ids'] ?? '',
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
                            'vo_group_ids' => $userData['vo_group_ids'] ?? '',
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

    /**
     * Helper to strip the !duplicate marker from a uid
     */
    private function stripDuplicateMarker($uid) {
        if (str_ends_with($uid, '!duplicate')) {
            return substr($uid, 0, -10);  // Correct: removes all 10 characters
        }
        return $uid;
    }

    /**
     * Scan for duplicates and return comprehensive user analysis
     */
    public function scanDuplicates() {
        try {
            // Get all users from oc_accounts
            $accountsQuery = $this->connection->getQueryBuilder();
            $accountsQuery->select('uid')
                ->from('accounts')
                ->orderBy('uid');
            $accountsResult = $accountsQuery->executeQuery();
            $allAccountUsers = $accountsResult->fetchAll();
            $accountsResult->closeCursor();

            // Get all user_vo entries (to determine exposure status)
            $userVoQuery = $this->connection->getQueryBuilder();
            $userVoQuery->select('uid', 'displayname')
                ->from('user_vo')
                ->where($userVoQuery->expr()->eq('backend', $userVoQuery->createNamedParameter('user_vo')));
            $userVoResult = $userVoQuery->executeQuery();
            $userVoEntries = $userVoResult->fetchAll();
            $userVoResult->closeCursor();

            // Create map of exposed users (exist in user_vo)
            $exposedUsers = [];
            foreach ($userVoEntries as $row) {
                $exposedUsers[$row['uid']] = $row['displayname'];
            }

            // Filter accounts to only include user_vo-managed users (case-insensitive match)
            $managedUsers = [];
            foreach ($allAccountUsers as $accountUser) {
                $normalizedAccount = strtolower($accountUser['uid']);
                // Check if this account has a corresponding entry in user_vo (canonical or marked)
                foreach ($exposedUsers as $voUid => $voDisplayname) {
                    if (strtolower($this->stripDuplicateMarker($voUid)) === $normalizedAccount) {
                        $managedUsers[] = $accountUser['uid'];
                        break;
                    }
                }
            }

            // Group managed users by normalized username
            $userGroups = [];
            foreach ($managedUsers as $uid) {
                $normalizedUid = strtolower($uid);
                if (!isset($userGroups[$normalizedUid])) {
                    $userGroups[$normalizedUid] = [];
                }
                $userGroups[$normalizedUid][] = $uid;
            }

            // Prepare response arrays
            $duplicateGroups = [];
            $allPluginUsers = [];

            // Process each user group
            foreach ($userGroups as $normalizedUid => $variants) {
                // Find canonical user (exists in user_vo without !duplicate marker)
                $canonical = $this->findCanonicalUser($normalizedUid);

                $variantData = [];
                foreach ($variants as $uid) {
                    $isCanonical = ($uid === $canonical);
                    $markedUid = $uid . '!duplicate';
                    $isExposed = array_key_exists($uid, $exposedUsers) || array_key_exists($markedUid, $exposedUsers);
                    $isDuplicate = array_key_exists($markedUid, $exposedUsers);

                    // Get display name from user_vo if exposed, otherwise use uid
                    $displayname = '';
                    if (array_key_exists($uid, $exposedUsers)) {
                        $displayname = !empty($exposedUsers[$uid]) ? $exposedUsers[$uid] : $uid;
                    } elseif (array_key_exists($markedUid, $exposedUsers)) {
                        $displayname = !empty($exposedUsers[$markedUid]) ? $exposedUsers[$markedUid] : $uid;
                    } else {
                        $displayname = $uid;
                    }

                    $variantData[] = [
                        'uid' => $uid,  // Clean uid for frontend
                        'display_uid' => $uid,
                        'is_exposed' => $isExposed,
                        'is_canonical' => $isCanonical,
                        'is_marked_duplicate' => $isDuplicate,
                        'file_count' => $this->countUserFiles($uid),
                        'displayname' => $displayname,
                        'groups' => $this->getUserGroups($uid),
                        'creation_date' => $this->getUserDirectoryCreationDate($uid),
                        'is_normalized' => ($uid === $normalizedUid),
                    ];
                }

                $groupInfo = [
                    'normalized_uid' => $normalizedUid,
                    'variants' => $variantData,
                ];

                // Add to appropriate categories
                if (count($variants) > 1) {
                    // Multiple variants = duplicate group
                    $duplicateGroups[] = $groupInfo;
                }

                // Add all variants to the comprehensive list
                foreach ($variantData as $variant) {
                    $allPluginUsers[] = $variant;
                }
            }

            // Sort arrays for consistent display
            usort($duplicateGroups, function($a, $b) {
                return strcmp($a['normalized_uid'], $b['normalized_uid']);
            });
            usort($allPluginUsers, function($a, $b) {
                return strcmp($a['uid'], $b['uid']);
            });

            return new JSONResponse([
                'success' => true,
                'duplicateSets' => $duplicateGroups,
                'allPluginUsers' => $allPluginUsers,
                'summary' => [
                    'duplicateSets' => count($duplicateGroups),
                    'totalManagedUsers' => count($allPluginUsers)
                ]
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Error in scanDuplicates: ' . $e->getMessage(), ['app' => 'user_vo']);
            return new JSONResponse(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Expose a user: add to user_vo with !duplicate marker
     */
    public function exposeUser() {
        $data = $this->request->getParams();
        $uid = $data['uid'] ?? null;
        if (!$uid) {
            return new JSONResponse(['success' => false, 'error' => 'No uid provided']);
        }

        // Add !duplicate marker to the uid
        $markedUid = $uid . '!duplicate';

        // Only add if not already present
        $query = $this->connection->getQueryBuilder();
        $query->select('uid')
            ->from('user_vo')
            ->where($query->expr()->eq('uid', $query->createNamedParameter($markedUid)))
            ->andWhere($query->expr()->eq('backend', $query->createNamedParameter('user_vo')));
        $result = $query->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        if ($row) {
            return new JSONResponse(['success' => true, 'message' => 'Already exposed']);
        }

        $insert = $this->connection->getQueryBuilder();
        $insert->insert('user_vo')
            ->values([
                'uid' => $insert->createNamedParameter($markedUid),
                'backend' => $insert->createNamedParameter('user_vo'),
                'displayname' => $insert->createNamedParameter($uid),
            ]);
        $insert->executeStatement();
        return new JSONResponse(['success' => true]);
    }

    /**
     * Hide a user: remove from user_vo (unless canonical)
     */
    public function hideUser() {
        $data = $this->request->getParams();
        $uid = $data['uid'] ?? null;
        if (!$uid) {
            return new JSONResponse(['success' => false, 'error' => 'No uid provided']);
        }

        $normalizedUid = strtolower($uid);
        $canonical = $this->findCanonicalUser($normalizedUid);

        // Don't allow hiding canonical users
        if ($uid === $canonical) {
            return new JSONResponse(['success' => false, 'error' => 'Cannot hide canonical user']);
        }

        // Remove the marked duplicate entry (uid + !duplicate)
        $markedUid = $uid . '!duplicate';
        $delete = $this->connection->getQueryBuilder();
        $delete->delete('user_vo')
            ->where($delete->expr()->eq('uid', $delete->createNamedParameter($markedUid)))
            ->andWhere($delete->expr()->eq('backend', $delete->createNamedParameter('user_vo')));
        $delete->executeStatement();

        return new JSONResponse(['success' => true]);
    }



    /**
     * Find the canonical user (first one without !duplicate marker) for a normalized username
     */
    private function findCanonicalUser($normalizedUid) {
        $query = $this->connection->getQueryBuilder();
        $query->select('uid')
            ->from('user_vo')
            ->where($query->expr()->eq('backend', $query->createNamedParameter('user_vo')))
            ->andWhere($query->expr()->notLike('uid', $query->createNamedParameter('%!duplicate')))
            ->andWhere($query->expr()->eq(
                $query->func()->lower('uid'),
                $query->createNamedParameter($normalizedUid)
            ))
            ->setMaxResults(1);
        $result = $query->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return $row ? $row['uid'] : null;
    }

    /**
     * Count files for a user (in their data directory)
     */
    private function countUserFiles($uid) {
        $dataDir = \OC::$server->getConfig()->getSystemValue('datadirectory', '/var/www/html/data');
        $userDir = $dataDir . '/' . $uid . '/files';
        if (!is_dir($userDir)) {
            return 0;
        }
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($userDir, \RecursiveDirectoryIterator::SKIP_DOTS));
        $count = 0;
        foreach ($rii as $file) {
            if ($file->isFile()) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Get groups for a user
     */
    private function getUserGroups($uid) {
        $user = \OC::$server->getUserManager()->get($uid);
        if (!$user) {
            return [];
        }

        $groups = $this->groupManager->getUserGroups($user);
        $groupNames = [];
        foreach ($groups as $group) {
            $groupNames[] = $group->getGID();
        }
        return $groupNames;
    }

    /**
     * Get user directory creation date (using birth time if available, fallback to oldest file)
     */
    private function getUserDirectoryCreationDate($uid) {
        $dataDir = \OC::$server->getConfig()->getSystemValue('datadirectory', '/var/www/html/data');
        $userDir = $dataDir . '/' . $uid;

        if (!is_dir($userDir)) {
            return null;
        }

        // Try to get birth time using stat command (Linux systems)
        $birthTime = $this->getBirthTime($userDir);
        if ($birthTime !== null) {
            return date('Y-m-d H:i:s', $birthTime);
        }

        // Fallback: find the oldest file in the user directory
        $oldestTime = $this->findOldestFileTime($userDir);
        if ($oldestTime !== null) {
            return date('Y-m-d H:i:s', $oldestTime);
        }

        return null;
    }

    /**
     * Get birth time (creation time) using stat command
     */
    private function getBirthTime($path) {
        $escapedPath = escapeshellarg($path);
        $output = shell_exec("stat -c %W '$escapedPath' 2>/dev/null");

        if ($output !== null) {
            $birthTime = (int)trim($output);
            // %W returns 0 if birth time is not available
            if ($birthTime > 0) {
                return $birthTime;
            }
        }

        // Try alternative method for systems that support it
        $output = shell_exec("stat -f %B '$escapedPath' 2>/dev/null");
        if ($output !== null) {
            $birthTime = (int)trim($output);
            if ($birthTime > 0) {
                return $birthTime;
            }
        }

        return null;
    }

    /**
     * Find the oldest file in the user directory as fallback
     */
    private function findOldestFileTime($userDir) {
        $oldestTime = null;

        // Check common files that are created early
        $checkFiles = [
            $userDir . '/files',
            $userDir . '/cache',
            $userDir . '/files_trashbin'
        ];

        foreach ($checkFiles as $file) {
            if (file_exists($file)) {
                $time = filectime($file);
                if ($time !== false && ($oldestTime === null || $time < $oldestTime)) {
                    $oldestTime = $time;
                }
            }
        }

        return $oldestTime;
    }

    /**
     * Search for VO users who could log in and check their NC account status
     *
     * @param string $searchTerm Partial name to search (empty = all users, with warning)
     * @return JSONResponse
     */
    public function searchVOUsers() {
        try {
            $searchTerm = $this->request->getParam('search_term', '');
            $this->logger->info('[searchVOUsers] Starting search', ['app' => 'user_vo', 'search_term' => $searchTerm]);

            // Get UserVOAuth instance to access API methods
            $configuration = $this->configService->loadConfiguration(maskPassword: false);
            $backend = new UserVOAuth(
                $configuration['api_url'],
                $configuration['api_username'],
                $configuration['api_password']
            );

            // Fetch all VO members
            $allMembers = $backend->fetchAllMembers();

            if (!$allMembers) {
                return new JSONResponse([
                    'success' => false,
                    'error' => 'Failed to fetch members from VereinOnline'
                ], 500);
            }

            $results = [];
            $searchLower = mb_strtolower(trim($searchTerm), 'UTF-8');
            $userManager = \OC::$server->getUserManager();

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
                $voUsername = $memberData['username'];
                $ncUsername = strtolower($voUsername); // NC usernames are lowercase
                $ncUser = $userManager->get($ncUsername);

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

            return new JSONResponse([
                'success' => true,
                'users' => $results,
                'count' => count($results),
                'search_term' => $searchTerm,
                'is_all_users' => empty($searchTerm)
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to search VO users', [
                'app' => 'user_vo',
                'error' => $e->getMessage()
            ]);

            return new JSONResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a Nextcloud account for a VO user
     *
     * @return JSONResponse
     */
    public function createAccountFromVO(string $voUserId = '') {
        try {
            // Allow parameter to be passed directly or from request
            if (empty($voUserId)) {
                $voUserId = $this->request->getParam('vo_user_id', '');
            }

            if (empty($voUserId)) {
                return new JSONResponse([
                    'success' => false,
                    'error' => 'VO user ID is required'
                ], 400);
            }

            // Get UserVOAuth instance to access API methods
            $configuration = $this->configService->loadConfiguration(maskPassword: false);
            $backend = new UserVOAuth(
                $configuration['api_url'],
                $configuration['api_username'],
                $configuration['api_password']
            );

            // Fetch user data from VO
            $memberData = $backend->fetchUserDataFromVO($voUserId);

            if (!$memberData) {
                return new JSONResponse([
                    'success' => false,
                    'error' => 'Failed to fetch user data from VereinOnline'
                ], 500);
            }

            // Validate user has login credentials (normalized field name)
            if (empty($memberData['username'])) {
                return new JSONResponse([
                    'success' => false,
                    'error' => 'User does not have login credentials in VereinOnline'
                ], 400);
            }

            // Check if deleted (normalized field name)
            if (!empty($memberData['_deleted']) && $memberData['_deleted'] === true) {
                return new JSONResponse([
                    'success' => false,
                    'error' => 'User is marked as deleted in VereinOnline'
                ], 400);
            }

            $voUsername = $memberData['username'];  // Normalized field
            $ncUsername = strtolower($voUsername);

            // Check if account already exists
            $userManager = \OC::$server->getUserManager();
            if ($userManager->get($ncUsername)) {
                return new JSONResponse([
                    'success' => false,
                    'error' => "Account '$ncUsername' already exists"
                ], 409);
            }

            // Create account using existing storeUser logic
            $backend->storeUser($ncUsername);

            // Sync user data (already normalized from fetchUserDataFromVO)
            $backend->syncUserData($ncUsername, $memberData);

            $this->logger->info("Pre-provisioned NC account for VO user", [
                'app' => 'user_vo',
                'nc_username' => $ncUsername,
                'vo_user_id' => $voUserId
            ]);

            return new JSONResponse([
                'success' => true,
                'nc_username' => $ncUsername,
                'message' => "Account '$ncUsername' created successfully"
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to create account from VO', [
                'app' => 'user_vo',
                'vo_user_id' => $voUserId ?? 'unknown',
                'error' => $e->getMessage()
            ]);

            return new JSONResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk create accounts for multiple VO users
     *
     * @return JSONResponse
     */
    public function bulkCreateAccountsFromVO() {
        try {
            $voUserIds = $this->request->getParam('vo_user_ids', []);

            if (empty($voUserIds) || !is_array($voUserIds)) {
                return new JSONResponse([
                    'success' => false,
                    'error' => 'No user IDs provided'
                ], 400);
            }

            $results = [
                'created' => [],
                'skipped' => [],
                'errors' => []
            ];

            foreach ($voUserIds as $voUserId) {
                // Call createAccountFromVO directly with the voUserId parameter
                $response = $this->createAccountFromVO($voUserId);
                $data = $response->getData();

                if ($data['success']) {
                    $results['created'][] = [
                        'vo_user_id' => $voUserId,
                        'nc_username' => $data['nc_username']
                    ];
                } elseif ($response->getStatus() === 409) {
                    $results['skipped'][] = [
                        'vo_user_id' => $voUserId,
                        'reason' => 'Already exists'
                    ];
                } else {
                    $results['errors'][] = [
                        'vo_user_id' => $voUserId,
                        'error' => $data['error'] ?? 'Unknown error'
                    ];
                }
            }

            return new JSONResponse([
                'success' => true,
                'summary' => [
                    'total' => count($voUserIds),
                    'created' => count($results['created']),
                    'skipped' => count($results['skipped']),
                    'errors' => count($results['errors'])
                ],
                'results' => $results
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to bulk create accounts', [
                'app' => 'user_vo',
                'error' => $e->getMessage()
            ]);

            return new JSONResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Fetch all groups from VereinOnline API
     *
     * @return JSONResponse
     */
    public function fetchAllVOGroups() {
        try {
            // Get UserVOAuth instance to access API methods
            $configuration = $this->configService->loadConfiguration(maskPassword: false);
            $backend = new UserVOAuth(
                $configuration['api_url'],
                $configuration['api_username'],
                $configuration['api_password']
            );

            // Fetch all groups from VO
            $allGroups = $backend->fetchAllGroups();

            if (!$allGroups) {
                return new JSONResponse([
                    'success' => false,
                    'error' => 'Failed to fetch groups from VereinOnline'
                ], 500);
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

                // Harmonize the group name
                $harmonizer = new \OCA\UserVO\Service\GroupNameHarmonizer();
                $ncGroupId = $harmonizer->harmonize($voGroupName);

                // Check if this group exists in our database (is managed)
                $qb = $this->connection->getQueryBuilder();
                $qb->select('nc_group_id', 'vo_group_name', 'deleted_in_vo', 'last_synced', 'member_count', 'vo_member_count', 'non_vo_member_count')
                    ->from('user_vo_groups')
                    ->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter($voGroupId)));
                $result = $qb->executeQuery();
                $dbRow = $result->fetch();
                $result->closeCursor();

                $isManaged = ($dbRow !== false);
                $deletedInVO = $isManaged && $dbRow['deleted_in_vo'];
                $nameChanged = false;

                // Detect renamed groups: Update stored VO name if changed, and check if harmonized name differs from NC group ID
                if ($isManaged && $dbRow['vo_group_name'] !== $voGroupName) {
                    // Update the stored VO group name
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

                // Check if harmonized current VO name differs from NC group ID (indicates meaningful rename)
                // Use collision handling to determine what the NC name should be
                $currentHarmonizedName = null;
                if ($isManaged) {
                    // Get all existing NC group IDs except the current one
                    $existingNcGroupNames = [];
                    $qb2 = $this->connection->getQueryBuilder();
                    $qb2->select('nc_group_id')
                        ->from('user_vo_groups')
                        ->where($qb2->expr()->neq('vo_group_id', $qb2->createNamedParameter($voGroupId)));
                    $result2 = $qb2->executeQuery();
                    while ($row = $result2->fetch()) {
                        $existingNcGroupNames[] = $row['nc_group_id'];
                    }
                    $result2->closeCursor();

                    $currentHarmonizedName = $this->groupNameHarmonizer->harmonizeWithCollisionHandling(
                        $voGroupName,
                        $existingNcGroupNames,
                        $dbRow['nc_group_id'] // Current NC name shouldn't be treated as collision
                    );
                    $nameChanged = ($currentHarmonizedName !== $dbRow['nc_group_id']);
                }

                $results[] = [
                    'vo_group_id' => $voGroupId,
                    'vo_group_name' => $voGroupName,
                    'vo_parent_id' => $voParentId,
                    'vo_position' => $voPosition,
                    'nc_group_id' => $isManaged ? $dbRow['nc_group_id'] : $ncGroupId,
                    'expected_nc_group_id' => $currentHarmonizedName,
                    'is_managed' => $isManaged,
                    'deleted_in_vo' => $deletedInVO,
                    'name_changed' => $nameChanged,
                    'last_synced' => $isManaged ? $dbRow['last_synced'] : null,
                    'member_count' => $isManaged ? (int)$dbRow['member_count'] : null,
                    'vo_member_count' => $isManaged ? (int)$dbRow['vo_member_count'] : null,
                    'non_vo_member_count' => $isManaged ? (int)$dbRow['non_vo_member_count'] : null,
                ];
            }

            return new JSONResponse([
                'success' => true,
                'groups' => $results,
                'count' => count($results)
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch VO groups', [
                'app' => 'user_vo',
                'error' => $e->getMessage()
            ]);

            return new JSONResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Fetch managed groups from database
     *
     * @return JSONResponse
     */
    public function fetchManagedGroups() {
        try {
            // Fetch all groups from VO to detect deletions
            $configuration = $this->configService->loadConfiguration(maskPassword: false);
            $backend = new UserVOAuth(
                $configuration['api_url'],
                $configuration['api_username'],
                $configuration['api_password']
            );
            $allVOGroups = $backend->fetchAllGroups();

            // Build set of VO group IDs that exist in the API response
            $voGroupIds = [];
            if ($allVOGroups) {
                foreach ($allVOGroups as $group) {
                    if (isset($group['id'])) {
                        $voGroupIds[] = $group['id'];
                    }
                }
            }

            // Get all managed groups from database
            $qb = $this->connection->getQueryBuilder();
            $qb->select('vo_group_id', 'vo_group_name', 'nc_group_id', 'vo_parent_id', 'vo_position', 'vo_position_index',
                        'deleted_in_vo', 'last_synced', 'member_count', 'vo_member_count', 'non_vo_member_count')
                ->from('user_vo_groups')
                ->orderBy('vo_position', 'ASC');
            $result = $qb->executeQuery();
            $rows = $result->fetchAll();
            $result->closeCursor();

            // Build map of VO group ID to current VO group data for rename detection
            $voGroupsById = [];
            if ($allVOGroups) {
                foreach ($allVOGroups as $group) {
                    if (isset($group['id'])) {
                        $voGroupsById[$group['id']] = $group;
                    }
                }
            }

            // Detect deleted and renamed groups
            $updatedGroups = false;
            if ($allVOGroups) {
                foreach ($rows as $row) {
                    $voGroupId = $row['vo_group_id'];
                    $currentlyDeleted = (bool)$row['deleted_in_vo'];
                    $existsInVO = in_array($voGroupId, $voGroupIds, true);

                    // Group was deleted in VO
                    if (!$existsInVO && !$currentlyDeleted) {
                        $updateQb = $this->connection->getQueryBuilder();
                        $updateQb->update('user_vo_groups')
                            ->set('deleted_in_vo', $updateQb->createNamedParameter(1, \PDO::PARAM_INT))
                            ->where($updateQb->expr()->eq('vo_group_id', $updateQb->createNamedParameter($voGroupId)));
                        $updateQb->executeStatement();
                        $updatedGroups = true;

                        $this->logger->info('Marked group as deleted in VO', [
                            'app' => 'user_vo',
                            'vo_group_id' => $voGroupId
                        ]);
                    }

                    // Group was restored in VO
                    if ($existsInVO && $currentlyDeleted) {
                        $updateQb = $this->connection->getQueryBuilder();
                        $updateQb->update('user_vo_groups')
                            ->set('deleted_in_vo', $updateQb->createNamedParameter(0, \PDO::PARAM_INT))
                            ->where($updateQb->expr()->eq('vo_group_id', $updateQb->createNamedParameter($voGroupId)));
                        $updateQb->executeStatement();
                        $updatedGroups = true;

                        $this->logger->info('Restored group (no longer deleted in VO)', [
                            'app' => 'user_vo',
                            'vo_group_id' => $voGroupId
                        ]);
                    }

                    // Group was renamed in VO
                    if ($existsInVO && isset($voGroupsById[$voGroupId])) {
                        $currentVOName = $voGroupsById[$voGroupId]['name'] ?? '';
                        $storedVOName = $row['vo_group_name'];

                        if ($currentVOName !== '' && $currentVOName !== $storedVOName) {
                            $updateQb = $this->connection->getQueryBuilder();
                            $updateQb->update('user_vo_groups')
                                ->set('vo_group_name', $updateQb->createNamedParameter($currentVOName))
                                ->where($updateQb->expr()->eq('vo_group_id', $updateQb->createNamedParameter($voGroupId)));
                            $updateQb->executeStatement();
                            $updatedGroups = true;

                            $this->logger->info('Detected group name change in VO', [
                                'app' => 'user_vo',
                                'vo_group_id' => $voGroupId,
                                'old_name' => $storedVOName,
                                'new_name' => $currentVOName
                            ]);
                        }
                    }
                }
            }

            // Re-query if we updated any groups to get fresh values
            if ($updatedGroups) {
                $qb = $this->connection->getQueryBuilder();
                $qb->select('vo_group_id', 'vo_group_name', 'nc_group_id', 'vo_parent_id', 'vo_position', 'vo_position_index',
                            'deleted_in_vo', 'last_synced', 'member_count', 'vo_member_count', 'non_vo_member_count')
                    ->from('user_vo_groups')
                    ->orderBy('vo_position', 'ASC');
                $result = $qb->executeQuery();
                $rows = $result->fetchAll();
                $result->closeCursor();
            }

            // Build list of all existing NC group names for collision detection
            $allNcGroupNames = [];
            foreach ($rows as $row) {
                $allNcGroupNames[] = $row['nc_group_id'];
            }

            $results = [];
            foreach ($rows as $row) {
                $ncGroupId = $row['nc_group_id'];
                $voGroupName = $row['vo_group_name'];
                $voGroupId = $row['vo_group_id'];

                // Check if NC group still exists
                $ncGroupExists = $this->groupManager->groupExists($ncGroupId);

                // Check if harmonized current VO name differs from NC group ID (indicates meaningful rename)
                // Use collision handling - exclude current group's NC name from collision check
                $otherNcGroupNames = array_filter($allNcGroupNames, function($name) use ($ncGroupId) {
                    return $name !== $ncGroupId;
                });
                $currentHarmonizedName = $this->groupNameHarmonizer->harmonizeWithCollisionHandling(
                    $voGroupName,
                    $otherNcGroupNames,
                    $ncGroupId
                );
                $nameChanged = ($currentHarmonizedName !== $ncGroupId);

                $results[] = [
                    'vo_group_id' => $row['vo_group_id'],
                    'vo_group_name' => $voGroupName,
                    'nc_group_id' => $ncGroupId,
                    'expected_nc_group_id' => $currentHarmonizedName,
                    'vo_parent_id' => $row['vo_parent_id'],
                    'vo_position' => $row['vo_position'] ? (int)$row['vo_position'] : null,
                    'vo_position_index' => $row['vo_position_index'],
                    'nc_group_exists' => $ncGroupExists,
                    'is_managed' => true,
                    'deleted_in_vo' => (bool)$row['deleted_in_vo'],
                    'name_changed' => $nameChanged,
                    'last_synced' => $row['last_synced'],
                    'member_count' => (int)$row['member_count'],
                    'vo_member_count' => (int)$row['vo_member_count'],
                    'non_vo_member_count' => (int)$row['non_vo_member_count'],
                ];
            }

            return new JSONResponse([
                'success' => true,
                'groups' => $results,
                'count' => count($results)
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch managed groups', [
                'app' => 'user_vo',
                'error' => $e->getMessage()
            ]);

            return new JSONResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a single group from VereinOnline
     *
     * @return JSONResponse
     */
    public function createGroup() {
        try {
            $voGroupId = $this->request->getParam('vo_group_id', '');

            if (empty($voGroupId)) {
                return new JSONResponse([
                    'success' => false,
                    'error' => 'VO group ID is required'
                ], 400);
            }

            // Check if group is already managed
            $qb = $this->connection->getQueryBuilder();
            $qb->select('vo_group_id')
                ->from('user_vo_groups')
                ->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter($voGroupId)));
            $result = $qb->executeQuery();
            $existing = $result->fetch();
            $result->closeCursor();

            if ($existing) {
                return new JSONResponse([
                    'success' => false,
                    'error' => 'Group is already managed'
                ], 409);
            }

            // Get UserVOAuth instance to access API methods
            $configuration = $this->configService->loadConfiguration(maskPassword: false);
            $backend = new UserVOAuth(
                $configuration['api_url'],
                $configuration['api_username'],
                $configuration['api_password']
            );

            // Fetch all groups from VO to find this specific group
            $allGroups = $backend->fetchAllGroups();

            if (!$allGroups) {
                return new JSONResponse([
                    'success' => false,
                    'error' => 'Failed to fetch groups from VereinOnline'
                ], 500);
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
                return new JSONResponse([
                    'success' => false,
                    'error' => 'Group not found in VereinOnline'
                ], 404);
            }

            $voGroupName = $groupData['name'] ?? '';
            $voParentId = $groupData['parentid'] ?? null;
            $voPosition = isset($groupData['pos']) ? (int)$groupData['pos'] : null;

            // Get all existing NC group IDs for collision detection
            $existingNcGroupNames = [];
            $qb2 = $this->connection->getQueryBuilder();
            $qb2->select('nc_group_id')
                ->from('user_vo_groups');
            $result2 = $qb2->executeQuery();
            while ($row = $result2->fetch()) {
                $existingNcGroupNames[] = $row['nc_group_id'];
            }
            $result2->closeCursor();

            // Harmonize the group name with collision handling
            $ncGroupId = $this->groupNameHarmonizer->harmonizeWithCollisionHandling($voGroupName, $existingNcGroupNames);

            // Check if NC group already exists (might have been created manually or collision resolution)
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
                    return new JSONResponse([
                        'success' => false,
                        'error' => 'Failed to create Nextcloud group'
                    ], 500);
                }

                $this->logger->info("Created NC group", [
                    'app' => 'user_vo',
                    'nc_group_id' => $ncGroupId,
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
                    'vo_parent_id' => $insertQb->createNamedParameter($voParentId),
                    'vo_position' => $insertQb->createNamedParameter($voPosition, \PDO::PARAM_INT),
                    'vo_position_index' => $insertQb->createNamedParameter($positionIndex),
                    'deleted_in_vo' => $insertQb->createNamedParameter(0, \PDO::PARAM_INT),
                    'member_count' => $insertQb->createNamedParameter(0, \PDO::PARAM_INT),
                    'vo_member_count' => $insertQb->createNamedParameter(0, \PDO::PARAM_INT),
                    'non_vo_member_count' => $insertQb->createNamedParameter(0, \PDO::PARAM_INT),
                ]);
            $insertQb->executeStatement();

            return new JSONResponse([
                'success' => true,
                'message' => "Group created successfully",
                'nc_group_id' => $ncGroupId,
                'vo_group_id' => $voGroupId
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to create group', [
                'app' => 'user_vo',
                'vo_group_id' => $voGroupId ?? 'unknown',
                'error' => $e->getMessage()
            ]);

            return new JSONResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
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

    /**
     * Delete a single managed group
     *
     * @return JSONResponse
     */
    public function deleteGroup() {
        try {
            $voGroupId = $this->request->getParam('vo_group_id', '');

            if (empty($voGroupId)) {
                return new JSONResponse([
                    'success' => false,
                    'error' => 'VO group ID is required'
                ], 400);
            }

            // Get group info from database
            $qb = $this->connection->getQueryBuilder();
            $qb->select('nc_group_id', 'vo_group_name')
                ->from('user_vo_groups')
                ->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter($voGroupId)));
            $result = $qb->executeQuery();
            $groupRow = $result->fetch();
            $result->closeCursor();

            if (!$groupRow) {
                return new JSONResponse([
                    'success' => false,
                    'error' => 'Group is not managed'
                ], 404);
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

            return new JSONResponse([
                'success' => true,
                'message' => "Group deleted successfully",
                'nc_group_id' => $ncGroupId,
                'vo_group_id' => $voGroupId
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to delete group', [
                'app' => 'user_vo',
                'vo_group_id' => $voGroupId ?? 'unknown',
                'error' => $e->getMessage()
            ]);

            return new JSONResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk create groups from VereinOnline
     *
     * @return JSONResponse
     */
    public function bulkCreateGroups() {
        try {
            $voGroupIds = $this->request->getParam('vo_group_ids', []);

            if (empty($voGroupIds) || !is_array($voGroupIds)) {
                return new JSONResponse([
                    'success' => false,
                    'error' => 'No group IDs provided'
                ], 400);
            }

            $results = [
                'created' => [],
                'skipped' => [],
                'errors' => []
            ];

            // Get UserVOAuth instance to access API methods
            $configuration = $this->configService->loadConfiguration(maskPassword: false);
            $backend = new UserVOAuth(
                $configuration['api_url'],
                $configuration['api_username'],
                $configuration['api_password']
            );

            // Fetch all groups once for efficiency
            $allGroups = $backend->fetchAllGroups();

            if (!$allGroups) {
                return new JSONResponse([
                    'success' => false,
                    'error' => 'Failed to fetch groups from VereinOnline'
                ], 500);
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

                    // Find the specific group
                    if (!isset($groupMap[$voGroupId])) {
                        $results['errors'][] = [
                            'vo_group_id' => $voGroupId,
                            'error' => 'Group not found in VereinOnline'
                        ];
                        continue;
                    }

                    $groupData = $groupMap[$voGroupId];
                    $voGroupName = $groupData['name'] ?? '';
                    $voParentId = $groupData['parentid'] ?? null;
                    $voPosition = isset($groupData['pos']) ? (int)$groupData['pos'] : null;

                    // Harmonize the group name
                    $harmonizer = new \OCA\UserVO\Service\GroupNameHarmonizer();
                    $ncGroupId = $harmonizer->harmonize($voGroupName);

                    // Check if NC group already exists
                    if (!$this->groupManager->groupExists($ncGroupId)) {
                        // Create the NC group
                        $ncGroup = $this->groupManager->createGroup($ncGroupId);
                        if (!$ncGroup) {
                            $results['errors'][] = [
                                'vo_group_id' => $voGroupId,
                                'error' => 'Failed to create Nextcloud group'
                            ];
                            continue;
                        }

                        $this->logger->info("Created NC group (bulk)", [
                            'app' => 'user_vo',
                            'nc_group_id' => $ncGroupId,
                            'vo_group_id' => $voGroupId
                        ]);
                    }

                    // Calculate position index
                    $positionIndex = $this->calculatePositionIndex($voParentId, $voPosition, $allGroups);

                    // Insert record into database
                    $insertQb = $this->connection->getQueryBuilder();
                    $insertQb->insert('user_vo_groups')
                        ->values([
                            'vo_group_id' => $insertQb->createNamedParameter($voGroupId),
                            'vo_group_name' => $insertQb->createNamedParameter($voGroupName),
                            'nc_group_id' => $insertQb->createNamedParameter($ncGroupId),
                            'vo_parent_id' => $insertQb->createNamedParameter($voParentId),
                            'vo_position' => $insertQb->createNamedParameter($voPosition, \PDO::PARAM_INT),
                            'vo_position_index' => $insertQb->createNamedParameter($positionIndex),
                            'deleted_in_vo' => $insertQb->createNamedParameter(0, \PDO::PARAM_INT),
                            'member_count' => $insertQb->createNamedParameter(0, \PDO::PARAM_INT),
                            'vo_member_count' => $insertQb->createNamedParameter(0, \PDO::PARAM_INT),
                            'non_vo_member_count' => $insertQb->createNamedParameter(0, \PDO::PARAM_INT),
                        ]);
                    $insertQb->executeStatement();

                    $results['created'][] = [
                        'vo_group_id' => $voGroupId,
                        'nc_group_id' => $ncGroupId,
                        'vo_group_name' => $voGroupName
                    ];

                } catch (\Exception $e) {
                    $results['errors'][] = [
                        'vo_group_id' => $voGroupId,
                        'error' => $e->getMessage()
                    ];
                }
            }

            return new JSONResponse([
                'success' => true,
                'summary' => [
                    'total' => count($voGroupIds),
                    'created' => count($results['created']),
                    'skipped' => count($results['skipped']),
                    'errors' => count($results['errors'])
                ],
                'results' => $results
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to bulk create groups', [
                'app' => 'user_vo',
                'error' => $e->getMessage()
            ]);

            return new JSONResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk delete managed groups
     *
     * @return JSONResponse
     */
    public function bulkDeleteGroups() {
        try {
            $voGroupIds = $this->request->getParam('vo_group_ids', []);

            if (empty($voGroupIds) || !is_array($voGroupIds)) {
                return new JSONResponse([
                    'success' => false,
                    'error' => 'No group IDs provided'
                ], 400);
            }

            $results = [
                'deleted' => [],
                'not_found' => [],
                'errors' => []
            ];

            foreach ($voGroupIds as $voGroupId) {
                try {
                    // Get group info from database
                    $qb = $this->connection->getQueryBuilder();
                    $qb->select('nc_group_id', 'vo_group_name')
                        ->from('user_vo_groups')
                        ->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter($voGroupId)));
                    $result = $qb->executeQuery();
                    $groupRow = $result->fetch();
                    $result->closeCursor();

                    if (!$groupRow) {
                        $results['not_found'][] = [
                            'vo_group_id' => $voGroupId,
                            'reason' => 'Not managed'
                        ];
                        continue;
                    }

                    $ncGroupId = $groupRow['nc_group_id'];
                    $voGroupName = $groupRow['vo_group_name'];

                    // Delete the NC group (if it exists)
                    if ($this->groupManager->groupExists($ncGroupId)) {
                        $ncGroup = $this->groupManager->get($ncGroupId);
                        if ($ncGroup) {
                            $ncGroup->delete();
                            $this->logger->info("Deleted NC group (bulk)", [
                                'app' => 'user_vo',
                                'nc_group_id' => $ncGroupId,
                                'vo_group_id' => $voGroupId
                            ]);
                        }
                    }

                    // Remove from tracking table
                    $deleteQb = $this->connection->getQueryBuilder();
                    $deleteQb->delete('user_vo_groups')
                        ->where($deleteQb->expr()->eq('vo_group_id', $deleteQb->createNamedParameter($voGroupId)));
                    $deleteQb->executeStatement();

                    $results['deleted'][] = [
                        'vo_group_id' => $voGroupId,
                        'nc_group_id' => $ncGroupId,
                        'vo_group_name' => $voGroupName
                    ];

                } catch (\Exception $e) {
                    $results['errors'][] = [
                        'vo_group_id' => $voGroupId,
                        'error' => $e->getMessage()
                    ];
                }
            }

            return new JSONResponse([
                'success' => true,
                'summary' => [
                    'total' => count($voGroupIds),
                    'deleted' => count($results['deleted']),
                    'not_found' => count($results['not_found']),
                    'errors' => count($results['errors'])
                ],
                'results' => $results
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to bulk delete groups', [
                'app' => 'user_vo',
                'error' => $e->getMessage()
            ]);

            return new JSONResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Sync a single group's members from VereinOnline
     *
     * @return JSONResponse
     */
    public function syncGroup() {
        try {
            $voGroupId = $this->request->getParam('vo_group_id', '');

            if (empty($voGroupId)) {
                return new JSONResponse([
                    'success' => false,
                    'error' => 'VO group ID is required'
                ], 400);
            }

            // Check if group is managed
            $qb = $this->connection->getQueryBuilder();
            $qb->select('nc_group_id', 'vo_group_name')
                ->from('user_vo_groups')
                ->where($qb->expr()->eq('vo_group_id', $qb->createNamedParameter($voGroupId)));
            $result = $qb->executeQuery();
            $groupRow = $result->fetch();
            $result->closeCursor();

            if (!$groupRow) {
                return new JSONResponse([
                    'success' => false,
                    'error' => 'Group is not managed'
                ], 404);
            }

            $ncGroupId = $groupRow['nc_group_id'];
            $voGroupName = $groupRow['vo_group_name'];

            // Get the NC group
            $ncGroup = $this->groupManager->get($ncGroupId);
            if (!$ncGroup) {
                return new JSONResponse([
                    'success' => false,
                    'error' => 'NC group does not exist'
                ], 404);
            }

            // Get all VO users who should be in this group
            // Query user_vo table for users whose vo_group_ids contains this group ID
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
                    $user = \OC::$server->getUserManager()->get($username);
                    if ($user) {
                        $ncGroup->addUser($user);
                        $added[] = $username;
                        $this->logger->debug("Added user to group", [
                            'app' => 'user_vo',
                            'username' => $username,
                            'group' => $ncGroupId
                        ]);
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
                    $user = \OC::$server->getUserManager()->get($username);
                    if ($user && $user->getBackendClassName() === 'OCA\\UserVO\\UserVOAuth') {
                        $ncGroup->removeUser($user);
                        $removed[] = $username;
                        $this->logger->debug("Removed user from group", [
                            'app' => 'user_vo',
                            'username' => $username,
                            'group' => $ncGroupId
                        ]);
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

            // Update last_synced timestamp and member counts
            $now = new \DateTime();
            $updateQb = $this->connection->getQueryBuilder();
            $updateQb->update('user_vo_groups')
                ->set('last_synced', $updateQb->createNamedParameter($now->format('Y-m-d H:i:s')))
                ->set('member_count', $updateQb->createNamedParameter($totalCount, \PDO::PARAM_INT))
                ->set('vo_member_count', $updateQb->createNamedParameter($voCount, \PDO::PARAM_INT))
                ->set('non_vo_member_count', $updateQb->createNamedParameter($nonVoCount, \PDO::PARAM_INT))
                ->where($updateQb->expr()->eq('vo_group_id', $updateQb->createNamedParameter($voGroupId)));
            $updateQb->executeStatement();

            $this->logger->info("Synced group members", [
                'app' => 'user_vo',
                'vo_group_id' => $voGroupId,
                'nc_group_id' => $ncGroupId,
                'added' => count($added),
                'removed' => count($removed),
                'skipped' => count($skipped)
            ]);

            return new JSONResponse([
                'success' => true,
                'message' => 'Group synced successfully',
                'vo_group_id' => $voGroupId,
                'nc_group_id' => $ncGroupId,
                'vo_group_name' => $voGroupName,
                'added' => $added,
                'removed' => $removed,
                'skipped' => $skipped,
                'member_count' => $totalCount,
                'vo_member_count' => $voCount,
                'non_vo_member_count' => $nonVoCount,
                'summary' => [
                    'added' => count($added),
                    'removed' => count($removed),
                    'skipped' => count($skipped),
                    'total_members' => $totalCount,
                    'vo_members' => $voCount,
                    'non_vo_members' => $nonVoCount
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to sync group', [
                'app' => 'user_vo',
                'vo_group_id' => $voGroupId ?? 'unknown',
                'error' => $e->getMessage()
            ]);

            return new JSONResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

}
