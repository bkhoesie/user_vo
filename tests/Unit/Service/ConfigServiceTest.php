<?php
/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserVO\Tests\Unit\Service;

use OCA\UserVO\Service\ConfigService;
use OCP\IConfig;
use Test\TestCase;

/**
 * Unit tests for ConfigService
 * Tests configuration loading precedence and password masking
 */
class ConfigServiceTest extends TestCase {
	private IConfig $config;
	private ConfigService $configService;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(IConfig::class);
		$this->configService = new ConfigService($this->config);
	}

	/**
	 * Test loading configuration from config.php (highest precedence)
	 */
	public function testLoadConfigurationFromConfigPhp(): void {
		// Mock config.php with UserVO backend
		$this->config->expects($this->once())
			->method('getSystemValue')
			->with('user_backends', [])
			->willReturn([
				[
					'class' => '\\OCA\\UserVO\\UserVOAuth',
					'arguments' => [
						'https://example.org/api',
						'test_user',
						'test_password'
					]
				]
			]);

		// Should not call getAppValue since config.php has all values
		$this->config->expects($this->never())
			->method('getAppValue');

		$result = $this->configService->loadConfiguration();

		$this->assertSame('https://example.org/api', $result['api_url']);
		$this->assertSame('test_user', $result['api_username']);
		$this->assertSame('*************', $result['api_password']); // Masked
	}

	/**
	 * Test loading configuration from admin interface (fallback)
	 */
	public function testLoadConfigurationFromAdminInterface(): void {
		// Mock empty config.php
		$this->config->expects($this->once())
			->method('getSystemValue')
			->with('user_backends', [])
			->willReturn([]);

		// Mock admin interface values
		$this->config->expects($this->exactly(3))
			->method('getAppValue')
			->willReturnMap([
				['user_vo', 'api_url', '', 'https://admin.example.org/api'],
				['user_vo', 'api_username', '', 'admin_user'],
				['user_vo', 'api_password', '', 'admin_password']
			]);

		$result = $this->configService->loadConfiguration();

		$this->assertSame('https://admin.example.org/api', $result['api_url']);
		$this->assertSame('admin_user', $result['api_username']);
		$this->assertSame('**************', $result['api_password']); // Masked
	}

	/**
	 * Test config.php precedence over admin interface
	 */
	public function testConfigPhpTakesPrecedenceOverAdminInterface(): void {
		// Mock config.php with partial values
		$this->config->expects($this->once())
			->method('getSystemValue')
			->with('user_backends', [])
			->willReturn([
				[
					'class' => 'OCA\\UserVO\\UserVOAuth', // Test without leading backslash
					'arguments' => [
						'https://config.example.org/api',
						'config_user',
						'' // Empty password
					]
				]
			]);

		// Only password should be fetched from admin interface
		$this->config->expects($this->once())
			->method('getAppValue')
			->with('user_vo', 'api_password', '')
			->willReturn('admin_password');

		$result = $this->configService->loadConfiguration();

		// URL and username from config.php
		$this->assertSame('https://config.example.org/api', $result['api_url']);
		$this->assertSame('config_user', $result['api_username']);
		// Password from admin interface (fallback)
		$this->assertSame('**************', $result['api_password']);
	}

	/**
	 * Test password masking can be disabled
	 */
	public function testLoadConfigurationWithoutPasswordMasking(): void {
		$this->config->expects($this->once())
			->method('getSystemValue')
			->with('user_backends', [])
			->willReturn([
				[
					'class' => '\\OCA\\UserVO\\UserVOAuth',
					'arguments' => [
						'https://example.org/api',
						'test_user',
						'plain_password'
					]
				]
			]);

		$result = $this->configService->loadConfiguration(false); // Don't mask

		$this->assertSame('plain_password', $result['api_password']);
	}

	/**
	 * Test getConfigurationSource returns 'config.php'
	 */
	public function testGetConfigurationSourceFromConfigPhp(): void {
		$this->config->expects($this->once())
			->method('getSystemValue')
			->with('user_backends', [])
			->willReturn([
				[
					'class' => '\\OCA\\UserVO\\UserVOAuth',
					'arguments' => ['url', 'user', 'pass']
				]
			]);

		$source = $this->configService->getConfigurationSource();

		$this->assertSame('config.php', $source);
	}

	/**
	 * Test getConfigurationSource returns 'admin_interface'
	 */
	public function testGetConfigurationSourceFromAdminInterface(): void {
		$this->config->expects($this->once())
			->method('getSystemValue')
			->with('user_backends', [])
			->willReturn([]);

		$this->config->expects($this->once())
			->method('getAppValue')
			->with('user_vo', 'api_url', '')
			->willReturn('https://example.org/api');

		$source = $this->configService->getConfigurationSource();

		$this->assertSame('admin_interface', $source);
	}

	/**
	 * Test getConfigurationSource returns 'incomplete'
	 */
	public function testGetConfigurationSourceIncomplete(): void {
		$this->config->expects($this->once())
			->method('getSystemValue')
			->with('user_backends', [])
			->willReturn([]);

		$this->config->expects($this->once())
			->method('getAppValue')
			->with('user_vo', 'api_url', '')
			->willReturn(''); // Empty

		$source = $this->configService->getConfigurationSource();

		$this->assertSame('incomplete', $source);
	}

	/**
	 * Test handling of double-backslash class names
	 */
	public function testHandlesDoubleBackslashInClassName(): void {
		$this->config->expects($this->once())
			->method('getSystemValue')
			->with('user_backends', [])
			->willReturn([
				[
					// Escaped backslashes as they appear in config.php
					'class' => '\\\\OCA\\\\UserVO\\\\UserVOAuth',
					'arguments' => ['url', 'user', 'pass']
				]
			]);

		$source = $this->configService->getConfigurationSource();

		$this->assertSame('config.php', $source);
	}

	/**
	 * Test per-field source reporting: per-field precedence, not the whole-config
	 * verdict getConfigurationSource() gives - a config.php URL alongside an
	 * admin-interface-only password should report each field's own source.
	 */
	public function testGetConfigurationSourcesReportsMixedSourcesPerField(): void {
		$this->config->method('getSystemValue')
			->with('user_backends', [])
			->willReturn([
				[
					'class' => '\OCA\UserVO\UserVOAuth',
					'arguments' => ['https://vo.example/org', 'apiuser', '']
				]
			]);
		$this->config->method('getAppValue')
			->willReturnCallback(fn($app, $key, $default) => $key === 'api_password' ? 'adminpass' : $default);

		$sources = $this->configService->getConfigurationSources();

		$this->assertSame('config.php', $sources['api_url']);
		$this->assertSame('config.php', $sources['api_username']);
		$this->assertSame('admin_interface', $sources['api_password']);
	}

	public function testGetConfigurationSourcesReportsNullForUnsetFields(): void {
		$this->config->method('getSystemValue')->willReturn([]);
		$this->config->method('getAppValue')->willReturnCallback(fn($app, $key, $default) => $default);

		$sources = $this->configService->getConfigurationSources();

		$this->assertNull($sources['api_url']);
		$this->assertNull($sources['api_username']);
		$this->assertNull($sources['api_password']);
	}

	public function testGetConfigurationSourcesAllFromAdminInterface(): void {
		$this->config->method('getSystemValue')->willReturn([]);
		$this->config->method('getAppValue')
			->willReturnCallback(fn($app, $key, $default) => match ($key) {
				'api_url' => 'https://vo.example/org',
				'api_username' => 'apiuser',
				'api_password' => 'apipass',
				default => $default,
			});

		$sources = $this->configService->getConfigurationSources();

		$this->assertSame('admin_interface', $sources['api_url']);
		$this->assertSame('admin_interface', $sources['api_username']);
		$this->assertSame('admin_interface', $sources['api_password']);
	}

	public function testGetConfigurationSourcesIgnoresEmptyConfigPhpArguments(): void {
		// A config.php backend entry with an empty-string URL must not count as
		// "config.php provides this field" - falls through to admin_interface/null.
		$this->config->method('getSystemValue')
			->with('user_backends', [])
			->willReturn([
				[
					'class' => '\OCA\UserVO\UserVOAuth',
					'arguments' => ['', 'apiuser', 'apipass']
				]
			]);
		$this->config->method('getAppValue')->willReturnCallback(fn($app, $key, $default) => $default);

		$sources = $this->configService->getConfigurationSources();

		$this->assertNull($sources['api_url'], 'Empty config.php argument must not count as a config.php source');
		$this->assertSame('config.php', $sources['api_username']);
		$this->assertSame('config.php', $sources['api_password']);
	}
}
