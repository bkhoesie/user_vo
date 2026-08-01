<?php
namespace OCA\UserVO\Tests\Integration\Controller;

use OCA\UserVO\Controller\UserProvisioningController;
use OCA\UserVO\Service\ConfigService;
use OCA\UserVO\Service\UserProvisioningService;
use OCP\AppFramework\App;
use OCP\IConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * Integration tests for UserProvisioningController. All three endpoints need
 * VO for their success paths (unlike GroupSyncController, there's no
 * short-circuit before VO here), so - consistent with GroupControllerTest -
 * VO config is cleared for this class and only param-validation plus
 * graceful-failure-when-VO-unreachable paths are covered here. Success paths
 * for the provisioning logic itself are exercised manually/via smoke tests
 * against a real configured environment.
 *
 * @group DB
 */
class UserProvisioningControllerTest extends TestCase {
	private UserProvisioningController $controller;
	private IConfig $config;
	private array $originalConfig = [];

	protected function setUp(): void {
		parent::setUp();

		$app = new App('user_vo');
		$container = $app->getContainer();

		$this->controller = $container->get(UserProvisioningController::class);
		$this->config = \OC::$server->get(IConfig::class);

		foreach (['api_url', 'api_username', 'api_password'] as $key) {
			$this->originalConfig[$key] = $this->config->getAppValue('user_vo', $key, '');
			$this->config->deleteAppValue('user_vo', $key);
		}
	}

	protected function tearDown(): void {
		foreach ($this->originalConfig as $key => $value) {
			if ($value === '') {
				$this->config->deleteAppValue('user_vo', $key);
			} else {
				$this->config->setAppValue('user_vo', $key, $value);
			}
		}
		parent::tearDown();
	}

	private function controllerWithParams(array $params): UserProvisioningController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn($key, $default = null) => $params[$key] ?? $default
		);

		return new UserProvisioningController(
			'user_vo',
			$request,
			\OC::$server->get(UserProvisioningService::class),
			\OC::$server->get(ConfigService::class),
			\OC::$server->get(LoggerInterface::class)
		);
	}

	// --- Param validation ---

	public function testCreateAccountFromVOMissingIdReturns400(): void {
		$response = $this->controller->createAccountFromVO();
		$this->assertEquals(400, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testBulkCreateAccountsMissingIdsReturns400(): void {
		$response = $this->controller->bulkCreateAccountsFromVO();
		$this->assertEquals(400, $response->getStatus());
	}

	// --- Graceful failure when VO is unreachable ---

	public function testSearchVOUsersReturnsErrorWhenVOUnreachable(): void {
		$controller = $this->controllerWithParams(['search_term' => 'test']);
		$response = $controller->searchVOUsers();

		$this->assertFalse($response->getData()['success']);
		$this->assertGreaterThanOrEqual(400, $response->getStatus());
	}

	public function testCreateAccountFromVOReturnsErrorWhenVOUnreachable(): void {
		$controller = $this->controllerWithParams(['vo_user_id' => 'test_needs_vo']);
		$response = $controller->createAccountFromVO();

		$this->assertFalse($response->getData()['success']);
		$this->assertGreaterThanOrEqual(400, $response->getStatus());
	}

	public function testBulkCreateAccountsReturnsErrorsWhenVOUnreachable(): void {
		$controller = $this->controllerWithParams(['vo_user_ids' => ['test_needs_vo1', 'test_needs_vo2']]);
		$response = $controller->bulkCreateAccountsFromVO();
		$data = $response->getData();

		$this->assertEquals(2, $data['summary']['errors']);
		$this->assertEquals(0, $data['summary']['created']);
	}
}
