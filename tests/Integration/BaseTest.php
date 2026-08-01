<?php
namespace OCA\UserVO\Tests\Integration;

use OCA\UserVO\Base;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use Test\TestCase;

/**
 * Integration tests for Base - the abstract user backend class underlying
 * every login and user lookup. Uses real Nextcloud database operations.
 *
 * checkCanonicalPassword() is abstract (implemented by UserVOAuth against the
 * real VO API); these tests use a concrete test double that just records what
 * it was called with, so we can verify Base's own delegation/matching logic
 * in isolation.
 *
 * @group DB
 */
class BaseTest extends TestCase {
	private const TEST_BACKEND = 'test_base_backend';

	private IDBConnection $connection;
	private RecordingBase $base;

	protected function setUp(): void {
		parent::setUp();

		$this->connection = \OC::$server->get(IDBConnection::class);
		$this->base = new RecordingBase(self::TEST_BACKEND);
		$this->cleanupTestData();
	}

	protected function tearDown(): void {
		$this->cleanupTestData();
		parent::tearDown();
	}

	private function cleanupTestData(): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->delete('user_vo')
			->where($qb->expr()->eq('backend', $qb->createNamedParameter(self::TEST_BACKEND)))
			->executeStatement();
	}

	private function insertUser(string $uid, string $displayName = ''): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->insert('user_vo')
			->values([
				'uid' => $qb->createNamedParameter($uid),
				'backend' => $qb->createNamedParameter(self::TEST_BACKEND),
				'displayname' => $qb->createNamedParameter($displayName),
			])
			->executeStatement();
	}

	// --- userExists() ---

	public function testUserExistsTrueForExactMatch(): void {
		$this->insertUser('alice.test');
		$this->assertTrue($this->base->userExists('alice.test'));
	}

	public function testUserExistsTrueForDuplicateMarkedVariant(): void {
		$this->insertUser('alice.test!duplicate');
		$this->assertTrue($this->base->userExists('alice.test'));
	}

	public function testUserExistsFalseForUnknownUser(): void {
		$this->assertFalse($this->base->userExists('nobody.test'));
	}

	// --- storeUser() ---

	public function testStoreUserCreatesNewUser(): void {
		$this->base->storeUser('newuser.test');
		$this->assertTrue($this->base->userExists('newuser.test'));
	}

	public function testStoreUserDoesNotDuplicateExistingUser(): void {
		$this->insertUser('existing.test', 'Existing User');
		$this->base->storeUser('existing.test');

		$qb = $this->connection->getQueryBuilder();
		$qb->select('uid')->from('user_vo')
			->where($qb->expr()->eq('backend', $qb->createNamedParameter(self::TEST_BACKEND)))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter('existing.test')));
		$rows = $qb->executeQuery()->fetchAll();
		$this->assertCount(1, $rows, 'storeUser() must not insert a second row for an existing user');
	}

	public function testStoreUserForcesLowercaseForNewUsers(): void {
		$this->base->storeUser('MixedCase.Test');
		$this->assertTrue($this->base->userExists('mixedcase.test'));
		$this->assertFalse($this->base->userExists('MixedCase.Test'));
	}

	public function testStoreUserStripsDuplicateMarkerAsSafetyNet(): void {
		// storeUser() should never legitimately be called with a !duplicate uid,
		// but if it happens (bug elsewhere), it must not create a row with the
		// marker baked into the stored uid.
		$this->base->storeUser('oops.test!duplicate');
		$this->assertTrue($this->base->userExists('oops.test'));
	}

	// --- getDisplayName() / getDisplayNames() ---

	public function testGetDisplayNameReturnsStoredName(): void {
		$this->insertUser('bob.test', 'Bob Tester');
		$this->assertEquals('Bob Tester', $this->base->getDisplayName('bob.test'));
	}

	public function testGetDisplayNameFallsBackToUidWhenUnknown(): void {
		$this->assertEquals('unknown.test', $this->base->getDisplayName('unknown.test'));
	}

	public function testGetDisplayNamePrefixesDuplicateMarkedEntries(): void {
		$this->insertUser('carol.test!duplicate', 'Carol Tester');
		$this->assertEquals('(D) Carol Tester', $this->base->getDisplayName('carol.test!duplicate'));
	}

	public function testGetDisplayNamesFiltersBySearchAndStripsMarker(): void {
		$this->insertUser('dave.test', 'Dave Tester');
		$this->insertUser('erin.test', 'Erin Tester');

		$results = $this->base->getDisplayNames('Dave');

		$this->assertArrayHasKey('dave.test', $results);
		$this->assertArrayNotHasKey('erin.test', $results);
	}

	// --- getUsers() ---

	public function testGetUsersStripsDuplicateMarkerFromResults(): void {
		$this->insertUser('frank.test!duplicate');
		$users = $this->base->getUsers('frank');
		$this->assertContains('frank.test', $users);
		$this->assertNotContains('frank.test!duplicate', $users);
	}

	// --- setDisplayName() ---

	public function testSetDisplayNameUpdatesStoredName(): void {
		$this->insertUser('grace.test', 'Old Name');
		$this->base->setDisplayName('grace.test', 'New Name');
		$this->assertEquals('New Name', $this->base->getDisplayName('grace.test'));
	}

	public function testSetDisplayNameStripsIncomingDuplicatePrefix(): void {
		// NC may pass back a display name we previously returned with the "(D) "
		// prefix (see getDisplayName) - must not persist that prefix into storage.
		$this->insertUser('heidi.test!duplicate', 'Heidi Tester');
		$this->base->setDisplayName('heidi.test!duplicate', '(D) Heidi Updated');
		$this->assertEquals('(D) Heidi Updated', $this->base->getDisplayName('heidi.test!duplicate'));

		$qb = $this->connection->getQueryBuilder();
		$qb->select('displayname')->from('user_vo')
			->where($qb->expr()->eq('backend', $qb->createNamedParameter(self::TEST_BACKEND)))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter('heidi.test!duplicate')));
		$row = $qb->executeQuery()->fetch();
		$this->assertEquals('Heidi Updated', $row['displayname'], 'Stored value must not include the display-only "(D) " prefix');
	}

	// --- countUsers() ---

	public function testCountUsersFalseWhenEmpty(): void {
		$this->assertFalse($this->base->countUsers());
	}

	public function testCountUsersTrueWhenNotEmpty(): void {
		$this->insertUser('ivan.test');
		$this->assertTrue($this->base->countUsers());
	}

	// --- deleteUser() ---

	public function testDeleteUserRemovesExactAndDuplicateVariant(): void {
		$this->insertUser('judy.test');
		$this->insertUser('judy.test!duplicate');

		$this->base->deleteUser('judy.test');

		$this->assertFalse($this->base->userExists('judy.test'));
	}

	// --- checkPassword() delegation / case-insensitive matching ---
	// This is the exact logic behind the historical case-sensitivity bug
	// (see nextcloud-plugin-case-sensitivity-bug.md) - worth the most scrutiny.

	public function testCheckPasswordDelegatesDirectlyOnExactMatch(): void {
		$this->insertUser('kevin.test');
		$this->base->checkPassword('kevin.test', 'irrelevant');
		$this->assertEquals('kevin.test', $this->base->lastCheckedUid);
	}

	public function testCheckPasswordMapsToCanonicalUserForKnownVariantCasing(): void {
		// Canonical user stored lowercase; login attempt uses different casing.
		$this->insertUser('laura.test');
		$this->base->checkPassword('Laura.Test', 'irrelevant');
		$this->assertEquals('laura.test', $this->base->lastCheckedUid, 'Must delegate using the canonical (stored) uid, not the login-attempt casing');
	}

	public function testCheckPasswordIgnoresDuplicateMarkedVariantsWhenFindingCanonical(): void {
		// A !duplicate-marked entry must never be treated as canonical.
		$this->insertUser('mia.test!duplicate');
		$this->base->checkPassword('Mia.Test', 'irrelevant');
		$this->assertEquals('mia.test', $this->base->lastCheckedUid, 'Must fall through to normalized-lowercase uid, not the duplicate-marked entry');
	}

	public function testCheckPasswordUsesNormalizedLowercaseForBrandNewUser(): void {
		$this->base->checkPassword('Nathan.Test', 'irrelevant');
		$this->assertEquals('nathan.test', $this->base->lastCheckedUid);
	}
}

/**
 * Minimal concrete Base subclass for testing - records the uid
 * checkCanonicalPassword() was called with instead of hitting a real API.
 */
class RecordingBase extends Base {
	public ?string $lastCheckedUid = null;

	protected function checkCanonicalPassword($uid, $password) {
		$this->lastCheckedUid = $uid;
		return $uid;
	}
}
