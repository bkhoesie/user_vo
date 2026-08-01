<?php
namespace OCA\UserVO\Tests\Unit\Cron;

use OCA\UserVO\Cron\SyncUsersJob;
use OCA\UserVO\Service\ConfigService;
use OCA\UserVO\Service\GroupSyncService;
use OCA\UserVO\Service\UserSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use Test\TestCase;

/**
 * Unit tests for SyncUsersJob - all dependencies are constructor-injected and
 * fully mockable, so this needs no real database. run() is protected (it's a
 * TimedJob callback), invoked via reflection.
 *
 * Focus: the enable/disable flag logic (including legacy 'enable_nightly_sync'
 * backward compatibility) and the "don't run group sync if user sync failed"
 * ordering rule - both are meaningful orchestration decisions with no other
 * coverage anywhere.
 */
class SyncUsersJobTest extends TestCase {
	private ITimeFactory $time;

	/** Values recorded via IConfig::setAppValue() by the mock built in configMock(). */
	private array $recordedConfig = [];

	protected function setUp(): void {
		parent::setUp();
		$this->time = $this->createMock(ITimeFactory::class);
		$this->recordedConfig = [];
	}

	private function configMock(array $initialValues): IConfig {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			fn($app, $key, $default = '') => $initialValues[$key] ?? $default
		);
		$config->method('setAppValue')->willReturnCallback(function ($app, $key, $value) {
			$this->recordedConfig[$key] = $value;
		});
		return $config;
	}

	private function runJob(
		IConfig $config,
		UserSyncService $userSyncService,
		ConfigService $configService,
		GroupSyncService $groupSyncService
	): void {
		$job = new SyncUsersJob($this->time, $config, $userSyncService, $configService, $groupSyncService);
		$ref = new \ReflectionMethod(SyncUsersJob::class, 'run');
		$ref->setAccessible(true);
		$ref->invoke($job, null);
	}

	public function testDoesNothingWhenBothSyncsDisabled(): void {
		$config = $this->configMock([
			'enable_nightly_user_sync' => 'false',
			'enable_nightly_group_sync' => 'false',
		]);
		$userSyncService = $this->createMock(UserSyncService::class);
		$userSyncService->expects($this->never())->method('syncAllUsers');
		$groupSyncService = $this->createMock(GroupSyncService::class);
		$groupSyncService->expects($this->never())->method('syncAllManagedGroups');

		$this->runJob($config, $userSyncService, $this->createMock(ConfigService::class), $groupSyncService);

		$this->assertEmpty($this->recordedConfig, 'No status should be written when nothing ran');
	}

	public function testLegacyEnableNightlySyncEnablesUserSync(): void {
		$config = $this->configMock([
			'enable_nightly_sync' => 'true', // legacy flag, no explicit 'enable_nightly_user_sync' set
		]);
		$configService = $this->createMock(ConfigService::class);
		$configService->method('loadConfiguration')->willReturn(['api_url' => '', 'api_username' => '', 'api_password' => '']);

		$userSyncService = $this->createMock(UserSyncService::class);
		$userSyncService->expects($this->once())->method('syncAllUsers')->willReturn([
			'success' => true,
			'summary' => ['total' => 1, 'success' => 1, 'failed' => 0, 'skipped' => 0],
		]);

		$this->runJob($config, $userSyncService, $configService, $this->createMock(GroupSyncService::class));

		$this->assertEquals('success', $this->recordedConfig['nightly_sync_last_status']);
	}

	public function testUserSyncFailurePreventsGroupSyncFromRunning(): void {
		$config = $this->configMock([
			'enable_nightly_user_sync' => 'true',
			'enable_nightly_group_sync' => 'true',
		]);
		$configService = $this->createMock(ConfigService::class);
		$configService->method('loadConfiguration')->willReturn(['api_url' => '', 'api_username' => '', 'api_password' => '']);

		$userSyncService = $this->createMock(UserSyncService::class);
		$userSyncService->method('syncAllUsers')->willReturn(['success' => false, 'error' => 'boom']);

		$groupSyncService = $this->createMock(GroupSyncService::class);
		$groupSyncService->expects($this->never())->method('syncAllManagedGroups');

		$this->runJob($config, $userSyncService, $configService, $groupSyncService);

		$this->assertEquals('failed', $this->recordedConfig['nightly_sync_last_status']);
		$this->assertStringContainsString('User sync failed', $this->recordedConfig['nightly_sync_last_error']);
	}

	public function testGroupSyncRunsIndependentlyWhenUserSyncDisabled(): void {
		$config = $this->configMock([
			'enable_nightly_user_sync' => 'false',
			'enable_nightly_group_sync' => 'true',
		]);
		$configService = $this->createMock(ConfigService::class);
		$configService->method('loadConfiguration')->willReturn(['api_url' => '', 'api_username' => '', 'api_password' => '']);

		$userSyncService = $this->createMock(UserSyncService::class);
		$userSyncService->expects($this->never())->method('syncAllUsers');

		$groupSyncService = $this->createMock(GroupSyncService::class);
		$groupSyncService->expects($this->once())->method('syncAllManagedGroups')->willReturn([
			'success' => true,
			'summary' => ['total' => 2, 'succeeded' => 2, 'failed' => 0],
		]);

		$this->runJob($config, $userSyncService, $configService, $groupSyncService);

		$this->assertEquals('success', $this->recordedConfig['nightly_sync_last_status']);
		$summary = json_decode($this->recordedConfig['nightly_sync_last_summary'], true);
		$this->assertEquals(2, $summary['groups']['succeeded']);
	}

	public function testBothSyncsSucceedRecordsCombinedSummary(): void {
		$config = $this->configMock([
			'enable_nightly_user_sync' => 'true',
			'enable_nightly_group_sync' => 'true',
		]);
		$configService = $this->createMock(ConfigService::class);
		$configService->method('loadConfiguration')->willReturn(['api_url' => '', 'api_username' => '', 'api_password' => '']);

		$userSyncService = $this->createMock(UserSyncService::class);
		$userSyncService->method('syncAllUsers')->willReturn([
			'success' => true,
			'summary' => ['total' => 3, 'success' => 3, 'failed' => 0, 'skipped' => 0],
		]);
		$groupSyncService = $this->createMock(GroupSyncService::class);
		$groupSyncService->method('syncAllManagedGroups')->willReturn([
			'success' => true,
			'summary' => ['total' => 1, 'succeeded' => 1, 'failed' => 0],
		]);

		$this->runJob($config, $userSyncService, $configService, $groupSyncService);

		$this->assertEquals('success', $this->recordedConfig['nightly_sync_last_status']);
		$summary = json_decode($this->recordedConfig['nightly_sync_last_summary'], true);
		$this->assertEquals(3, $summary['users']['synced']);
		$this->assertEquals(1, $summary['groups']['succeeded']);
	}
}
