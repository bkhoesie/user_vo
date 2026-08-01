<?php
namespace OCA\UserVO\Tests\Integration\Controller;

use OCA\UserVO\Controller\GroupController;
use OCA\UserVO\Service\ConfigService;
use OCA\UserVO\Service\GroupManagementService;
use OCP\AppFramework\App;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * Integration tests for GroupController - the HTTP layer wrapping
 * GroupManagementService (already covered at the service level by
 * GroupManagementServiceTest). Focus here: param validation, status code
 * mapping, and response shape - exactly the layer where two real bugs
 * (bulkCreateGroups not auto-syncing, fetchAllVOGroups returning 500
 * instead of 200) went unnoticed until manual testing found them.
 *
 * createBackend() builds a UserVOAuth from whatever VO config happens to be
 * set; deliberately left unconfigured here to exercise the "VO unreachable"
 * graceful-failure paths - createGroup/bulkCreateGroups need VO and so only
 * their failure paths are covered here (success paths are already covered by
 * GroupManagementServiceTest with a mocked backend). deleteGroup/
 * bulkDeleteGroups/fetchManagedGroups never call VO at all, so their success
 * paths are fully testable here.
 *
 * VO config is deliberately cleared for the duration of every test in this
 * class (save/restore, matching the pattern already used by
 * ConfigControllerTest) so "VO unreachable" behavior is deterministic
 * regardless of whether the environment running these tests (e.g. a
 * long-lived dev instance like stable33) happens to have real credentials
 * saved.
 *
 * @group DB
 */
class GroupControllerTest extends TestCase {
	private GroupController $controller;
	private IDBConnection $connection;
	private IGroupManager $groupManager;
	private IConfig $config;
	private array $originalConfig = [];

	protected function setUp(): void {
		parent::setUp();

		$app = new App('user_vo');
		$container = $app->getContainer();

		$this->controller = $container->get(GroupController::class);
		$this->connection = \OC::$server->get(IDBConnection::class);
		$this->groupManager = \OC::$server->get(IGroupManager::class);
		$this->config = \OC::$server->get(IConfig::class);

		foreach (['api_url', 'api_username', 'api_password'] as $key) {
			$this->originalConfig[$key] = $this->config->getAppValue('user_vo', $key, '');
			$this->config->deleteAppValue('user_vo', $key);
		}

		$this->cleanupTestData();
	}

	protected function tearDown(): void {
		foreach ($this->originalConfig as $key => $value) {
			if ($value === '') {
				$this->config->deleteAppValue('user_vo', $key);
			} else {
				$this->config->setAppValue('user_vo', $key, $value);
			}
		}

		$this->cleanupTestData();
		parent::tearDown();
	}

	private function cleanupTestData(): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->delete('user_vo_groups')
			->where($qb->expr()->like('vo_group_id', $qb->createNamedParameter('test_%')))
			->executeStatement();

		foreach ($this->groupManager->search('uservo_test_') as $group) {
			$group->delete();
		}
	}

	private function createTestGroup(string $voGroupId, string $name): string {
		$ncGroupId = 'uservo_' . $voGroupId;
		$qb = $this->connection->getQueryBuilder();
		$qb->insert('user_vo_groups')
			->values([
				'vo_group_id' => $qb->createNamedParameter($voGroupId),
				'vo_group_name' => $qb->createNamedParameter($name),
				'nc_group_id' => $qb->createNamedParameter($ncGroupId),
				'nc_display_name' => $qb->createNamedParameter($name),
				'vo_position_index' => $qb->createNamedParameter('1'),
				'vo_parent_id' => $qb->createNamedParameter(null),
				'vo_position' => $qb->createNamedParameter(1, \PDO::PARAM_INT),
				'deleted_in_vo' => $qb->createNamedParameter(0, \PDO::PARAM_INT),
				'member_count' => $qb->createNamedParameter(0, \PDO::PARAM_INT),
				'vo_member_count' => $qb->createNamedParameter(0, \PDO::PARAM_INT),
				'non_vo_member_count' => $qb->createNamedParameter(0, \PDO::PARAM_INT),
			])
			->executeStatement();
		$this->groupManager->createGroup($ncGroupId);
		return $ncGroupId;
	}

	/** Build a controller with a mocked IRequest carrying the given params, real services otherwise. */
	private function controllerWithParams(array $params): GroupController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn($key, $default = null) => $params[$key] ?? $default
		);

		return new GroupController(
			'user_vo',
			$request,
			\OC::$server->get(GroupManagementService::class),
			\OC::$server->get(ConfigService::class),
			\OC::$server->get(LoggerInterface::class)
		);
	}

	// --- Param validation (doesn't reach the service layer at all) ---

	public function testCreateGroupMissingIdReturns400(): void {
		$response = $this->controller->createGroup();
		$this->assertEquals(400, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testDeleteGroupMissingIdReturns400(): void {
		$response = $this->controller->deleteGroup();
		$this->assertEquals(400, $response->getStatus());
	}

	public function testBulkCreateGroupsMissingIdsReturns400(): void {
		$response = $this->controller->bulkCreateGroups();
		$this->assertEquals(400, $response->getStatus());
	}

	public function testBulkDeleteGroupsMissingIdsReturns400(): void {
		$response = $this->controller->bulkDeleteGroups();
		$this->assertEquals(400, $response->getStatus());
	}

	// --- fetchAllVOGroups: regression test for today's 500-vs-200 fix ---

	public function testFetchAllVOGroupsReturns200WithGracefulFailureWhenVOUnreachable(): void {
		$response = $this->controller->fetchAllVOGroups();

		$this->assertEquals(200, $response->getStatus(), 'Must be 200 even when VO is unreachable, consistent with every sibling endpoint');
		$this->assertFalse($response->getData()['success']);
	}

	// --- fetchManagedGroups: never touches VO, local-DB-only ---
	// Note: doesn't assert the list is exactly empty/contains-only-N-items -
	// this suite may run against a long-lived dev instance (e.g. stable33)
	// with real pre-existing managed groups, not just a fresh CI instance.

	public function testFetchManagedGroupsReturnsExistingManagedGroup(): void {
		$this->createTestGroup('test_fmg1', 'Test Group');

		$response = $this->controller->fetchManagedGroups();

		$groupIds = array_column($response->getData()['groups'], 'vo_group_id');
		$this->assertContains('test_fmg1', $groupIds);
	}

	// --- deleteGroup / bulkDeleteGroups: never touch VO, fully testable ---

	public function testDeleteGroupRemovesManagedGroup(): void {
		$ncGroupId = $this->createTestGroup('test_del1', 'Delete Me');
		$this->assertTrue($this->groupManager->groupExists($ncGroupId));

		$controller = $this->controllerWithParams(['vo_group_id' => 'test_del1']);
		$response = $controller->deleteGroup();

		$this->assertEquals(200, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
		$this->assertFalse($this->groupManager->groupExists($ncGroupId));
	}

	public function testDeleteGroupNonExistentReturns404(): void {
		$controller = $this->controllerWithParams(['vo_group_id' => 'test_never_existed']);
		$response = $controller->deleteGroup();

		$this->assertEquals(404, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testBulkDeleteGroupsHandlesMixOfExistingAndMissing(): void {
		$this->createTestGroup('test_bd1', 'Bulk Delete Me');

		$controller = $this->controllerWithParams(['vo_group_ids' => ['test_bd1', 'test_never_existed']]);
		$response = $controller->bulkDeleteGroups();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertEquals(1, $data['summary']['deleted']);
		$this->assertEquals(1, $data['summary']['errors']);
	}

	// --- createGroup / bulkCreateGroups: need VO, only failure paths testable here ---

	public function testCreateGroupReturnsErrorStatusWhenVOUnreachable(): void {
		$controller = $this->controllerWithParams(['vo_group_id' => 'test_needs_vo']);
		$response = $controller->createGroup();

		$this->assertFalse($response->getData()['success']);
		$this->assertGreaterThanOrEqual(400, $response->getStatus());
	}

	public function testBulkCreateGroupsReturnsErrorsWhenVOUnreachable(): void {
		$controller = $this->controllerWithParams(['vo_group_ids' => ['test_needs_vo1', 'test_needs_vo2']]);
		$response = $controller->bulkCreateGroups();
		$data = $response->getData();

		$this->assertTrue($data['success'], 'Envelope success is true even though individual items errored - matches bulkDeleteGroups behavior');
		$this->assertEquals(2, $data['summary']['errors']);
		$this->assertEquals(0, $data['summary']['created']);
	}
}
