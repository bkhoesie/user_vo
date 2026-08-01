<?php
namespace OCA\UserVO\Tests\Integration\Listener;

use OCA\UserVO\Listener\GroupDeletedListener;
use OCP\EventDispatcher\Event;
use OCP\Group\Events\GroupDeletedEvent;
use OCP\IDBConnection;
use OCP\IGroup;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * Integration tests for GroupDeletedListener - real database (user_vo_groups
 * cleanup), mocked IGroup/event.
 *
 * @group DB
 */
class GroupDeletedListenerTest extends TestCase {
	private const NC_GROUP_ID_PREFIX = 'test_gdl_';

	private GroupDeletedListener $listener;
	private IDBConnection $connection;

	protected function setUp(): void {
		parent::setUp();

		$this->connection = \OC::$server->get(IDBConnection::class);
		$this->listener = new GroupDeletedListener(
			$this->connection,
			\OC::$server->get(LoggerInterface::class)
		);

		$this->cleanupTestData();
	}

	protected function tearDown(): void {
		$this->cleanupTestData();
		parent::tearDown();
	}

	private function cleanupTestData(): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->delete('user_vo_groups')
			->where($qb->expr()->like('nc_group_id', $qb->createNamedParameter(self::NC_GROUP_ID_PREFIX . '%')))
			->executeStatement();
	}

	private function insertManagedGroup(string $ncGroupId, string $voGroupId): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->insert('user_vo_groups')->values([
			'vo_group_id' => $qb->createNamedParameter($voGroupId),
			'vo_group_name' => $qb->createNamedParameter('Test Group'),
			'nc_group_id' => $qb->createNamedParameter($ncGroupId),
		])->executeStatement();
	}

	private function groupExistsInDb(string $ncGroupId): bool {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('nc_group_id')->from('user_vo_groups')
			->where($qb->expr()->eq('nc_group_id', $qb->createNamedParameter($ncGroupId)));
		return $qb->executeQuery()->fetch() !== false;
	}

	public function testIgnoresNonGroupDeletedEvents(): void {
		$ncGroupId = self::NC_GROUP_ID_PREFIX . 'ignored';
		$this->insertManagedGroup($ncGroupId, 'vo1');

		$otherEvent = $this->createMock(Event::class);
		$this->listener->handle($otherEvent);

		$this->assertTrue($this->groupExistsInDb($ncGroupId), 'A non-GroupDeletedEvent must not trigger any cleanup');
	}

	public function testIgnoresDeletionOfUnmanagedGroup(): void {
		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn(self::NC_GROUP_ID_PREFIX . 'never_managed');
		$event = new GroupDeletedEvent($group);

		// Must not throw even though there's nothing to clean up.
		$this->listener->handle($event);
		$this->addToAssertionCount(1);
	}

	public function testRemovesManagedGroupFromTrackingTable(): void {
		$ncGroupId = self::NC_GROUP_ID_PREFIX . 'managed';
		$this->insertManagedGroup($ncGroupId, 'vo1');

		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn($ncGroupId);
		$event = new GroupDeletedEvent($group);

		$this->listener->handle($event);

		$this->assertFalse($this->groupExistsInDb($ncGroupId));
	}
}
