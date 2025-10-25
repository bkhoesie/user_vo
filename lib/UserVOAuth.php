<?php
/**
 * @author Nikolaus Demmel <nikolaus@nikolaus-demmel.de>
 * @copyright (c) 2023 Nikolaus Demmel <nikolaus@nikolaus-demmel.de>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the LICENSE file.
 */

declare(strict_types=1);

namespace OCA\UserVO;

use function OCP\Log\logger;
use OCA\UserVO\Base;
use OCP\IConfig;
use OCA\UserVO\Service\ApiClient;
use OCA\UserVO\Service\ConfigService;

class UserVOAuth extends Base {
    private $apiUrl;
    private $username;
    private $password;
    private $config;
    private $configService;
    private $apiClient;

    public function __construct($apiUrl = null, $username = null, $password = null, IConfig $config = null) {
        parent::__construct('user_vo');
        $this->config = $config ?? \OC::$server->getConfig();
        $this->configService = new ConfigService($this->config);
        $this->apiClient = \OC::$server->get(ApiClient::class);

        if ($apiUrl !== null && $username !== null && $password !== null) {
            // Use constructor parameters (for backward compatibility / testing)
            $this->apiUrl = $apiUrl;
            $this->username = $username;
            $this->password = $password;
            logger('user_vo')->debug('Using configuration from constructor parameters');
        } else {
            // Load configuration using ConfigService (handles precedence: config.php > admin interface)
            $configuration = $this->configService->loadConfiguration(maskPassword: false);
            $this->apiUrl = $configuration['api_url'];
            $this->username = $configuration['api_username'];
            $this->password = $configuration['api_password'];
        }

        // Validate that we have all required configuration
        if (empty($this->apiUrl) || empty($this->username) || empty($this->password)) {
            logger('user_vo')->error('UserVO configuration is incomplete. Please configure via config.php or admin interface.');
        }
    }

    /**
     * Get current configuration source
     * @return string 'config.php', 'admin_interface', or 'incomplete'
     */
    public function getConfigurationSource(): string {
        return $this->configService->getConfigurationSource();
    }

    /**
     * Get current configuration values
     * @return array
     */
    public function getCurrentConfig(): array {
        // Get masked configuration from ConfigService
        $maskedConfig = $this->configService->loadConfiguration(maskPassword: true);

        return [
            'api_url' => $this->apiUrl,
            'api_username' => $this->username,
            'api_password' => $maskedConfig['api_password'], // Already masked by ConfigService
            'source' => $this->getConfigurationSource(),
            'sources' => $this->getConfigurationSources()
        ];
    }

    /**
     * Get detailed information about where each config value comes from
     * @return array
     */
    public function getConfigurationSources(): array {
        return $this->configService->getConfigurationSources();
    }

    /**
     * Check if the provided credentials are valid and authenticate the user.
     *
     * @param string $uid      The canonical username
     * @param string $password The password
     *
     * @return bool|string The authenticated user's ID if successful, otherwise false
     */
    protected function checkCanonicalPassword($uid, $password) {
        // Perform the necessary authentication logic using Vereinonline API
        // Make API request to verify the credentials and retrieve user information
        // Return the authenticated user's ID or false

        // Example implementation:
        $token = 'A/' . $this->username . '/' . md5($this->password);

        $url = $this->apiUrl . "/?api=VerifyLogin";
        $data = [
            'user' => $uid,
            'password' => $password,
            'result' => 'id',
        ];

        $response = $this->makeRequest($url, $data, $token);

        if ($response === null) {
            logger('user_vo')->error('API request failed');
            return false;
        } elseif (is_array($response) && isset($response[0]) && $response[0] !== '') {
            // Authentication successful - store user first
            $this->storeUser($uid);

            // Fetch extended user data from VO and sync to NC
            $voUserId = $response[0];
            $voUserData = $this->fetchUserDataFromVO($voUserId);

            if ($voUserData !== null) {
                // Sync user data (display name, email, phone, metadata)
                $this->syncUserData($uid, $voUserData);

                // Sync user's group memberships (login-time group sync)
                $this->syncUserGroupsOnLogin($uid, $voUserData);
            } else {
                logger('user_vo')->warning('Failed to fetch user data from VO after successful login', [
                    'uid' => $uid,
                    'vo_user_id' => $voUserId
                ]);
                // Continue login anyway - authentication was successful
            }

            return $uid;
        } elseif (is_array($response) && isset($response['error'])) {
            $errorMessage = $response['error'];
            logger('user_vo')->error('User authentication error: ' . $errorMessage);
            return false;
        } else {
            logger('user_vo')->error('Invalid API response: ' . json_encode($response), ['app' => 'user_vo']);
            return false;
        }
    }


    /**
     * Make a request to the Vereinonline API.
     *
     * @param string $url    The API URL
     * @param array  $data   The request data
     * @param string $token  The authentication token
     *
     * @return mixed The API response
     */
    /**
     * Make API request using centralized ApiClient service
     *
     * @param string $url API endpoint URL
     * @param array $data Request payload
     * @param string $token Authorization token
     * @return array|null Response data or null on failure
     */
    private function makeRequest($url, $data, $token) {
        // Use ApiClient with throwOnError=false to return null on failure (matches original behavior)
        return $this->apiClient->makeRequest($url, $data, $token, throwOnError: false);
    }

    /**
     * Fetch extended user data from VO API
     *
     * @param string $voUserId VO user ID (from VerifyLogin result)
     * @return array|null User data or null on error. Returns array with '_error' key on specific errors.
     */
    public function fetchUserDataFromVO(string $voUserId): ?array {
        $token = 'A/' . $this->username . '/' . md5($this->password);
        $url = $this->apiUrl . "/?api=GetMember";
        $data = ['id' => $voUserId];

        $response = $this->makeRequest($url, $data, $token);

        if (!$response || isset($response['error'])) {
            logger('user_vo')->error("Failed to fetch user data from VO", [
                'vo_user_id' => $voUserId,
                'error' => $response['error'] ?? 'Unknown error'
            ]);
            return ['_error' => 'api_error', '_message' => $response['error'] ?? 'Unknown error'];
        }

        // Check if user is deleted in VO - still return data but mark as deleted
        $isDeleted = !empty($response['geloescht']) && $response['geloescht'] !== "0";
        if ($isDeleted) {
            logger('user_vo')->info("User is deleted in VO", ['vo_user_id' => $voUserId]);
        }

        // CRITICAL: Filter out users without login credentials
        if (empty($response['userlogin'])) {
            logger('user_vo')->debug("User without VO login credentials", [
                'vo_user_id' => $voUserId
            ]);
            return ['_error' => 'no_login', '_message' => 'No login credentials in VO'];
        }

        // Return normalized structure with actual VO field names
        return [
            'id' => $response['id'],                    // VO user ID
            'username' => $response['userlogin'],       // Username for NC
            'firstname' => $response['vorname'] ?? '',  // First name
            'lastname' => $response['nachname'] ?? '',  // Last name
            'email' => $response['p_email'] ?? '',      // Personal email
            'group_ids' => $response['gruppenids'] ?? '',     // Comma-separated group IDs
            'foto' => $response['foto'] ?? '',          // Photo filename
            '_deleted' => $isDeleted,                   // User marked as deleted in VO
        ];
    }

    /**
     * Fetch members from VO and create username mapping for specific NC users
     *
     * This is expensive (O(n) API calls) but optimized to stop once all target users are found.
     * Uses fuzzy matching on names to prioritize likely candidates.
     * Only needed once after upgrade to populate missing vo_user_ids for existing users.
     *
     * @param array $targetUsernames Array of NC usernames to find (lowercase)
     * @return array Map of lowercase NC username => ['vo_user_id' => ..., 'vo_username' => ...]
     */
    /**
     * Fetch all members from VereinOnline API
     *
     * @return array|null Array of members or null on failure
     */
    public function fetchAllMembers(): ?array {
        $token = 'A/' . $this->username . '/' . md5($this->password);
        $listUrl = $this->apiUrl . "/?api=GetMembers";
        $listResponse = $this->makeRequest($listUrl, [], $token);

        if (!$listResponse || !is_array($listResponse)) {
            logger('user_vo')->error("Failed to fetch members list from VO");
            return null;
        }

        return $listResponse;
    }

    /**
     * Fetch all groups from VereinOnline API
     *
     * @return array|null Array of groups or null on failure
     *     Each group: ['id' => string, 'name' => string, ...]
     */
    public function fetchAllGroups(): ?array {
        $token = 'A/' . $this->username . '/' . md5($this->password);
        $listUrl = $this->apiUrl . "/?api=GetGroups";
        $listResponse = $this->makeRequest($listUrl, [], $token);

        if (!$listResponse || !is_array($listResponse)) {
            logger('user_vo')->error("Failed to fetch groups list from VO");
            return null;
        }

        logger('user_vo')->info("Fetched groups from VO", [
            'group_count' => count($listResponse)
        ]);

        return $listResponse;
    }

    public function fetchMembersMapForUsers(array $targetUsernames): array {
        $listResponse = $this->fetchAllMembers();

        if ($listResponse === null) {
            return [];
        }

        $token = 'A/' . $this->username . '/' . md5($this->password);
        $totalMembers = count($listResponse);
        $targetCount = count($targetUsernames);
        logger('user_vo')->info("Searching for NC users in VO members", [
            'target_users' => $targetCount,
            'total_vo_members' => $totalMembers
        ]);

        // Prioritize members using fuzzy name matching
        // GetMembers returns "name" field like "Mustermann, Maximilian"
        // NC username might be "maximilian.mustermann" or "maxmustermann"
        $prioritized = [];
        $rest = [];

        foreach ($listResponse as $member) {
            $score = 0;
            $memberName = strtolower($member['name'] ?? '');

            // Extract name parts from "Lastname, Firstname" format
            $nameParts = array_map('trim', explode(',', $memberName));

            foreach ($targetUsernames as $username) {
                // Check if username contains parts of the VO name
                foreach ($nameParts as $part) {
                    if (!empty($part) && (
                        strpos($username, $part) !== false ||
                        strpos($part, $username) !== false ||
                        levenshtein(substr($username, 0, 10), substr($part, 0, 10)) <= 2
                    )) {
                        $score += 1;
                    }
                }
            }

            if ($score > 0) {
                $prioritized[] = ['member' => $member, 'score' => $score];
            } else {
                $rest[] = $member;
            }
        }

        // Sort prioritized by score (highest first)
        usort($prioritized, fn($a, $b) => $b['score'] <=> $a['score']);

        // Build search order: prioritized candidates first, then rest
        $searchOrder = array_merge(
            array_map(fn($p) => $p['member'], $prioritized),
            $rest
        );

        logger('user_vo')->info("Prioritized likely candidates using name matching", [
            'prioritized' => count($prioritized),
            'rest' => count($rest)
        ]);

        $map = [];
        $getMemberUrl = $this->apiUrl . "/?api=GetMember";
        $checked = 0;

        foreach ($searchOrder as $member) {
            // Stop early if we've found all target users
            if (count($map) >= $targetCount) {
                logger('user_vo')->info("Found all target users, stopping early", [
                    'checked' => $checked,
                    'total' => $totalMembers
                ]);
                break;
            }

            $checked++;

            // Fetch full member data to get userlogin
            $memberData = $this->makeRequest($getMemberUrl, ['id' => $member['id']], $token);

            if (!$memberData || !is_array($memberData)) {
                continue;
            }

            // Skip members without login credentials (not NC users)
            if (empty($memberData['userlogin'])) {
                continue;
            }

            // Normalize username to lowercase for case-insensitive matching
            $ncUsername = strtolower($memberData['userlogin']);

            // Only add if this is one of our target users
            if (in_array($ncUsername, $targetUsernames)) {
                $map[$ncUsername] = [
                    'vo_user_id' => $memberData['id'],
                    'vo_username' => $memberData['userlogin'], // Preserve original case
                ];
                logger('user_vo')->info("Found match", [
                    'nc_username' => $ncUsername,
                    'vo_user_id' => $memberData['id'],
                    'position' => $checked
                ]);
            }
        }

        logger('user_vo')->info("Built members map from VO", [
            'found' => count($map),
            'target' => $targetCount,
            'checked' => $checked
        ]);
        return $map;
    }

    /**
     * Synchronize user data from VO to Nextcloud
     *
     * @param string $uid NC username (lowercase canonical)
     * @param array $voUserData User data from fetchUserDataFromVO
     * @return array ['success' => bool, 'photo_error' => string|null]
     */
    public function syncUserData(string $uid, array $voUserData): array {
        try {
            // Username mismatch warning - VO username might have different case (case-insensitive comparison)
            if (strtolower($voUserData['username']) !== strtolower($uid)) {
                logger('user_vo')->warning("Username mismatch during sync", [
                    'nc_uid' => $uid,
                    'vo_username' => $voUserData['username'],
                    'vo_user_id' => $voUserData['id']
                ]);
                // Continue anyway - we use NC's canonical username
            }

            // Get NC user object
            $userManager = \OC::$server->getUserManager();
            $user = $userManager->get($uid);

            if (!$user) {
                logger('user_vo')->error("Cannot sync - user not found in NC", ['uid' => $uid]);
                return ['success' => false, 'photo_error' => null];
            }

            // Update display name (always)
            $displayName = trim($voUserData['firstname'] . ' ' . $voUserData['lastname']);
            if (!empty($displayName) && $displayName !== ' ') {
                $user->setDisplayName($displayName);
            }

            // Update email (if configured and available)
            $syncEmail = $this->config->getAppValue('user_vo', 'sync_email', 'true') === 'true';
            if ($syncEmail && !empty($voUserData['email'])) {
                $user->setSystemEMailAddress($voUserData['email']);
            }

            // Update photo (if configured and available)
            $syncPhoto = $this->config->getAppValue('user_vo', 'sync_photo', 'false') === 'true';
            $photoError = null;
            if ($syncPhoto && !empty($voUserData['foto'])) {
                // Construct photo URL from foto filename
                $photoUrl = $this->apiUrl . '/fotos/' . $voUserData['foto'];
                // Skip default anonymous photo
                if ($voUserData['foto'] !== 'anonym.gif') {
                    $photoSyncResult = $this->syncUserPhoto($uid, $photoUrl);
                    // Log photo sync failures but don't fail the whole user sync
                    if (!$photoSyncResult['success']) {
                        $photoError = $photoSyncResult['message'];
                        logger('user_vo')->warning("Photo sync failed but continuing with user sync", [
                            'uid' => $uid,
                            'photo_error' => $photoError
                        ]);
                    }
                }
            }

            // Update metadata in user_vo table
            $this->updateVOMetadata($uid, $voUserData);

            return ['success' => true, 'photo_error' => $photoError];

        } catch (\Throwable $e) {
            // Catch both Exception and Error (e.g., memory exhaustion, type errors)
            logger('user_vo')->error("Failed to sync user data", [
                'uid' => $uid,
                'error' => $e->getMessage(),
                'type' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
            return ['success' => false, 'photo_error' => null];
        }
    }


    /**
     * Download and set user avatar from URL
     *
     * @param string $uid NC username
     * @param string $photoUrl Photo URL
     * @return bool Success
     */
    protected function syncUserPhoto(string $uid, string $photoUrl): array {
        try {
            // Validate URL is from vereinonline.org
            $parsedUrl = parse_url($photoUrl);
            if (!$parsedUrl || !isset($parsedUrl['host']) ||
                !str_ends_with($parsedUrl['host'], 'vereinonline.org')) {
                logger('user_vo')->warning("Photo URL not from vereinonline.org", [
                    'uid' => $uid,
                    'url' => $photoUrl
                ]);
                return ['success' => false, 'message' => 'Invalid URL'];
            }

            // Check file size before downloading
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $photoUrl);
            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_exec($ch);
            $contentLength = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                logger('user_vo')->error("Photo URL returned non-200 status", [
                    'uid' => $uid,
                    'url' => $photoUrl,
                    'http_code' => $httpCode
                ]);
                return ['success' => false, 'message' => 'Photo not accessible (HTTP ' . $httpCode . ')'];
            }

            // Size limit: 10MB
            $maxSize = 10 * 1024 * 1024;
            if ($contentLength > $maxSize) {
                logger('user_vo')->warning("Photo file too large", [
                    'uid' => $uid,
                    'size' => $contentLength,
                    'max_size' => $maxSize
                ]);
                return ['success' => false, 'message' => 'Photo too large (' . round($contentLength / 1024 / 1024, 1) . 'MB)'];
            }

            // Download photo
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $photoUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $imageData = curl_exec($ch);
            $downloadHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($imageData === false || $downloadHttpCode !== 200) {
                logger('user_vo')->error("Failed to download photo", [
                    'uid' => $uid,
                    'url' => $photoUrl,
                    'http_code' => $downloadHttpCode
                ]);
                return ['success' => false, 'message' => 'Download failed (HTTP ' . $downloadHttpCode . ')'];
            }

            // Validate it's an image
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->buffer($imageData);
            if (!str_starts_with($mimeType, 'image/')) {
                logger('user_vo')->error("Downloaded file is not an image", [
                    'uid' => $uid,
                    'mime_type' => $mimeType
                ]);
                return ['success' => false, 'message' => 'Not an image'];
            }

            // Get user and set avatar
            $user = \OC::$server->getUserManager()->get($uid);
            if (!$user) {
                return ['success' => false, 'message' => 'User not found'];
            }

            $avatar = \OC::$server->getAvatarManager()->getAvatar($uid);

            // Create temp file for the image
            $tmpFile = tmpfile();
            fwrite($tmpFile, $imageData);
            $tmpPath = stream_get_meta_data($tmpFile)['uri'];

            // Load image
            $image = new \OCP\Image();
            if (!$image->loadFromFile($tmpPath)) {
                fclose($tmpFile);
                logger('user_vo')->error("Failed to load image from temp file", [
                    'uid' => $uid,
                    'mime_type' => $mimeType
                ]);
                return ['success' => false, 'message' => 'Failed to load image'];
            }

            // Validate image dimensions
            if (!$image->valid() || $image->width() <= 0 || $image->height() <= 0) {
                fclose($tmpFile);
                logger('user_vo')->error("Image is invalid or has invalid dimensions", [
                    'uid' => $uid,
                    'width' => $image->width(),
                    'height' => $image->height()
                ]);
                return ['success' => false, 'message' => 'Invalid image dimensions'];
            }

            // Nextcloud requires square avatars - crop to square if needed
            if ($image->width() !== $image->height()) {
                $size = min($image->width(), $image->height());
                $x = (int)(($image->width() - $size) / 2);
                $y = (int)(($image->height() - $size) / 2);
                if (!$image->crop($x, $y, $size, $size)) {
                    fclose($tmpFile);
                    logger('user_vo')->error("Failed to crop image", ['uid' => $uid]);
                    return ['success' => false, 'message' => 'Failed to crop image'];
                }
            }

            // Set avatar and verify it succeeded
            try {
                $avatar->set($image);

                // Verify avatar was actually set by checking if it exists
                if (!$avatar->exists()) {
                    fclose($tmpFile);
                    logger('user_vo')->error("Avatar->set() succeeded but avatar doesn't exist", ['uid' => $uid]);
                    return ['success' => false, 'message' => 'Avatar not set (exists check failed)'];
                }
            } catch (\Exception $e) {
                fclose($tmpFile);
                logger('user_vo')->error("Failed to set avatar", [
                    'uid' => $uid,
                    'error' => $e->getMessage()
                ]);
                return ['success' => false, 'message' => 'Failed to set avatar: ' . $e->getMessage()];
            }

            fclose($tmpFile);

            logger('user_vo')->info("Successfully synced user photo", [
                'uid' => $uid,
                'width' => $image->width(),
                'height' => $image->height()
            ]);
            return ['success' => true, 'message' => 'Synced'];

        } catch (\Throwable $e) {
            // Catch both Exception and Error (e.g., memory exhaustion)
            logger('user_vo')->error("Error syncing user photo", [
                'uid' => $uid,
                'error' => $e->getMessage(),
                'type' => get_class($e)
            ]);
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Update VO metadata in user_vo table
     *
     * @param string $uid NC username
     * @param array $voUserData User data from fetchUserDataFromVO
     */
    protected function updateVOMetadata(string $uid, array $voUserData): void {
        try {
            $db = \OC::$server->getDatabaseConnection();

            // Check if record exists
            $qb = $db->getQueryBuilder();
            $qb->select('uid')
                ->from('user_vo')
                ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
            $result = $qb->executeQuery();
            $exists = $result->fetchOne() !== false;
            $result->closeCursor();

            if ($exists) {
                // Update existing record
                $qb = $db->getQueryBuilder();
                $qb->update('user_vo')
                    ->set('vo_user_id', $qb->createNamedParameter($voUserData['id']))
                    ->set('vo_username', $qb->createNamedParameter($voUserData['username']))
                    ->set('vo_group_ids', $qb->createNamedParameter($voUserData['group_ids']))
                    ->set('last_synced', $qb->createNamedParameter(new \DateTime(), 'datetime'))
                    ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
                $qb->executeStatement();
            } else {
                // Insert new record (should not normally happen as storeUser creates the record)
                $displayName = trim($voUserData['firstname'] . ' ' . $voUserData['lastname']);
                $qb = $db->getQueryBuilder();
                $qb->insert('user_vo')
                    ->values([
                        'uid' => $qb->createNamedParameter($uid),
                        'displayname' => $qb->createNamedParameter($displayName),
                        'backend' => $qb->createNamedParameter($this->backend),
                        'vo_user_id' => $qb->createNamedParameter($voUserData['id']),
                        'vo_username' => $qb->createNamedParameter($voUserData['username']),
                        'vo_group_ids' => $qb->createNamedParameter($voUserData['group_ids']),
                        'last_synced' => $qb->createNamedParameter(new \DateTime(), 'datetime')
                    ]);
                $qb->executeStatement();
            }

            logger('user_vo')->debug("Updated VO metadata", [
                'uid' => $uid,
                'vo_user_id' => $voUserData['id'],
                'group_count' => empty($voUserData['group_ids']) ? 0 : count(explode(',', $voUserData['group_ids']))
            ]);

        } catch (\Exception $e) {
            logger('user_vo')->error("Failed to update VO metadata", [
                'uid' => $uid,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Sync user's group memberships at login time
     *
     * Performs full sync (metadata + membership) for all managed VO groups that this user
     * is or was a member of. This ensures:
     * - User is added to groups they should be in (after login vo_group_ids)
     * - User is removed from groups they shouldn't be in anymore (comparing NC membership)
     * - Group metadata is updated (display names, sync timestamps, member counts)
     *
     * @param string $uid NC username
     * @param array $voUserData User data from VO API
     */
    private function syncUserGroupsOnLogin(string $uid, array $voUserData): void {
        try {
            // Get user's NEW VO group IDs (after login)
            $newVoGroupIds = !empty($voUserData['group_ids'])
                ? array_map('trim', explode(',', $voUserData['group_ids']))
                : [];

            // Get user's OLD NC group memberships (before this login sync)
            $connection = \OC::$server->getDatabaseConnection();
            $user = \OC::$server->getUserManager()->get($uid);
            if (!$user) {
                logger('user_vo')->warning("User not found during login-time group sync", ['uid' => $uid]);
                return;
            }

            $groupManager = \OC::$server->getGroupManager();
            $userGroups = $groupManager->getUserGroups($user);
            $oldNcGroupIds = array_map(fn($g) => $g->getGID(), $userGroups);

            // Get VO group IDs for the NC groups the user is currently in
            $oldVoGroupIds = [];
            if (!empty($oldNcGroupIds)) {
                $qb = $connection->getQueryBuilder();
                $qb->select('vo_group_id')
                    ->from('user_vo_groups')
                    ->where($qb->expr()->isNotNull('nc_group_id'))
                    ->andWhere($qb->expr()->in('nc_group_id', $qb->createNamedParameter($oldNcGroupIds, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)));
                $result = $qb->executeQuery();
                $rows = $result->fetchAll();
                $result->closeCursor();
                $oldVoGroupIds = array_map(fn($r) => $r['vo_group_id'], $rows);
            }

            // Combine: sync all groups user is/was in (union of old and new)
            $allGroupIdsToSync = array_unique(array_merge($oldVoGroupIds, $newVoGroupIds));

            if (empty($allGroupIdsToSync)) {
                logger('user_vo')->debug("User has no VO groups to sync (old or new), skipping login-time group sync", [
                    'uid' => $uid
                ]);
                return;
            }

            // Use GroupSyncService to do full sync (metadata + membership)
            $groupSyncService = \OC::$server->get(\OCA\UserVO\Service\GroupSyncService::class);
            $result = $groupSyncService->syncGroupsByIds($allGroupIdsToSync);

            if ($result['success']) {
                logger('user_vo')->info("Login-time group sync completed", [
                    'uid' => $uid,
                    'synced' => $result['synced'],
                    'failed' => $result['failed'],
                    'total_groups' => count($allGroupIdsToSync),
                    'old_groups' => count($oldVoGroupIds),
                    'new_groups' => count($newVoGroupIds)
                ]);
            } else {
                logger('user_vo')->error("Login-time group sync failed", [
                    'uid' => $uid,
                    'error' => $result['error'] ?? 'Unknown error'
                ]);
            }

        } catch (\Exception $e) {
            logger('user_vo')->error("Failed to sync groups during login", [
                'uid' => $uid,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

}
