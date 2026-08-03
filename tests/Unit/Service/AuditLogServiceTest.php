<?php
namespace OCA\UserVO\Tests\Unit\Service;

use OCA\UserVO\Service\AuditLogService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AuditLogService's failure handling - a mocked IDBConnection
 * lets these force a DB error without a real database, unlike
 * AuditLogServiceTest (Integration), which covers normal read/write/cleanup
 * behavior against a real one.
 */
class AuditLogServiceTest extends TestCase {
	/**
	 * Regression test: log() is called unguarded on critical paths (login,
	 * account creation, config save - see UserVOAuth::checkCanonicalPassword,
	 * Base::insertUserRow, ConfigController::saveConfiguration) that must not
	 * fail just because the audit-log write itself failed (DB hiccup, lock
	 * contention, ...). See the doc-comment on log() for why this must stay a
	 * caught, best-effort write rather than something every caller has to
	 * guard against individually.
	 */
	public function testLogSwallowsDatabaseFailuresWithoutThrowing(): void {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('insert')->willReturnSelf();
		$qb->method('values')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturnArgument(0);
		$qb->method('executeStatement')->willThrowException(new \RuntimeException('DB connection lost'));

		$connection = $this->createMock(IDBConnection::class);
		$connection->method('getQueryBuilder')->willReturn($qb);

		$service = new AuditLogService($connection);

		// Must not throw.
		$service->log('test_event', 'someuser', null, 'test message');
		$this->addToAssertionCount(1);
	}
}
