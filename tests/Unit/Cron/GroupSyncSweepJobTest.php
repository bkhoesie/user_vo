<?php
namespace OCA\UserVO\Tests\Unit\Cron;

use OCA\UserVO\Cron\GroupSyncSweepJob;
use OCA\UserVO\Service\ConfigService;
use OCA\UserVO\Service\GroupSyncLedgerService;
use OCA\UserVO\Service\GroupSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use Test\TestCase;

/**
 * Unit tests for GroupSyncSweepJob - all dependencies are constructor-injected
 * and fully mockable, so this needs no real database. run() is protected
 * (a TimedJob callback), invoked via reflection - same pattern as
 * SyncUsersJobTest.
 *
 * Focus: the "cheap no-op when nothing is dirty" contract (no backend built,
 * no VO call), the kill switch, the batch cap, and that repair goes through
 * the same lease-protected public entry point every other caller uses - not
 * some new unlocked path. See GroupSyncLedgerService and the
 * Version1005Date20260803000000 migration for the design this job repairs.
 */
class GroupSyncSweepJobTest extends TestCase {
	private ITimeFactory $time;
	private IConfig $config;
	private ConfigService $configService;
	private GroupSyncService $groupSyncService;
	private GroupSyncLedgerService $ledgerService;

	protected function setUp(): void {
		parent::setUp();
		$this->time = $this->createMock(ITimeFactory::class);
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getAppValue')->willReturnCallback(
			fn ($app, $key, $default = '') => $key === 'enable_group_sync_sweep' ? 'true' : $default
		);
		$this->configService = $this->createMock(ConfigService::class);
		$this->configService->method('loadConfiguration')->willReturn([
			'api_url' => 'https://vo.test/org',
			'api_username' => 'apiuser',
			'api_password' => 'apipass',
		]);
		$this->groupSyncService = $this->createMock(GroupSyncService::class);
		$this->ledgerService = $this->createMock(GroupSyncLedgerService::class);
	}

	private function runJob(): void {
		$job = new GroupSyncSweepJob($this->time, $this->config, $this->configService, $this->groupSyncService, $this->ledgerService);
		$ref = new \ReflectionMethod(GroupSyncSweepJob::class, 'run');
		$ref->setAccessible(true);
		$ref->invoke($job, null);
	}

	public function testDoesNothingAndCallsNoApiWhenNothingIsDirty(): void {
		$this->ledgerService->method('findDirtyGroups')->willReturn([]);
		$this->configService->expects($this->never())->method('loadConfiguration');
		$this->groupSyncService->expects($this->never())->method('syncSingleGroupById');

		$this->runJob();
	}

	public function testResyncsEachDirtyGroupThroughTheLockedPublicEntryPoint(): void {
		$this->ledgerService->method('findDirtyGroups')->willReturn(['g1', 'g2']);

		$seenGroupIds = [];
		$this->groupSyncService->expects($this->exactly(2))->method('syncSingleGroupById')
			->willReturnCallback(function ($voGroupId, $backend) use (&$seenGroupIds) {
				$seenGroupIds[] = $voGroupId;
				return ['success' => true];
			});

		$this->runJob();

		$this->assertEquals(['g1', 'g2'], $seenGroupIds, 'Must resync exactly the groups findDirtyGroups() reported, via syncSingleGroupById()');
	}

	public function testSkipsWhenConfigurationIsIncomplete(): void {
		$this->ledgerService->method('findDirtyGroups')->willReturn(['g1']);
		$this->configService = $this->createMock(ConfigService::class);
		$this->configService->method('loadConfiguration')->willReturn([
			'api_url' => '',
			'api_username' => '',
			'api_password' => '',
		]);
		$this->groupSyncService->expects($this->never())->method('syncSingleGroupById');

		$this->runJob();
	}

	public function testRespectsTheKillSwitch(): void {
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getAppValue')->willReturnCallback(
			fn ($app, $key, $default = '') => $key === 'enable_group_sync_sweep' ? 'false' : $default
		);
		$this->ledgerService->expects($this->never())->method('findDirtyGroups');
		$this->groupSyncService->expects($this->never())->method('syncSingleGroupById');

		$this->runJob();
	}

	public function testHonorsThePerRunBatchCap(): void {
		$this->ledgerService->expects($this->once())->method('findDirtyGroups')
			->with(25)
			->willReturn([]);

		$this->runJob();
	}

	public function testBuildsTheBackendOnceForTheWholeBatch(): void {
		$this->ledgerService->method('findDirtyGroups')->willReturn(['g1', 'g2']);

		$seenBackends = [];
		$this->groupSyncService->method('syncSingleGroupById')
			->willReturnCallback(function ($voGroupId, $backend) use (&$seenBackends) {
				$seenBackends[] = $backend;
				return ['success' => true];
			});

		$this->runJob();

		$this->assertCount(2, $seenBackends);
		$this->assertSame($seenBackends[0], $seenBackends[1], 'The same backend instance must be reused across the whole batch - fetchAllGroups() memoizes per-instance, so reuse is what keeps this to one live GetGroups call per run');
	}

	/**
	 * syncSingleGroupById() only catches \Exception internally, not
	 * \Throwable - an \Error for one group must not abort the rest of this
	 * run's batch and leave every remaining dirty group untouched.
	 */
	public function testOneGroupThrowingDoesNotAbortTheRestOfTheBatch(): void {
		$this->ledgerService->method('findDirtyGroups')->willReturn(['g1', 'g2', 'g3']);

		$seenGroupIds = [];
		$this->groupSyncService->method('syncSingleGroupById')
			->willReturnCallback(function ($voGroupId) use (&$seenGroupIds) {
				$seenGroupIds[] = $voGroupId;
				if ($voGroupId === 'g2') {
					throw new \Error('simulated fatal error for g2');
				}
				return ['success' => true];
			});

		$this->runJob();

		$this->assertEquals(['g1', 'g2', 'g3'], $seenGroupIds, 'g3 must still be attempted despite g2 throwing');
	}

	/**
	 * All groups in a batch share one backend instance - if fetchAllGroups()
	 * fails for one, it will fail identically for every remaining group in
	 * the same run. Must stop instead of burning one failed VO API call per
	 * remaining dirty group, every tick, for the duration of an outage.
	 */
	public function testStopsEarlyWhenVoApiAppearsDown(): void {
		$this->ledgerService->method('findDirtyGroups')->willReturn(['g1', 'g2', 'g3']);

		$seenGroupIds = [];
		$this->groupSyncService->method('syncSingleGroupById')
			->willReturnCallback(function ($voGroupId) use (&$seenGroupIds) {
				$seenGroupIds[] = $voGroupId;
				return ['success' => false, 'error' => 'Failed to fetch groups from VereinOnline', 'status_code' => 500];
			});

		$this->runJob();

		$this->assertEquals(['g1'], $seenGroupIds, 'Must stop after the first VO-fetch failure rather than retrying identically for every remaining group');
	}
}
