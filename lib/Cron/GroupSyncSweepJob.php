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
use OCA\UserVO\Service\ConfigService;
use OCA\UserVO\Service\GroupSyncLedgerService;
use OCA\UserVO\Service\GroupSyncService;
use OCA\UserVO\UserVOAuth;

/**
 * Periodic repair job for the B1 fix: resyncs any group GroupSyncLedgerService
 * has marked dirty (dirty_seq > clean_seq) - a user-metadata write whose
 * effect a concurrent or lock-skipped full sync may have missed. See
 * GroupSyncLedgerService and the Version1005Date20260803000000 migration for
 * the full design.
 *
 * Unlike SyncUsersJob, this runs irrespective of the nightly sync policy
 * flags: it repairs writes that already happened locally, not a
 * VO-freshness policy decision. It intentionally does NOT replace
 * enable_nightly_group_sync - the ledger only sees drift caused by a local
 * write; a user quietly removed from a VO group who simply never logs in
 * again produces no write and no dirty mark, so only a periodic full
 * resync (the nightly job) catches that.
 *
 * Deliberately a separate job rather than folded into SyncUsersJob: this
 * needs a much shorter cadence (see INTERVAL_SECONDS) than the 24h nightly
 * job, and must run even when both nightly flags are off.
 *
 * The common case (nothing dirty) costs exactly one indexed SELECT - no
 * backend is built and no VO API call is made unless there's actually
 * something to repair. When there is, one backend is built for the whole
 * batch: fetchAllGroups()'s per-instance memo (see UserVOAuth) means at most
 * one live GetGroups call per run, regardless of how many groups are dirty.
 * Each dirty group is resynced via the same public, lease-protected entry
 * point (syncSingleGroupById()) any other caller uses - this job introduces
 * no new locking code and no new lock semantics. A group that's currently
 * lease-contended by another sync gets a 409 here, is left untouched (still
 * dirty), and is simply retried on the next tick.
 *
 * Under sustained write traffic a group can be re-dirtied faster than it's
 * cleaned; the periodic trigger bounds that to at most one resync per group
 * per interval (capped at MAX_GROUPS_PER_RUN), always useful work since each
 * pass propagates the newest data. A "resync immediately after every dirty
 * mark" trigger was considered and rejected: under sustained traffic its
 * resync rate is bounded only by how fast syncs finish, which is a livelock.
 */
class GroupSyncSweepJob extends TimedJob {
    private const INTERVAL_SECONDS = 300;
    private const MAX_GROUPS_PER_RUN = 25;

    private IConfig $config;
    private ConfigService $configService;
    private GroupSyncService $groupSyncService;
    private GroupSyncLedgerService $ledgerService;

    public function __construct(
        ITimeFactory $time,
        IConfig $config,
        ConfigService $configService,
        GroupSyncService $groupSyncService,
        GroupSyncLedgerService $ledgerService
    ) {
        parent::__construct($time);
        $this->config = $config;
        $this->configService = $configService;
        $this->groupSyncService = $groupSyncService;
        $this->ledgerService = $ledgerService;

        $this->setInterval(self::INTERVAL_SECONDS);
    }

    protected function run($argument): void {
        if ($this->config->getAppValue('user_vo', 'enable_group_sync_sweep', 'true') !== 'true') {
            logger('user_vo')->debug('Group sync sweep is disabled, skipping');
            return;
        }

        $dirtyGroupIds = $this->ledgerService->findDirtyGroups(self::MAX_GROUPS_PER_RUN);
        if (empty($dirtyGroupIds)) {
            return;
        }

        $configuration = $this->configService->loadConfiguration(maskPassword: false);
        if (empty($configuration['api_url']) || empty($configuration['api_username']) || empty($configuration['api_password'])) {
            logger('user_vo')->warning('Group sync sweep found dirty groups but VO is not configured, skipping', [
                'dirty_count' => count($dirtyGroupIds),
            ]);
            return;
        }

        // Built once for the whole batch: fetchAllGroups()'s per-instance
        // memo means this costs at most one live GetGroups call for however
        // many groups are dirty this run.
        $backend = new UserVOAuth(
            $configuration['api_url'],
            $configuration['api_username'],
            $configuration['api_password']
        );

        $repaired = 0;
        $stillContended = 0;
        $failed = 0;

        foreach ($dirtyGroupIds as $voGroupId) {
            try {
                $result = $this->groupSyncService->syncSingleGroupById($voGroupId, $backend);
            } catch (\Throwable $e) {
                // One group's failure must not abort the rest of this run's
                // batch - syncSingleGroupById() only catches \Exception
                // internally, so an \Error here would otherwise propagate out
                // of the loop and leave every remaining dirty group untouched
                // for this tick.
                $failed++;
                logger('user_vo')->warning('Group sync sweep failed to repair a dirty group', [
                    'vo_group_id' => $voGroupId,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            if ($result['success']) {
                $repaired++;
            } elseif (($result['status_code'] ?? null) === 409) {
                // Another sync currently holds this group's lease - leave it
                // dirty (untouched by this failed attempt) and retry next tick.
                $stillContended++;
            } else {
                $failed++;
                logger('user_vo')->warning('Group sync sweep failed to repair a dirty group', [
                    'vo_group_id' => $voGroupId,
                    'error' => $result['error'] ?? 'unknown error',
                ]);

                if (($result['error'] ?? null) === 'Failed to fetch groups from VereinOnline') {
                    // Every remaining group in this batch shares the same
                    // backend instance and would fail identically - stop
                    // instead of burning one failed VO API call per
                    // remaining dirty group on every tick of an outage.
                    logger('user_vo')->warning('Group sync sweep stopping early - VO API appears to be down', [
                        'remaining' => count($dirtyGroupIds) - $repaired - $stillContended - $failed,
                    ]);
                    break;
                }
            }
        }

        logger('user_vo')->info('Group sync sweep completed', [
            'dirty_count' => count($dirtyGroupIds),
            'repaired' => $repaired,
            'still_contended' => $stillContended,
            'failed' => $failed,
        ]);
    }
}
