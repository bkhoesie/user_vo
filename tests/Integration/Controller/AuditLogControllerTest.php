<?php
namespace OCA\UserVO\Tests\Integration\Controller;

use OCA\UserVO\Controller\AuditLogController;
use OCA\UserVO\Service\AuditLogService;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IDBConnection;
use OCP\IRequest;
use Test\TestCase;

/**
 * Integration tests for AuditLogController - thin HTTP wrapper over
 * AuditLogService (already covered directly by AuditLogServiceTest). Focus
 * here: response shape and that each endpoint actually reaches the service.
 *
 * @group DB
 */
class AuditLogControllerTest extends TestCase {
	private AuditLogController $controller;
	private AuditLogService $auditLogService;
	private IDBConnection $connection;

	protected function setUp(): void {
		parent::setUp();

		$this->connection = \OC::$server->get(IDBConnection::class);
		$this->auditLogService = new AuditLogService($this->connection);
		$this->controller = new AuditLogController(
			'user_vo',
			$this->createMock(IRequest::class),
			$this->auditLogService
		);

		$this->cleanupTestData();
	}

	protected function tearDown(): void {
		$this->cleanupTestData();
		parent::tearDown();
	}

	private function cleanupTestData(): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->delete('user_vo_audit_log')
			->where($qb->expr()->like('event_type', $qb->createNamedParameter('test_%')))
			->executeStatement();
	}

	public function testFetchRecentReturnsLoggedEntries(): void {
		$this->auditLogService->log('test_ctrl_fetch', 'someuid', null, 'a test message');

		$response = $this->controller->fetchRecent();
		$this->assertInstanceOf(JSONResponse::class, $response);

		$data = $response->getData();
		$this->assertTrue($data['success']);
		$eventTypes = array_column($data['entries'], 'event_type');
		$this->assertContains('test_ctrl_fetch', $eventTypes);
	}

	public function testDownloadReturnsTextFileWithLoggedEntries(): void {
		$this->auditLogService->log('test_ctrl_download', null, null, 'download me');

		$response = $this->controller->download();
		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$this->assertStringContainsString('test_ctrl_download', $response->render());
	}

	/**
	 * clear() wipes the whole shared table by design (see
	 * AuditLogServiceTest::testClearAllDeletesEveryEntryAndLogsTheClearItself
	 * for why that's accepted here, not just tolerated).
	 */
	public function testClearRemovesEntriesAndReportsCount(): void {
		$this->auditLogService->log('test_ctrl_clear', null, null, 'to be cleared');

		$response = $this->controller->clear();
		$this->assertInstanceOf(JSONResponse::class, $response);

		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertGreaterThanOrEqual(1, $data['deleted']);

		$eventTypes = array_column($this->auditLogService->getRecentEntries(1000), 'event_type');
		$this->assertNotContains('test_ctrl_clear', $eventTypes);
		$this->assertContains('audit_log_cleared', $eventTypes);
	}
}
