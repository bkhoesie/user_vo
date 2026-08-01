<?php
namespace OCA\UserVO\Tests\Integration\Service;

use OCA\UserVO\Service\UserAccountService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * Integration tests for UserAccountService.scanDuplicates()'s grouping/
 * canonical-detection logic and findCanonicalUser() directly - previously
 * only indirectly exercised via UserAccountControllerTest, and only for the
 * "no duplicates" case. Directly manipulates the accounts table (bypassing
 * IUserManager::createUser(), which wouldn't allow case-variant duplicates
 * under a single backend) to precisely simulate the scenario this logic
 * exists to handle.
 *
 * @group DB
 */
class UserAccountServiceTest extends TestCase {
	private const UID_PREFIX = 'zzz_test_uas_';

	private UserAccountService $service;
	private IDBConnection $connection;

	protected function setUp(): void {
		parent::setUp();

		$this->connection = \OC::$server->get(IDBConnection::class);
		$this->service = new UserAccountService(
			$this->connection,
			\OC::$server->get(IGroupManager::class),
			\OC::$server->get(IConfig::class),
			\OC::$server->get(LoggerInterface::class)
		);

		$this->cleanupTestData();
	}

	protected function tearDown(): void {
		$this->cleanupTestData();
		parent::tearDown();
	}

	private function cleanupTestData(): void {
		// LOWER()-wrapped match throughout: uid comparisons in this table are
		// case-sensitive at the DB level (deliberately, per the plugin's own
		// case-sensitivity handling), and this test creates case-variant uids.
		$qb = $this->connection->getQueryBuilder();
		$qb->delete('user_vo')
			->where($qb->expr()->like($qb->func()->lower('uid'), $qb->createNamedParameter(self::UID_PREFIX . '%')))
			->executeStatement();

		$qb = $this->connection->getQueryBuilder();
		$qb->delete('accounts')
			->where($qb->expr()->like($qb->func()->lower('uid'), $qb->createNamedParameter(self::UID_PREFIX . '%')))
			->executeStatement();
	}

	private function insertAccount(string $uid): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->insert('accounts')->values([
			'uid' => $qb->createNamedParameter($uid),
			'data' => $qb->createNamedParameter('[]'),
		])->executeStatement();
	}

	private function insertUserVo(string $uid, string $displayName = ''): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->insert('user_vo')->values([
			'uid' => $qb->createNamedParameter($uid),
			'backend' => $qb->createNamedParameter('user_vo'),
			'displayname' => $qb->createNamedParameter($displayName),
		])->executeStatement();
	}

	public function testScanDuplicatesGroupsCaseVariantsAndIdentifiesCanonical(): void {
		$canonicalUid = self::UID_PREFIX . 'dupuser';
		$variantUid = 'Zzz_Test_Uas_DupUser'; // same normalized form, different case

		$this->insertAccount($canonicalUid);
		$this->insertAccount($variantUid);
		$this->insertUserVo($canonicalUid, 'Canonical Display Name');

		$result = $this->service->scanDuplicates();

		$this->assertTrue($result['success']);
		$this->assertEquals(1, $result['summary']['duplicateSets']);

		$group = $result['duplicateSets'][0];
		$this->assertEquals(strtolower($canonicalUid), $group['normalized_uid']);
		$this->assertCount(2, $group['variants']);

		$canonicalVariant = $this->findVariant($group['variants'], $canonicalUid);
		$this->assertTrue($canonicalVariant['is_canonical']);
		$this->assertEquals('Canonical Display Name', $canonicalVariant['displayname']);

		$otherVariant = $this->findVariant($group['variants'], $variantUid);
		$this->assertFalse($otherVariant['is_canonical']);
	}

	public function testScanDuplicatesReportsMarkedDuplicateFlag(): void {
		$canonicalUid = self::UID_PREFIX . 'markeduser';
		$variantUid = 'Zzz_Test_Uas_MarkedUser';

		$this->insertAccount($canonicalUid);
		$this->insertAccount($variantUid);
		$this->insertUserVo($canonicalUid);
		$this->insertUserVo($variantUid . '!duplicate');

		$result = $this->service->scanDuplicates();

		$group = $this->findGroupByNormalizedUid($result, strtolower($canonicalUid));
		$markedVariant = $this->findVariant($group['variants'], $variantUid);
		$this->assertTrue($markedVariant['is_marked_duplicate']);
		$this->assertTrue($markedVariant['is_exposed']);
	}

	public function testScanDuplicatesNoGroupForSingleVariant(): void {
		$uid = self::UID_PREFIX . 'lone';
		$this->insertAccount($uid);
		$this->insertUserVo($uid);

		$result = $this->service->scanDuplicates();

		$normalizedUids = array_column($result['duplicateSets'], 'normalized_uid');
		$this->assertNotContains(strtolower($uid), $normalizedUids, 'A single variant must not be reported as a duplicate set');
	}

	private function findVariant(array $variants, string $uid): array {
		foreach ($variants as $v) {
			if ($v['uid'] === $uid) {
				return $v;
			}
		}
		$this->fail("No variant found for uid $uid");
	}

	private function findGroupByNormalizedUid(array $result, string $normalizedUid): array {
		foreach ($result['duplicateSets'] as $group) {
			if ($group['normalized_uid'] === $normalizedUid) {
				return $group;
			}
		}
		$this->fail("No duplicate group found for normalized uid $normalizedUid");
	}

	// --- findCanonicalUser() ---

	public function testFindCanonicalUserReturnsUnmarkedEntry(): void {
		$uid = self::UID_PREFIX . 'canon';
		$this->insertUserVo($uid);
		$this->insertUserVo($uid . '!duplicate');

		$canonical = $this->service->findCanonicalUser(strtolower($uid));

		$this->assertEquals($uid, $canonical);
	}

	public function testFindCanonicalUserReturnsNullWhenNoneExists(): void {
		$canonical = $this->service->findCanonicalUser(strtolower(self::UID_PREFIX . 'nobody'));
		$this->assertNull($canonical);
	}
}
