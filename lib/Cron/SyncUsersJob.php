<?php
/**
 * @author Nikolaus Demmel <nikolaus@nikolaus-demmel.de>
 * @copyright (c) 2025 Nikolaus Demmel <nikolaus@nikolaus-demmel.de>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the LICENSE file.
 */

declare(strict_types=1);

namespace OCA\UserVO\Cron;

use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use function OCP\Log\logger;
use OCA\UserVO\Service\UserSyncService;
use OCA\UserVO\Service\ConfigService;
use OCA\UserVO\Service\GroupSyncService;
use OCA\UserVO\UserVOAuth;

/**
 * Coordinated nightly sync job for users and groups
 *
 * Runs user sync first (updates vo_group_ids), then group sync (uses those IDs).
 * Each can be enabled/disabled independently via config.
 *
 * @psalm-import-type GroupSyncAllSuccess from GroupSyncService
 */
class SyncUsersJob extends TimedJob {
    private IConfig $config;
    private UserSyncService $userSyncService;
    private ConfigService $configService;
    private GroupSyncService $groupSyncService;

    public function __construct(
        ITimeFactory $time,
        IConfig $config,
        UserSyncService $userSyncService,
        ConfigService $configService,
        GroupSyncService $groupSyncService
    ) {
        parent::__construct($time);
        $this->config = $config;
        $this->userSyncService = $userSyncService;
        $this->configService = $configService;
        $this->groupSyncService = $groupSyncService;

        // Run once per day (24 hours)
        $this->setInterval(24 * 60 * 60);
    }

    protected function run($argument): void {
        $userSyncEnabled = $this->config->getAppValue('user_vo', 'enable_nightly_user_sync', 'false') === 'true';
        $groupSyncEnabled = $this->config->getAppValue('user_vo', 'enable_nightly_group_sync', 'false') === 'true';

        if (!$userSyncEnabled && !$groupSyncEnabled) {
            logger('user_vo')->debug('Nightly sync is disabled (both user and group sync disabled), skipping');
            return;
        }

        logger('user_vo')->info('Starting nightly VO sync', [
            'user_sync' => $userSyncEnabled,
            'group_sync' => $groupSyncEnabled
        ]);

        $startTime = time();
        $userSummary = null;
        $groupSummary = null;
        $errors = [];

        // Step 1: Sync users (if enabled)
        if ($userSyncEnabled) {
            try {
                logger('user_vo')->info('Starting user sync');

                // Create backend instance
                $configuration = $this->configService->loadConfiguration(maskPassword: false);
                $backend = new UserVOAuth(
                    $configuration['api_url'],
                    $configuration['api_username'],
                    $configuration['api_password']
                );

                // Call service directly (not via controller)
                $result = $this->userSyncService->syncAllUsers($backend);

                if (!$result['success']) {
                    throw new \Exception($result['error'] ?? 'User sync failed');
                }

                // Convert 'success' key to 'synced' for consistency
                $userSummary = [
                    'total' => $result['summary']['total'],
                    'synced' => $result['summary']['success'],
                    'failed' => $result['summary']['failed'],
                    'skipped' => $result['summary']['skipped']
                ];

                logger('user_vo')->info('User sync completed', $userSummary);

            } catch (\Exception $e) {
                $error = 'User sync failed: ' . $e->getMessage();
                $errors[] = $error;
                logger('user_vo')->error($error, ['trace' => $e->getTraceAsString()]);

                // Don't proceed to group sync if user sync failed
                $groupSyncEnabled = false;
            }
        }

        // Step 2: Sync groups (if enabled and user sync succeeded or was skipped)
        if ($groupSyncEnabled) {
            try {
                logger('user_vo')->info('Starting group sync');

                // Reuse backend instance from user sync if available, or create new one
                if (!isset($backend)) {
                    $configuration = $this->configService->loadConfiguration(maskPassword: false);
                    $backend = new UserVOAuth(
                        $configuration['api_url'],
                        $configuration['api_username'],
                        $configuration['api_password']
                    );
                }

                // Call service directly (not via controller)
                $result = $this->groupSyncService->syncAllManagedGroups($backend);

                if (!$result['success']) {
                    throw new \Exception($result['error'] ?? 'Group sync failed');
                }

                /** @var GroupSyncAllSuccess $result */
                $groupSummary = [
                    'total' => $result['summary']['total'],
                    'succeeded' => $result['summary']['succeeded'],
                    'failed' => $result['summary']['failed']
                ];

                logger('user_vo')->info('Group sync completed', $groupSummary);

            } catch (\Exception $e) {
                $error = 'Group sync failed: ' . $e->getMessage();
                $errors[] = $error;
                logger('user_vo')->error($error, ['trace' => $e->getTraceAsString()]);
            }
        }

        // Store combined status
        $overallStatus = empty($errors) ? 'success' : 'failed';
        $this->config->setAppValue('user_vo', 'nightly_sync_last_run', (string)$startTime);
        $this->config->setAppValue('user_vo', 'nightly_sync_last_status', $overallStatus);
        $this->config->setAppValue('user_vo', 'nightly_sync_last_error', implode('; ', $errors));
        $this->config->setAppValue('user_vo', 'nightly_sync_last_summary', json_encode([
            'users' => $userSummary ?: ['total' => 0, 'synced' => 0, 'failed' => 0, 'skipped' => 0],
            'groups' => $groupSummary ?: ['total' => 0, 'succeeded' => 0, 'failed' => 0]
        ]));

        if ($overallStatus === 'success') {
            logger('user_vo')->info('Nightly VO sync completed successfully');
        } else {
            logger('user_vo')->error('Nightly VO sync completed with errors', ['errors' => $errors]);
        }
    }
}
