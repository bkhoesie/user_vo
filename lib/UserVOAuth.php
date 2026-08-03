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
use OCA\UserVO\Service\GroupSyncLedgerService;

class UserVOAuth extends Base {
    /** Fresh cache lifetime for fetchAllGroups(allowCached: true). */
    private const GROUP_CACHE_TTL_SECONDS = 300;
    /** Longer-lived fallback used only when a live fetch fails outright. */
    private const GROUP_CACHE_STALE_TTL_SECONDS = 3600;

    private $apiUrl;
    private $username;
    private $password;
    private $config;
    private $configService;
    private $apiClient;
    private GroupSyncLedgerService $ledgerService;
    /** @var array|null Request-scoped memo for fetchAllGroups() - see that method. */
    private ?array $liveGroupsFetchMemo = null;

    public function __construct($apiUrl = null, $username = null, $password = null, IConfig $config = null) {
        parent::__construct('user_vo');
        $this->config = $config ?? \OC::$server->get(\OCP\IConfig::class);
        $this->configService = new ConfigService($this->config);
        $this->apiClient = \OC::$server->get(ApiClient::class);
        $this->ledgerService = \OC::$server->get(GroupSyncLedgerService::class);

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
     * @param bool $allowCached When true, may return a short-lived cached
     *     result instead of hitting the API, and falls back to a
     *     longer-lived stale cached result if the live fetch fails outright
     *     (rather than every metadata hiccup aborting the caller entirely).
     *     The cached value is a trimmed projection (id/name/parentid/pos
     *     only, the only fields GroupSyncService's sync body reads) - only
     *     pass true from a caller that doesn't need any other fields.
     *     Every successful live fetch refreshes the cache for subsequent
     *     cached callers, regardless of whether this call used it.
     * @return array|null Array of groups or null on failure with no usable
     *     cached fallback. Each group: ['id' => string, 'name' => string, ...]
     *     (full data on a live fetch; only the projected fields above when
     *     served from cache).
     */
    public function fetchAllGroups(bool $allowCached = false): ?array {
        // Request-scoped memo, independent of the allowCached TTL cache above:
        // a single UserVOAuth instance can end up doing a live fetchAllGroups()
        // call once per group in a loop (e.g. bulk-creating N groups, each
        // auto-syncing its own membership right after creation) - reusing the
        // first live result for the rest of this instance's lifetime avoids
        // N-1 redundant live fetches in one request without weakening the
        // "admin wants fresh data" guarantee (still fresh as of request start).
        // Only a successful fetch is memoized - a failure isn't, so a
        // transient error on one group doesn't suppress retrying for the rest.
        if ($this->liveGroupsFetchMemo !== null) {
            return $this->liveGroupsFetchMemo;
        }

        $cache = \OC::$server->get(\OCP\ICacheFactory::class)->createDistributed('user_vo-groups');
        $cacheKey = md5($this->apiUrl);

        if ($allowCached) {
            $cached = $cache->get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        $token = 'A/' . $this->username . '/' . md5($this->password);
        $listUrl = $this->apiUrl . "/?api=GetGroups";
        $listResponse = $this->makeRequest($listUrl, [], $token);

        if (!$listResponse || !is_array($listResponse)) {
            logger('user_vo')->error("Failed to fetch groups list from VO");

            if ($allowCached) {
                $stale = $cache->get($cacheKey . '-stale');
                if ($stale !== null) {
                    logger('user_vo')->info("Falling back to stale cached VO groups after a live fetch failure");
                    return $stale;
                }
            }

            return null;
        }

        logger('user_vo')->info("Fetched groups from VO", [
            'group_count' => count($listResponse)
        ]);

        $projection = array_map(fn ($group) => [
            'id' => $group['id'] ?? null,
            'name' => $group['name'] ?? null,
            'parentid' => $group['parentid'] ?? null,
            'pos' => $group['pos'] ?? null,
        ], $listResponse);
        $cache->set($cacheKey, $projection, self::GROUP_CACHE_TTL_SECONDS);
        $cache->set($cacheKey . '-stale', $projection, self::GROUP_CACHE_STALE_TTL_SECONDS);
        $this->liveGroupsFetchMemo = $listResponse;

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
            $voUsername = $voUserData['username'] ?? '';
            if (strtolower($voUsername) !== strtolower($uid)) {
                logger('user_vo')->warning("Username mismatch during sync", [
                    'nc_uid' => $uid,
                    'vo_username' => $voUsername,
                    'vo_user_id' => $voUserData['id'] ?? null
                ]);
                // Continue anyway - we use NC's canonical username
            }

            // Get NC user object
            $userManager = \OC::$server->get(\OCP\IUserManager::class);
            $user = $userManager->get($uid);

            if (!$user) {
                logger('user_vo')->error("Cannot sync - user not found in NC", ['uid' => $uid]);
                return ['success' => false, 'photo_error' => null];
            }

            // Update display name (always)
            $displayName = trim(($voUserData['firstname'] ?? '') . ' ' . ($voUserData['lastname'] ?? ''));
            if (!empty($displayName) && $displayName !== ' ') {
                $user->setDisplayName($displayName);
            }

            // Update email (if configured and available)
            $syncEmail = $this->config->getAppValue('user_vo', 'sync_email', 'true') === 'true';
            if ($syncEmail && !empty($voUserData['email'])) {
                $user->setSystemEMailAddress($voUserData['email']);
            }

            // Update photo (if configured and available)
            $syncPhoto = $this->config->getAppValue('user_vo', 'sync_photo', 'true') === 'true';
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

            $client = \OC::$server->get(\OCP\Http\Client\IClientService::class)->newClient();

            // Check file size before downloading
            try {
                $headResponse = $client->head($photoUrl, [
                    'timeout' => 10,
                    'allow_redirects' => true,
                    'http_errors' => false,
                ]);
            } catch (\Exception $e) {
                logger('user_vo')->error("Photo URL not accessible", [
                    'uid' => $uid,
                    'url' => $photoUrl,
                    'error' => $e->getMessage()
                ]);
                return ['success' => false, 'message' => 'Photo not accessible'];
            }

            $httpCode = $headResponse->getStatusCode();
            if ($httpCode !== 200) {
                logger('user_vo')->error("Photo URL returned non-200 status", [
                    'uid' => $uid,
                    'url' => $photoUrl,
                    'http_code' => $httpCode
                ]);
                return ['success' => false, 'message' => 'Photo not accessible (HTTP ' . $httpCode . ')'];
            }

            // Size limit: 10MB
            $contentLengthHeader = $headResponse->getHeader('Content-Length');
            $contentLength = $contentLengthHeader !== '' ? (int)$contentLengthHeader : 0;
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
            try {
                $downloadResponse = $client->get($photoUrl, [
                    'timeout' => 10,
                    'allow_redirects' => true,
                    'http_errors' => false,
                ]);
            } catch (\Exception $e) {
                logger('user_vo')->error("Failed to download photo", [
                    'uid' => $uid,
                    'url' => $photoUrl,
                    'error' => $e->getMessage()
                ]);
                return ['success' => false, 'message' => 'Download failed'];
            }

            $downloadHttpCode = $downloadResponse->getStatusCode();
            $imageData = $downloadResponse->getBody();

            if (!is_string($imageData) || $downloadHttpCode !== 200) {
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
            $user = \OC::$server->get(\OCP\IUserManager::class)->get($uid);
            if (!$user) {
                return ['success' => false, 'message' => 'User not found'];
            }

            $avatar = \OC::$server->get(\OCP\IAvatarManager::class)->getAvatar($uid);

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
     * Splits a comma-separated VO group ID string into a trimmed array,
     * without the '' -> [''] garbage entry explode() would otherwise produce
     * for an empty string.
     *
     * @return string[]
     */
    private static function splitGroupIds(string $groupIds): array {
        return $groupIds !== '' ? array_map('trim', explode(',', $groupIds)) : [];
    }

    /**
     * Update VO metadata in user_vo table
     *
     * Runs as one transaction, and marks the symmetric difference of the old
     * and new VO group IDs dirty in the same transaction - this is the
     * writer side of the B1 fix (see GroupSyncLedgerService and the
     * Version1005Date20260803000000 migration for the full design). The sync
     * side's membership predicate for a group G is exactly `G in
     * user.vo_group_ids` (GroupSyncService::syncSingleGroupFullLocked()) -
     * nothing else about this row participates. If G is in both OLD and NEW,
     * that predicate evaluates identically whichever snapshot a racing sync
     * read, so this write cannot make any racing sync compute a wrong answer
     * for G; only a group whose presence actually changed can be affected.
     * The diff is therefore provably sufficient, not merely an optimization -
     * marking OLD union NEW (every group this user belongs to, changed or
     * not) was tried first and reverted: since OLD == NEW in steady state,
     * the union dirties a user's entire group set on every metadata write,
     * which - via the nightly job's per-user sync step, or any lock-skipped
     * login-time sync - caused GroupSyncSweepJob to keep resyncing groups
     * with no actual membership change, including full resyncs of every
     * managed group nightly regardless of enable_nightly_group_sync.
     *
     * The touch-update-then-read (rather than a plain SELECT) takes an
     * exclusive lock on this uid's row before reading OLD: this app can't
     * rely on SELECT ... FOR UPDATE across all DBs/NC versions it supports
     * (see GroupSyncLockService's lease for the same constraint). Without
     * it, two concurrent writes for the same uid - real, via NC's ~5 minute
     * credential-token revalidation across multiple sessions/devices - could
     * each read a stale OLD value and dirty-mark based on it, potentially
     * missing a group whose predicate actually changed.
     *
     * @param string $uid NC username
     * @param array $voUserData User data from fetchUserDataFromVO
     */
    protected function updateVOMetadata(string $uid, array $voUserData): void {
        $db = \OC::$server->get(\OCP\IDBConnection::class);
        // Guard against nesting: no current caller runs this inside an outer
        // transaction, but this method must stay a safe building block if one
        // ever does.
        $ownsTransaction = !$db->inTransaction();

        try {
            if ($ownsTransaction) {
                $db->beginTransaction();
            }

            $voUserId = $voUserData['id'] ?? null;
            $voUsername = $voUserData['username'] ?? '';
            $voGroupIds = $voUserData['group_ids'] ?? '';
            $now = new \DateTime();

            // Take the row's write lock before reading OLD.
            $touchQb = $db->getQueryBuilder();
            $touchQb->update('user_vo')
                ->set('last_synced', $touchQb->createNamedParameter($now, 'datetime'))
                ->where($touchQb->expr()->eq('uid', $touchQb->createNamedParameter($uid)));
            $touchQb->executeStatement();

            // Existence check + OLD value, now stable thanks to the lock above.
            // Deliberately not using the touch-UPDATE's affected-row count as
            // the existence test: it can report 0 changed rows when
            // last_synced happens to already hold the same (whole-second)
            // value, which would wrongly route to INSERT below.
            $selectQb = $db->getQueryBuilder();
            $selectQb->select('vo_group_ids')
                ->from('user_vo')
                ->where($selectQb->expr()->eq('uid', $selectQb->createNamedParameter($uid)));
            $result = $selectQb->executeQuery();
            $row = $result->fetch();
            $result->closeCursor();

            $exists = $row !== false;
            $oldVoGroupIds = $exists ? ($row['vo_group_ids'] ?? '') : '';

            if ($exists) {
                // Update existing record
                $qb = $db->getQueryBuilder();
                $qb->update('user_vo')
                    ->set('vo_user_id', $qb->createNamedParameter($voUserId))
                    ->set('vo_username', $qb->createNamedParameter($voUsername))
                    ->set('vo_group_ids', $qb->createNamedParameter($voGroupIds))
                    ->set('last_synced', $qb->createNamedParameter($now, 'datetime'))
                    ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
                $qb->executeStatement();
            } else {
                // Insert new record (should not normally happen as storeUser creates the record)
                $displayName = trim(($voUserData['firstname'] ?? '') . ' ' . ($voUserData['lastname'] ?? ''));
                $qb = $db->getQueryBuilder();
                $qb->insert('user_vo')
                    ->values([
                        'uid' => $qb->createNamedParameter($uid),
                        'displayname' => $qb->createNamedParameter($displayName),
                        'backend' => $qb->createNamedParameter($this->backend),
                        'vo_user_id' => $qb->createNamedParameter($voUserId),
                        'vo_username' => $qb->createNamedParameter($voUsername),
                        'vo_group_ids' => $qb->createNamedParameter($voGroupIds),
                        'last_synced' => $qb->createNamedParameter($now, 'datetime')
                    ]);
                $qb->executeStatement();
            }

            $oldGroupIds = self::splitGroupIds($oldVoGroupIds);
            $newGroupIds = self::splitGroupIds($voGroupIds);
            $this->ledgerService->markDirty(array_merge(
                array_diff($oldGroupIds, $newGroupIds),
                array_diff($newGroupIds, $oldGroupIds)
            ));

            if ($ownsTransaction) {
                $db->commit();
            }

            logger('user_vo')->debug("Updated VO metadata", [
                'uid' => $uid,
                'vo_user_id' => $voUserId,
                'group_count' => empty($voGroupIds) ? 0 : count(explode(',', $voGroupIds))
            ]);

        } catch (\Exception $e) {
            if ($ownsTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
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
            $connection = \OC::$server->get(\OCP\IDBConnection::class);
            $user = \OC::$server->get(\OCP\IUserManager::class)->get($uid);
            if (!$user) {
                logger('user_vo')->warning("User not found during login-time group sync", ['uid' => $uid]);
                return;
            }

            $groupManager = \OC::$server->get(\OCP\IGroupManager::class);
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
            // Never block a login on group-sync lock contention - skip a
            // group this time if another sync already holds its lease.
            $result = $groupSyncService->syncGroupsByIds($allGroupIdsToSync, $this, nonBlocking: true);

            if ($result['success']) {
                logger('user_vo')->info("Login-time group sync completed", [
                    'uid' => $uid,
                    'synced' => $result['synced'],
                    'failed' => $result['failed'],
                    'skipped' => $result['skipped'],
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
