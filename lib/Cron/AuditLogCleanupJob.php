<?php
/**
 * @author Nikolaus Demmel <nikolaus@nikolaus-demmel.de>
 * @copyright (c) 2026 Nikolaus Demmel <nikolaus@nikolaus-demmel.de>
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
use OCA\UserVO\Service\AuditLogService;

/**
 * Nightly retention cleanup for the audit log (see AuditLogService) - keeps
 * it bounded at audit_log_retention_days (default 7) rather than growing
 * forever. Deliberately its own job rather than folded into SyncUsersJob:
 * retention policy is unrelated to VO sync scheduling and must run
 * regardless of whether nightly sync is enabled.
 */
class AuditLogCleanupJob extends TimedJob {
    private const INTERVAL_SECONDS = 86400;
    private const DEFAULT_RETENTION_DAYS = 7;

    private IConfig $config;
    private AuditLogService $auditLogService;

    public function __construct(
        ITimeFactory $time,
        IConfig $config,
        AuditLogService $auditLogService
    ) {
        parent::__construct($time);
        $this->config = $config;
        $this->auditLogService = $auditLogService;

        $this->setInterval(self::INTERVAL_SECONDS);
    }

    protected function run($argument): void {
        $retentionDays = (int)$this->config->getAppValue('user_vo', 'audit_log_retention_days', (string)self::DEFAULT_RETENTION_DAYS);
        if ($retentionDays <= 0) {
            $retentionDays = self::DEFAULT_RETENTION_DAYS;
        }

        $deleted = $this->auditLogService->cleanupOlderThan($retentionDays);

        if ($deleted > 0) {
            logger('user_vo')->info('Audit log cleanup removed old entries', [
                'deleted' => $deleted,
                'retention_days' => $retentionDays,
            ]);
        }
    }
}
