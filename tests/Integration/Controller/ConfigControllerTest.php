<?php

namespace OCA\UserVO\Tests\Integration\Controller;

use OCA\UserVO\Controller\ConfigController;
use OCA\UserVO\Service\ApiClient;
use OCA\UserVO\Service\AuditLogService;
use OCA\UserVO\Service\ConfigService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Test\TestCase as NextcloudTestCase;

/**
 * Integration tests for ConfigController
 *
 * @group DB
 */
class ConfigControllerTest extends NextcloudTestCase {
	private ConfigController $controller;
	private IConfig $config;
	private ConfigService $configService;
	private ApiClient $apiClient;
	private LoggerInterface $logger;
	private IRequest $request;

	// Store original config values for restoration
	private array $originalConfig = [];

	protected function setUp(): void {
		parent::setUp();

		// Get real Nextcloud services
		$this->config = \OC::$server->get(IConfig::class);
		$this->logger = \OC::$server->get(LoggerInterface::class);
		$this->request = $this->createMock(IRequest::class);

		// Save original configuration
		$this->originalConfig = [
			'api_url' => $this->config->getAppValue('user_vo', 'api_url', ''),
			'api_username' => $this->config->getAppValue('user_vo', 'api_username', ''),
			'api_password' => $this->config->getAppValue('user_vo', 'api_password', ''),
			'sync_email' => $this->config->getAppValue('user_vo', 'sync_email', ''),
			'sync_photo' => $this->config->getAppValue('user_vo', 'sync_photo', ''),
			'enable_nightly_user_sync' => $this->config->getAppValue('user_vo', 'enable_nightly_user_sync', ''),
			'enable_nightly_group_sync' => $this->config->getAppValue('user_vo', 'enable_nightly_group_sync', ''),
			'enable_nightly_sync' => $this->config->getAppValue('user_vo', 'enable_nightly_sync', ''),
			'nightly_sync_last_run' => $this->config->getAppValue('user_vo', 'nightly_sync_last_run', ''),
			'nightly_sync_last_status' => $this->config->getAppValue('user_vo', 'nightly_sync_last_status', ''),
			'nightly_sync_last_error' => $this->config->getAppValue('user_vo', 'nightly_sync_last_error', ''),
			'nightly_sync_last_summary' => $this->config->getAppValue('user_vo', 'nightly_sync_last_summary', ''),
		];

		// Create real ConfigService and ApiClient
		$this->configService = new ConfigService($this->config);
		$this->apiClient = new ApiClient($this->logger, \OC::$server->get(IClientService::class));

		// Create controller
		$this->controller = new ConfigController(
			'user_vo',
			$this->request,
			$this->configService,
			$this->apiClient,
			$this->config,
			$this->logger,
			\OC::$server->get(AuditLogService::class)
		);
	}

	protected function tearDown(): void {
		// Restore original configuration
		foreach ($this->originalConfig as $key => $value) {
			if ($value === '') {
				// If original value was empty, delete the key
				$this->config->deleteAppValue('user_vo', $key);
			} else {
				// Otherwise restore the original value
				$this->config->setAppValue('user_vo', $key, $value);
			}
		}

		parent::tearDown();
	}

	public function testGetConfigurationStatusReturnsEmptyWhenNotConfigured() {
		// Clear config for this test
		$this->config->deleteAppValue('user_vo', 'api_url');
		$this->config->deleteAppValue('user_vo', 'api_username');
		$this->config->deleteAppValue('user_vo', 'api_password');

		$response = $this->controller->getConfigurationStatus();
		$this->assertInstanceOf(JSONResponse::class, $response);

		$data = $response->getData();
		$this->assertIsArray($data);
		$this->assertFalse($data['is_configured']);
		$this->assertEquals('incomplete', $data['source']);
		$this->assertIsArray($data['current_config']);
		$this->assertEquals('', $data['current_config']['api_url']);
		$this->assertEquals('', $data['current_config']['api_username']);
	}

	public function testSaveConfigurationFailsWithInvalidURL() {
		$this->request->method('getParam')
			->willReturnCallback(function($key, $default) {
				$params = [
					'api_url' => 'not-a-valid-url',
					'api_username' => 'test_user',
					'api_password' => 'test_pass'
				];
				return $params[$key] ?? $default;
			});

		$response = $this->controller->saveConfiguration();
		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(400, $response->getStatus());

		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('Invalid API URL', $data['message']);
	}

	public function testSaveConfigurationFailsWithMissingFields() {
		$this->request->method('getParam')
			->willReturnCallback(function($key, $default) {
				$params = [
					'api_url' => 'https://example.com',
					'api_username' => '',
					'api_password' => ''
				];
				return $params[$key] ?? $default;
			});

		$response = $this->controller->saveConfiguration();
		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(400, $response->getStatus());

		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('required', $data['message']);
	}

	public function testSaveConfigurationSucceedsWithValidInput() {
		$this->request->method('getParam')
			->willReturnCallback(function($key, $default) {
				$params = [
					'api_url' => 'https://example.com/api',
					'api_username' => 'test_user',
					'api_password' => 'test_pass'
				];
				return $params[$key] ?? $default;
			});

		$response = $this->controller->saveConfiguration();
		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());

		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertStringContainsString('saved successfully', $data['message']);

		// Verify configuration was saved
		$this->assertEquals('https://example.com/api', $this->config->getAppValue('user_vo', 'api_url', ''));
		$this->assertEquals('test_user', $this->config->getAppValue('user_vo', 'api_username', ''));
		$this->assertNotEmpty($this->config->getAppValue('user_vo', 'api_password', ''));
	}

	// --- testConfiguration() ---
	// $this->apiClient is real (raw curl, not mockable - see ApiClientTest's scope note), so
	// these can't reach a genuine success response; they cover the validation branches that
	// run before any network call, plus the password-precedence resolution combined with a
	// guaranteed-refused connection (http://127.0.0.1:1) to confirm resolution actually
	// happened without needing a live VO server.

	public function testTestConfigurationFailsWithMissingFields() {
		$this->config->deleteAppValue('user_vo', 'api_password');
		$this->request->method('getParam')->willReturnCallback(fn($key, $default) => $default);

		$response = $this->controller->testConfiguration();
		$this->assertEquals(400, $response->getStatus());

		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('required for testing', $data['message']);
	}

	public function testTestConfigurationFailsWithInvalidURL() {
		$this->request->method('getParam')
			->willReturnCallback(function ($key, $default) {
				$params = ['api_url' => 'not-a-valid-url', 'api_username' => 'u', 'api_password' => 'p'];
				return $params[$key] ?? $default;
			});

		$response = $this->controller->testConfiguration();
		$this->assertEquals(400, $response->getStatus());

		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('Invalid API URL', $data['message']);
	}

	public function testTestConfigurationResolvesPasswordFromDatabaseInAdminInterfaceMode() {
		// url + username given directly, no password param - admin-interface-mode precedence
		// must resolve the password from the database, not treat it as still missing.
		$this->config->setAppValue('user_vo', 'api_password', 'dbpass');
		$this->request->method('getParam')
			->willReturnCallback(function ($key, $default) {
				$params = ['api_url' => 'http://127.0.0.1:1/', 'api_username' => 'u'];
				return $params[$key] ?? $default;
			});

		$response = $this->controller->testConfiguration();

		// If the password hadn't resolved, this would 400 "required for testing" instead -
		// reaching the network call (which then fails to connect) proves resolution worked.
		$this->assertEquals(500, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('Configuration test failed', $data['message']);
	}

	public function testTestConfigurationFallsBackToFullPrecedenceWhenUrlAndUsernameMissing() {
		// No url/username/password params at all, and nothing configured anywhere -
		// exercises the config.php-mode precedence branch (ConfigService::loadConfiguration),
		// which here still resolves to empty, so validation correctly still fails.
		$this->config->deleteAppValue('user_vo', 'api_url');
		$this->config->deleteAppValue('user_vo', 'api_username');
		$this->config->deleteAppValue('user_vo', 'api_password');
		$this->request->method('getParam')->willReturnCallback(fn($key, $default) => $default);

		$response = $this->controller->testConfiguration();

		$this->assertEquals(400, $response->getStatus());
		$this->assertStringContainsString('required for testing', $response->getData()['message']);
	}

	public function testTestConfigurationReachesApiWithAllFieldsProvidedDirectly() {
		$this->request->method('getParam')
			->willReturnCallback(function ($key, $default) {
				$params = ['api_url' => 'http://127.0.0.1:1/', 'api_username' => 'u', 'api_password' => 'p'];
				return $params[$key] ?? $default;
			});

		$response = $this->controller->testConfiguration();

		$this->assertEquals(500, $response->getStatus());
		$this->assertStringContainsString('Configuration test failed', $response->getData()['message']);
	}

	public function testClearConfigurationSucceeds() {
		// Set some configuration first
		$this->config->setAppValue('user_vo', 'api_url', 'https://example.com');
		$this->config->setAppValue('user_vo', 'api_username', 'test');
		$this->config->setAppValue('user_vo', 'api_password', 'pass');

		$response = $this->controller->clearConfiguration();
		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());

		$data = $response->getData();
		$this->assertTrue($data['success']);

		// Verify configuration was cleared
		$this->assertEquals('', $this->config->getAppValue('user_vo', 'api_url', ''));
		$this->assertEquals('', $this->config->getAppValue('user_vo', 'api_username', ''));
		$this->assertEquals('', $this->config->getAppValue('user_vo', 'api_password', ''));
	}

	public function testSaveUserSyncSettingsSucceeds() {
		$this->request->method('getParam')
			->willReturnCallback(function($key, $default) {
				$params = [
					'sync_email' => 'true',
					'sync_photo' => 'false'
				];
				return $params[$key] ?? $default;
			});

		$response = $this->controller->saveUserSyncSettings();
		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());

		$data = $response->getData();
		$this->assertTrue($data['success']);

		// Verify settings were saved
		$this->assertEquals('true', $this->config->getAppValue('user_vo', 'sync_email', ''));
		$this->assertEquals('false', $this->config->getAppValue('user_vo', 'sync_photo', ''));
	}

	public function testSaveNightlySyncSettingForUserSync() {
		$this->request->method('getParam')
			->willReturnCallback(function($key, $default) {
				$params = [
					'enabled' => true,
					'sync_type' => 'user'
				];
				return $params[$key] ?? $default;
			});

		$response = $this->controller->saveNightlySyncSetting();
		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());

		$data = $response->getData();
		$this->assertTrue($data['success']);

		// Verify setting was saved
		$this->assertEquals('true', $this->config->getAppValue('user_vo', 'enable_nightly_user_sync', ''));
	}

	public function testSaveNightlySyncSettingForGroupSync() {
		$this->request->method('getParam')
			->willReturnCallback(function($key, $default) {
				$params = [
					'enabled' => true,
					'sync_type' => 'group'
				];
				return $params[$key] ?? $default;
			});

		$response = $this->controller->saveNightlySyncSetting();
		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());

		$data = $response->getData();
		$this->assertTrue($data['success']);

		// Verify group sync setting was saved
		$this->assertEquals('true', $this->config->getAppValue('user_vo', 'enable_nightly_group_sync', ''));
	}

	public function testGetNightlySyncStatusWithNoHistory() {
		// Clear sync history for this test
		$this->config->deleteAppValue('user_vo', 'enable_nightly_user_sync');
		$this->config->deleteAppValue('user_vo', 'enable_nightly_group_sync');
		$this->config->deleteAppValue('user_vo', 'enable_nightly_sync');
		$this->config->deleteAppValue('user_vo', 'nightly_sync_last_run');
		$this->config->deleteAppValue('user_vo', 'nightly_sync_last_status');
		$this->config->deleteAppValue('user_vo', 'nightly_sync_last_error');
		$this->config->deleteAppValue('user_vo', 'nightly_sync_last_summary');

		$response = $this->controller->getNightlySyncStatus();
		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());

		$data = $response->getData();
		$this->assertTrue($data['success']);
		// Both default to enabled when never explicitly configured (see
		// ApplyUpdatedSyncDefaults repair step for the existing-install backfill).
		$this->assertTrue($data['user_sync_enabled']);
		$this->assertTrue($data['group_sync_enabled']);
		$this->assertNull($data['last_run']);
		$this->assertEquals('never', $data['last_status']);
	}

	public function testGetNightlySyncStatusWithHistory() {
		// Set up sync history
		$timestamp = time();
		$this->config->setAppValue('user_vo', 'enable_nightly_user_sync', 'true');
		$this->config->setAppValue('user_vo', 'enable_nightly_group_sync', 'true');
		$this->config->setAppValue('user_vo', 'nightly_sync_last_run', (string)$timestamp);
		$this->config->setAppValue('user_vo', 'nightly_sync_last_status', 'success');
		$this->config->setAppValue('user_vo', 'nightly_sync_last_error', '');
		$this->config->setAppValue('user_vo', 'nightly_sync_last_summary', json_encode([
			'users_synced' => 5,
			'groups_synced' => 3
		]));

		$response = $this->controller->getNightlySyncStatus();
		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());

		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertTrue($data['user_sync_enabled']);
		$this->assertTrue($data['group_sync_enabled']);
		$this->assertEquals($timestamp, $data['last_run']);
		$this->assertEquals('success', $data['last_status']);
		$this->assertEquals('', $data['last_error']);
		$this->assertIsArray($data['last_summary']);
		$this->assertEquals(5, $data['last_summary']['users_synced']);
		$this->assertEquals(3, $data['last_summary']['groups_synced']);
	}
}
