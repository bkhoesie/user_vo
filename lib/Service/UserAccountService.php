<?php
declare(strict_types=1);

namespace OCA\UserVO\Service;

use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Service for user account management operations
 *
 * Handles duplicate user detection, account metadata retrieval,
 * and user account exposure/hiding operations.
 */
class UserAccountService {
    private IDBConnection $connection;
    private IGroupManager $groupManager;
    private IConfig $config;
    private LoggerInterface $logger;

    public function __construct(
        IDBConnection $connection,
        IGroupManager $groupManager,
        IConfig $config,
        LoggerInterface $logger
    ) {
        $this->connection = $connection;
        $this->groupManager = $groupManager;
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Scan for duplicate user accounts and analyze all plugin-managed users
     *
     * @return array Result with duplicate groups and all users
     */
    public function scanDuplicates(): array {
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

        return [
            'success' => true,
            'duplicateSets' => $duplicateGroups,
            'allPluginUsers' => $allPluginUsers,
            'summary' => [
                'duplicateSets' => count($duplicateGroups),
                'totalManagedUsers' => count($allPluginUsers)
            ]
        ];
    }

    /**
     * Expose a user by adding to user_vo with !duplicate marker
     *
     * @param string $uid User ID to expose
     * @return array Result with success status
     */
    public function exposeUser(string $uid): array {
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
            return ['success' => true, 'message' => 'Already exposed'];
        }

        $insert = $this->connection->getQueryBuilder();
        $insert->insert('user_vo')
            ->values([
                'uid' => $insert->createNamedParameter($markedUid),
                'backend' => $insert->createNamedParameter('user_vo'),
                'displayname' => $insert->createNamedParameter($uid),
            ]);
        $insert->executeStatement();

        return ['success' => true];
    }

    /**
     * Hide a user by removing from user_vo (unless canonical)
     *
     * @param string $uid User ID to hide
     * @return array Result with success status or error
     */
    public function hideUser(string $uid): array {
        $normalizedUid = strtolower($uid);
        $canonical = $this->findCanonicalUser($normalizedUid);

        // Don't allow hiding canonical users
        if ($uid === $canonical) {
            return ['success' => false, 'error' => 'Cannot hide canonical user'];
        }

        // Remove the marked duplicate entry (uid + !duplicate)
        $markedUid = $uid . '!duplicate';
        $delete = $this->connection->getQueryBuilder();
        $delete->delete('user_vo')
            ->where($delete->expr()->eq('uid', $delete->createNamedParameter($markedUid)))
            ->andWhere($delete->expr()->eq('backend', $delete->createNamedParameter('user_vo')));
        $delete->executeStatement();

        return ['success' => true];
    }

    /**
     * Find the canonical user (first one without !duplicate marker) for a normalized username
     *
     * @param string $normalizedUid Normalized (lowercase) username
     * @return string|null Canonical username or null if not found
     */
    public function findCanonicalUser(string $normalizedUid): ?string {
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
     *
     * @param string $uid User ID
     * @return int Number of files
     */
    public function countUserFiles(string $uid): int {
        $dataDir = $this->config->getSystemValue('datadirectory', '/var/www/html/data');
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
     *
     * @param string $uid User ID
     * @return array Array of arrays with 'gid' and 'display_name'
     */
    public function getUserGroups(string $uid): array {
        $user = \OC::$server->get(\OCP\IUserManager::class)->get($uid);
        if (!$user) {
            return [];
        }

        $groups = $this->groupManager->getUserGroups($user);
        $groupData = [];
        foreach ($groups as $group) {
            $groupData[] = [
                'gid' => $group->getGID(),
                'display_name' => $group->getDisplayName()
            ];
        }
        return $groupData;
    }

    /**
     * Get user directory creation date (using birth time if available, fallback to oldest file)
     *
     * @param string $uid User ID
     * @return string|null Creation date in Y-m-d H:i:s format or null
     */
    public function getUserDirectoryCreationDate(string $uid): ?string {
        $dataDir = $this->config->getSystemValue('datadirectory', '/var/www/html/data');
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
     * Strip !duplicate marker from username
     *
     * @param string $uid Username possibly with !duplicate marker
     * @return string Username without marker
     */
    private function stripDuplicateMarker(string $uid): string {
        if (str_ends_with($uid, '!duplicate')) {
            return substr($uid, 0, -10);  // Removes all 10 characters
        }
        return $uid;
    }

    /**
     * Get birth time (creation time) using stat command
     *
     * @param string $path Directory path
     * @return int|null Birth time as unix timestamp or null
     */
    private function getBirthTime(string $path): ?int {
        $escapedPath = escapeshellarg($path);
        $output = shell_exec("stat -c %W $escapedPath 2>/dev/null");

        if ($output !== null) {
            $birthTime = (int)trim($output);
            // %W returns 0 if birth time is not available
            if ($birthTime > 0) {
                return $birthTime;
            }
        }

        // Try alternative method for systems that support it
        $output = shell_exec("stat -f %B $escapedPath 2>/dev/null");
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
     *
     * @param string $userDir User directory path
     * @return int|null Oldest file time as unix timestamp or null
     */
    private function findOldestFileTime(string $userDir): ?int {
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
}
