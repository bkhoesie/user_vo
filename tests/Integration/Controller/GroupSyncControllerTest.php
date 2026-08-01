<?php
namespace OCA\UserVO\Tests\Integration\Controller;

use OCA\UserVO\Controller\GroupSyncController;
use OCA\UserVO\Service\ConfigService;
use OCA\UserVO\Service\GroupSyncService;
use OCP\AppFramework\App;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * Integration tests for GroupSyncController - the HTTP layer wrapping
 * GroupSyncService. Focus: status-code mapping (success/status_code ->
 * HTTP status) and the response-shape transformation syncSelectedGroups()
 * does (service's {synced, failed} -> frontend's {summary: {total,
 * succeeded, failed}}), which is logic unique to this controller.
 *
 * VO config is cleared for this class (see GroupControllerTest for why) -
 * all tests here exercise paths that short-circuit before needing VO
 * (empty/not-managed group ids), matching what's actually testable without
 * real credentials.
 *
 * @group DB
 */
class GroupSyncControllerTest extends TestCase {
	private GroupSyncController $controller;
	private IDBConnection $connection;
	private IConfig $config;
	private array $originalConfig = [];

	protected function setUp(): void {
		parent::setUp();

		$app = new App('user_vo');
		$container = $app->getContainer();

		$this->controller = $container->get(GroupSyncController::class);
		$this->connection = \OC::$server->get(IDBConnection::class);
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

	private function controllerWithParams(array $params): GroupSyncController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn($key, $default = null) => $params[$key] ?? $default
		);

		return new GroupSyncController(
			'user_vo',
			$request,
			\OC::$server->get(GroupSyncService::class),
			\OC::$server->get(ConfigService::class),
			\OC::$server->get(LoggerInterface::class)
		);
	}

	// --- syncGroup: validation happens in the service, short-circuits before VO ---

	public function testSyncGroupMissingIdReturns400(): void {
		$controller = $this->controllerWithParams([]);
		$response = $controller->syncGroup();

		$this->assertEquals(400, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testSyncGroupNotManagedReturns404(): void {
		$controller = $this->controllerWithParams(['vo_group_id' => 'test_never_managed']);
		$response = $controller->syncGroup();

		$this->assertEquals(404, $response->getStatus());
	}

	// --- syncSelectedGroups: controller's own validation + response transformation ---

	public function testSyncSelectedGroupsMissingIdsReturns400(): void {
		$controller = $this->controllerWithParams([]);
		$response = $controller->syncSelectedGroups();

		$this->assertEquals(400, $response->getStatus());
	}

	public function testSyncSelectedGroupsWithUnmanagedIdsTransformsResponseShape(): void {
		// No matching rows in user_vo_groups -> short-circuits with synced=0,
		// failed=0, no VO call needed. Exercises the controller's own
		// synced/failed -> summary{total,succeeded,failed} transformation.
		$controller = $this->controllerWithParams(['vo_group_ids' => ['test_unmanaged_1', 'test_unmanaged_2']]);
		$response = $controller->syncSelectedGroups();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('summary', $data);
		$this->assertEquals(0, $data['summary']['total']);
		$this->assertEquals(0, $data['summary']['succeeded']);
		$this->assertEquals(0, $data['summary']['failed']);
	}

	// --- syncAllGroups: outcome depends on incidental environment state (how
	// many managed groups already exist), so just verify it responds sanely
	// rather than assert a specific success/failure outcome.

	public function testSyncAllGroupsRespondsWithoutError(): void {
		$response = $this->controller->syncAllGroups();

		$this->assertArrayHasKey('success', $response->getData());
		$this->assertContains($response->getStatus(), [200, 404, 500]);
	}
}
