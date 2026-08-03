<?php
namespace OCA\UserVO\Tests\Integration\Cron;

use OCA\UserVO\Cron\AuditLogCleanupJob;
use OCA\UserVO\Service\AuditLogService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use Test\TestCase;

/**
 * Integration tests for AuditLogCleanupJob's retention behavior.
 *
 * @group DB
 */
class AuditLogCleanupJobTest extends TestCase {
	private IDBConnection $connection;
	private IConfig $config;
	private AuditLogService $auditLogService;
	private AuditLogCleanupJob $job;
	private string $originalRetentionConfig;

	protected function setUp(): void {
		parent::setUp();

		$this->connection = \OC::$server->get(IDBConnection::class);
		$this->config = \OC::$server->get(IConfig::class);
		$this->auditLogService = new AuditLogService($this->connection);

		$this->originalRetentionConfig = $this->config->getAppValue('user_vo', 'audit_log_retention_days', '');
		$this->config->deleteAppValue('user_vo', 'audit_log_retention_days');

		$this->job = new AuditLogCleanupJob(
			\OC::$server->get(ITimeFactory::class),
			$this->config,
			$this->auditLogService
		);

		$this->cleanupTestData();
	}

	protected function tearDown(): void {
		if ($this->originalRetentionConfig === '') {
			$this->config->deleteAppValue('user_vo', 'audit_log_retention_days');
		} else {
			$this->config->setAppValue('user_vo', 'audit_log_retention_days', $this->originalRetentionConfig);
		}

		$this->cleanupTestData();
		parent::tearDown();
	}

	private function cleanupTestData(): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->delete('user_vo_audit_log')
			->where($qb->expr()->like('event_type', $qb->createNamedParameter('test_%')))
			->executeStatement();
	}

	private function insertEntryAt(string $eventType, \DateTime $createdAt): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->insert('user_vo_audit_log')
			->values([
				'created_at' => $qb->createNamedParameter($createdAt, 'datetime'),
				'event_type' => $qb->createNamedParameter($eventType),
				'uid' => $qb->createNamedParameter(null),
				'group_id' => $qb->createNamedParameter(null),
				'message' => $qb->createNamedParameter('test message'),
			])
			->executeStatement();
	}

	private function runJob(): void {
		$ref = new \ReflectionMethod(AuditLogCleanupJob::class, 'run');
		$ref->setAccessible(true);
		$ref->invoke($this->job, null);
	}

	/** @return string[] */
	private function remainingTestEventTypes(): array {
		return array_column($this->auditLogService->getRecentEntries(1000), 'event_type');
	}

	public function testDefaultRetentionDeletesEntriesOlderThanSevenDays(): void {
		$this->insertEntryAt('test_old', (new \DateTime())->modify('-8 days'));
		$this->insertEntryAt('test_recent', (new \DateTime())->modify('-1 days'));

		$this->runJob();

		$remaining = $this->remainingTestEventTypes();
		$this->assertNotContains('test_old', $remaining);
		$this->assertContains('test_recent', $remaining);
	}

	public function testConfiguredRetentionOverridesDefault(): void {
		$this->config->setAppValue('user_vo', 'audit_log_retention_days', '1');
		$this->insertEntryAt('test_two_days_old', (new \DateTime())->modify('-2 days'));
		$this->insertEntryAt('test_few_hours_old', (new \DateTime())->modify('-3 hours'));

		$this->runJob();

		$remaining = $this->remainingTestEventTypes();
		$this->assertNotContains('test_two_days_old', $remaining);
		$this->assertContains('test_few_hours_old', $remaining);
	}

	public function testNonPositiveRetentionFallsBackToDefault(): void {
		$this->config->setAppValue('user_vo', 'audit_log_retention_days', '0');
		$this->insertEntryAt('test_old_fallback', (new \DateTime())->modify('-8 days'));
		$this->insertEntryAt('test_recent_fallback', (new \DateTime())->modify('-1 days'));

		$this->runJob();

		$remaining = $this->remainingTestEventTypes();
		$this->assertNotContains('test_old_fallback', $remaining);
		$this->assertContains('test_recent_fallback', $remaining);
	}
}
