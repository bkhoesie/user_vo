<?php
namespace OCA\UserVO\Tests\Integration\Service;

use OCA\UserVO\Service\AuditLogService;
use OCP\IDBConnection;
use Test\TestCase;

/**
 * Integration tests for AuditLogService against a real database.
 *
 * @group DB
 */
class AuditLogServiceTest extends TestCase {
	private AuditLogService $service;
	private IDBConnection $connection;

	protected function setUp(): void {
		parent::setUp();
		$this->connection = \OC::$server->get(IDBConnection::class);
		$this->service = new AuditLogService($this->connection);
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

	/** Inserts a row with an explicit created_at, bypassing log()'s always-now() timestamp - needed to test cleanupOlderThan(). */
	private function insertEntryAt(string $eventType, \DateTime $createdAt, ?string $uid = null, ?string $groupId = null, string $message = 'test message'): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->insert('user_vo_audit_log')
			->values([
				'created_at' => $qb->createNamedParameter($createdAt, 'datetime'),
				'event_type' => $qb->createNamedParameter($eventType),
				'uid' => $qb->createNamedParameter($uid),
				'group_id' => $qb->createNamedParameter($groupId),
				'message' => $qb->createNamedParameter($message),
			])
			->executeStatement();
	}

	public function testLogInsertsAllFields(): void {
		$this->service->log('test_login_failed', 'testuser', 'testgroup', 'Invalid credentials');

		$entries = $this->service->getRecentEntries();
		$entry = current(array_filter($entries, fn ($e) => $e['event_type'] === 'test_login_failed'));

		$this->assertNotFalse($entry);
		$this->assertEquals('testuser', $entry['uid']);
		$this->assertEquals('testgroup', $entry['group_id']);
		$this->assertEquals('Invalid credentials', $entry['message']);
		$this->assertNotEmpty($entry['created_at']);
	}

	public function testLogToleratesNullUidAndGroupId(): void {
		$this->service->log('test_config_changed', null, null, 'api_url changed');

		$entries = $this->service->getRecentEntries();
		$entry = current(array_filter($entries, fn ($e) => $e['event_type'] === 'test_config_changed'));

		$this->assertNotFalse($entry);
		$this->assertNull($entry['uid']);
		$this->assertNull($entry['group_id']);
	}

	public function testGetRecentEntriesOrdersNewestFirst(): void {
		$this->insertEntryAt('test_order_1', (new \DateTime())->modify('-3 seconds'));
		$this->insertEntryAt('test_order_2', (new \DateTime())->modify('-2 seconds'));
		$this->insertEntryAt('test_order_3', (new \DateTime())->modify('-1 seconds'));

		// Fetch generously and filter down to just our own rows - this table
		// is shared with the rest of the test suite (and real activity on a
		// long-lived dev instance), so a small limit could be pushed out
		// entirely by unrelated, more-recent entries from elsewhere.
		$entries = $this->service->getRecentEntries(1000);
		$orderedTestTypes = array_values(array_filter(array_column($entries, 'event_type'), fn ($t) => str_starts_with($t, 'test_order_')));
		$this->assertEquals(['test_order_3', 'test_order_2', 'test_order_1'], $orderedTestTypes);
	}

	public function testGetRecentEntriesRespectsLimit(): void {
		$this->insertEntryAt('test_limit_1', new \DateTime());
		$this->insertEntryAt('test_limit_2', new \DateTime());
		$this->insertEntryAt('test_limit_3', new \DateTime());

		$this->assertLessThanOrEqual(2, count($this->service->getRecentEntries(2)));
	}

	public function testGetAllEntriesAsTextRendersOldestFirstWithExpectedFormat(): void {
		$this->insertEntryAt('test_text_older', (new \DateTime())->modify('-10 seconds'), 'uid1', 'group1', 'older message');
		$this->insertEntryAt('test_text_newer', (new \DateTime())->modify('-5 seconds'), null, null, 'newer message');

		$text = $this->service->getAllEntriesAsText();

		$olderPos = strpos($text, 'test_text_older');
		$newerPos = strpos($text, 'test_text_newer');
		$this->assertNotFalse($olderPos);
		$this->assertNotFalse($newerPos);
		$this->assertLessThan($newerPos, $olderPos, 'Oldest entry must appear first in the downloadable text');

		$this->assertMatchesRegularExpression('/\[test_text_older\] uid=uid1 group=group1: older message/', $text);
		$this->assertMatchesRegularExpression('/\[test_text_newer\] uid=- group=-: newer message/', $text);
	}

	public function testCleanupOlderThanDeletesOnlyEntriesPastRetention(): void {
		$this->insertEntryAt('test_cleanup_old', (new \DateTime())->modify('-10 days'));
		$this->insertEntryAt('test_cleanup_recent', (new \DateTime())->modify('-1 days'));

		$deleted = $this->service->cleanupOlderThan(7);

		$this->assertGreaterThanOrEqual(1, $deleted);

		$remainingTypes = array_column($this->service->getRecentEntries(1000), 'event_type');
		$this->assertNotContains('test_cleanup_old', $remainingTypes);
		$this->assertContains('test_cleanup_recent', $remainingTypes);
	}

	/**
	 * clearAll() genuinely wipes the entire shared table by design - it's
	 * the backing action for the admin UI's "Clear Log" button, which really
	 * does mean "everything". Unlike this file's other tests, its own
	 * cleanupTestData() (which only ever deletes test_% rows) can't restore
	 * whatever else was in the table before this test ran - accepted, since
	 * that's exactly the real behavior being verified.
	 */
	public function testClearAllDeletesEveryEntryAndLogsTheClearItself(): void {
		$this->insertEntryAt('test_clear_before', new \DateTime());

		$deleted = $this->service->clearAll();
		$this->assertGreaterThanOrEqual(1, $deleted);

		$remaining = $this->service->getRecentEntries(1000);
		$this->assertCount(1, $remaining, 'Only the single fresh "cleared" entry should remain');
		$this->assertEquals('audit_log_cleared', $remaining[0]['event_type']);
	}
}
