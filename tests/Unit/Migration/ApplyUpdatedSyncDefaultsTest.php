<?php
namespace OCA\UserVO\Tests\Unit\Migration;

use OCA\UserVO\Migration\ApplyUpdatedSyncDefaults;
use OCP\IConfig;
use OCP\Migration\IOutput;
use Test\TestCase;

/**
 * Unit tests for the repair step that backfills sync_photo and
 * enable_nightly_user_sync to 'true' for installs that never explicitly
 * saved a value - matching this version's new defaults - without touching
 * any install that ever did save a value, true or false.
 */
class ApplyUpdatedSyncDefaultsTest extends TestCase {
	/** Values recorded via IConfig::setAppValue() by the mock built in configMock(). */
	private array $recordedConfig = [];

	protected function setUp(): void {
		parent::setUp();
		$this->recordedConfig = [];
	}

	private function configMock(array $initialValues): IConfig {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			fn ($app, $key, $default = '') => $initialValues[$key] ?? $default
		);
		$config->method('setAppValue')->willReturnCallback(function ($app, $key, $value) {
			$this->recordedConfig[$key] = $value;
		});
		return $config;
	}

	public function testDefaultsSyncPhotoWhenNeverSet(): void {
		$config = $this->configMock([]);
		(new ApplyUpdatedSyncDefaults($config))->run($this->createMock(IOutput::class));
		$this->assertSame('true', $this->recordedConfig['sync_photo'] ?? null);
	}

	public function testDefaultsNightlyUserSyncWhenNeverSet(): void {
		$config = $this->configMock([]);
		(new ApplyUpdatedSyncDefaults($config))->run($this->createMock(IOutput::class));
		$this->assertSame('true', $this->recordedConfig['enable_nightly_user_sync'] ?? null);
	}

	public function testDoesNotOverwriteAnExplicitlySetSyncPhotoValue(): void {
		$config = $this->configMock(['sync_photo' => 'false']);
		(new ApplyUpdatedSyncDefaults($config))->run($this->createMock(IOutput::class));
		$this->assertArrayNotHasKey('sync_photo', $this->recordedConfig, 'An explicit false is a real, deliberate choice and must not be overwritten');
	}

	public function testDoesNotOverwriteAnExplicitlySetNightlyUserSyncValue(): void {
		$config = $this->configMock(['enable_nightly_user_sync' => 'false']);
		(new ApplyUpdatedSyncDefaults($config))->run($this->createMock(IOutput::class));
		$this->assertArrayNotHasKey('enable_nightly_user_sync', $this->recordedConfig);
	}

	public function testIsANoOpWhenBothAreAlreadySet(): void {
		$config = $this->configMock([
			'sync_photo' => 'true',
			'enable_nightly_user_sync' => 'true',
		]);
		(new ApplyUpdatedSyncDefaults($config))->run($this->createMock(IOutput::class));
		$this->assertEmpty($this->recordedConfig);
	}

	public function testNeverTouchesSyncEmailOrGroupSync(): void {
		$config = $this->configMock([]);
		(new ApplyUpdatedSyncDefaults($config))->run($this->createMock(IOutput::class));
		$this->assertArrayNotHasKey('sync_email', $this->recordedConfig, 'sync_email already defaults to true, not this step\'s concern');
		$this->assertArrayNotHasKey('enable_nightly_group_sync', $this->recordedConfig, 'enable_nightly_group_sync never shipped - its code default alone covers every install');
	}
}
