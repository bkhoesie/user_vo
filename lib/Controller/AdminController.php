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
use Psr\Log\LoggerInterface;

class AdminController extends Controller {

    private $connection;
    private $logger;
    private $groupManager;
    private $config;
    private $configService;

    public function __construct(
        $appName,
        IRequest $request,
        IDBConnection $connection,
        LoggerInterface $logger,
        IGroupManager $groupManager,
        IConfig $config,
        ConfigService $configService
    ) {
        parent::__construct($appName, $request);
        $this->connection = $connection;
        $this->logger = $logger;
        $this->groupManager = $groupManager;
        $this->config = $config;
        $this->configService = $configService;
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
            $qb->select('uid', 'vo_user_id', 'vo_username', 'displayname', 'last_synced')
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
                    $qb->select('vo_username', 'displayname', 'last_synced')
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
                    $qb->select('vo_username', 'displayname', 'last_synced')
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

}
