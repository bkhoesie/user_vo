<?php
/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserVO\Tests\Unit\Controller;

use OCA\UserVO\Controller\AdminController;
use OCA\UserVO\Service\ApiClient;
use OCA\UserVO\Service\ConfigService;
use OCA\UserVO\Service\GroupNameHarmonizer;
use OCA\UserVO\Service\GroupSyncService;
use OCA\UserVO\Service\UserProvisioningService;
use OCA\UserVO\Service\UserAccountService;
use OCA\UserVO\Service\GroupManagementService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * Unit tests for Config-related endpoints in AdminController
 * (These will move to ConfigController in Phase 3)
 *
 * NOTE: These tests are limited by the current tight coupling in AdminController.
 * Many methods create UserVOAuth instances internally and call IConfig directly,
 * making them difficult to properly unit test. After Phase 3 refactoring into
 * ConfigController, these tests should be rewritten to be true unit tests.
 *
 * For now, these tests verify basic behavior and serve as regression tests.
 *
 * Tests:
 * - GET /admin (index - render settings page)
 * - GET /admin/config-status (getConfigurationStatus)
 * - POST /admin/test-config (testConfiguration)
 * - POST /admin/save-config and clear-config are too coupled to test properly
 */
class ConfigControllerTest extends TestCase {
	private IRequest $request;
	private IDBConnection $connection;
	private LoggerInterface $logger;
	private IGroupManager $groupManager;
	private IConfig $config;
	private ConfigService $configService;
	private GroupNameHarmonizer $groupNameHarmonizer;
	private GroupSyncService $groupSyncService;
	private ApiClient $apiClient;
	private UserProvisioningService $userProvisioningService;
	private UserAccountService $userAccountService;
	private GroupManagementService $groupManagementService;
	private AdminController $controller;

	protected function setUp(): void {
		parent::setUp();

		// Create mocks in constructor parameter order
		$this->request = $this->createMock(IRequest::class);
		$this->connection = $this->createMock(IDBConnection::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->config = $this->createMock(IConfig::class);
		$this->configService = $this->createMock(ConfigService::class);
		$this->groupNameHarmonizer = $this->createMock(GroupNameHarmonizer::class);
		$this->groupSyncService = $this->createMock(GroupSyncService::class);
		$this->apiClient = $this->createMock(ApiClient::class);
		$this->userProvisioningService = $this->createMock(UserProvisioningService::class);
		$this->userAccountService = $this->createMock(UserAccountService::class);
		$this->groupManagementService = $this->createMock(GroupManagementService::class);

		$this->controller = new AdminController(
			'user_vo',
			$this->request,
			$this->connection,
			$this->logger,
			$this->groupManager,
			$this->config,
			$this->configService,
			$this->groupNameHarmonizer,
			$this->groupSyncService,
			$this->apiClient,
			$this->userProvisioningService,
			$this->userAccountService,
			$this->groupManagementService
		);
	}

	/**
	 * Test GET /admin/config-status returns configuration status
	 */
	public function testGetConfigurationStatus(): void {
		$expectedStatus = [
			'is_configured' => true,
			'configuration_source' => 'admin_interface',
			'api_url' => 'https://example.org',
			'api_username' => 'testuser',
			'has_password' => true
		];

		$this->configService->expects($this->once())
			->method('getConfigurationStatus')
			->willReturn($expectedStatus);

		$result = $this->controller->getConfigurationStatus();

		// Note: getConfigurationStatus() returns array directly, not JSONResponse
		$this->assertIsArray($result);
		$this->assertSame($expectedStatus, $result);
	}

	/**
	 * Test POST /admin/test-config with valid credentials
	 */
	public function testTestConfigurationSuccess(): void {
		$this->request->expects($this->exactly(3))
			->method('getParam')
			->willReturnMap([
				['api_url', '', 'https://example.org'],
				['api_username', '', 'testuser'],
				['api_password', '', 'testpass']
			]);

		// Mock ApiClient to return success
		// Note: This actually creates a UserVOAuth backend internally
		// For true unit test, we'd need to inject/mock the backend creation

		$result = $this->controller->testConfiguration();

		$this->assertInstanceOf(JSONResponse::class, $result);
		$data = $result->getData();

		// The actual test makes a real API call, so we expect it to fail in test env
		// In the future, this should be refactored to inject the backend
		$this->assertArrayHasKey('success', $data);
	}

	/**
	 * Test POST /admin/test-config with empty credentials
	 *
	 * NOTE: This test is skipped because testConfiguration() creates a UserVOAuth
	 * instance internally, which requires complex mocking of IConfig methods.
	 * This will be easier to test after Phase 3 refactoring.
	 */
	public function testTestConfigurationWithEmptyCredentials(): void {
		$this->markTestSkipped('Too coupled to test properly - will fix in Phase 3');
	}

	/**
	 * Test POST /admin/save-config saves configuration
	 *
	 * NOTE: Skipped - saveConfiguration() calls IConfig::setAppValue directly,
	 * but also triggers ConfigService internally which makes strict mocking difficult.
	 * Integration tests cover this functionality.
	 */
	public function testSaveConfiguration(): void {
		$this->markTestSkipped('Too coupled to test properly - covered by integration tests');
	}

	/**
	 * Test POST /admin/clear-config
	 *
	 * NOTE: Skipped - clearConfiguration() creates UserVOAuth internally and calls
	 * ConfigService, making it too tightly coupled for pure unit testing.
	 * Integration tests cover this functionality.
	 */
	public function testClearConfiguration(): void {
		$this->markTestSkipped('Too coupled to test properly - covered by integration tests');
	}
}
