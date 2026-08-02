<?php
namespace OCA\UserVO\Tests\Unit\Migration;

use OCA\UserVO\Migration\MigrateLegacyNightlySyncFlag;
use OCP\IConfig;
use OCP\Migration\IOutput;
use Test\TestCase;

/**
 * Unit tests for the one-time repair step that migrates the legacy
 * 'enable_nightly_sync' config key (what the admin UI's "user sync"
 * checkbox always actually meant, and the only thing that ever wrote it)
 * into 'enable_nightly_user_sync', then removes it.
 */
class MigrateLegacyNightlySyncFlagTest extends TestCase {
	private function configMock(array $initialValues): IConfig {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			fn ($app, $key, $default = '') => $initialValues[$key] ?? $default
		);
		return $config;
	}

	public function testMigratesLegacyValueWhenNewKeyNotSet(): void {
		$config = $this->configMock(['enable_nightly_sync' => 'true']);
		$config->expects($this->once())->method('setAppValue')
			->with('user_vo', 'enable_nightly_user_sync', 'true');
		$config->expects($this->once())->method('deleteAppValue')
			->with('user_vo', 'enable_nightly_sync');

		(new MigrateLegacyNightlySyncFlag($config))->run($this->createMock(IOutput::class));
	}

	public function testDoesNotOverwriteAnAlreadySetNewKey(): void {
		$config = $this->configMock([
			'enable_nightly_sync' => 'true',
			'enable_nightly_user_sync' => 'false',
		]);
		$config->expects($this->never())->method('setAppValue');
		$config->expects($this->once())->method('deleteAppValue')
			->with('user_vo', 'enable_nightly_sync');

		(new MigrateLegacyNightlySyncFlag($config))->run($this->createMock(IOutput::class));
	}

	public function testNoOpWhenLegacyKeyWasNeverSet(): void {
		$config = $this->configMock([]);
		$config->expects($this->never())->method('setAppValue');
		$config->expects($this->never())->method('deleteAppValue');

		(new MigrateLegacyNightlySyncFlag($config))->run($this->createMock(IOutput::class));
	}
}
