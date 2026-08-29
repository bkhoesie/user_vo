<?php
namespace OCA\UserVO\Tests\LiveApi;

use OCA\UserVO\Service\ApiClient;
use OCA\UserVO\UserVOAuth;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * Live contract tests against the real VereinOnline API.
 *
 * No isolated VO sandbox org exists - these run against the real production
 * org using a dedicated, synthetic test API account + test member account +
 * test group (see .env.vo-test.example / tests/run-live-api-tests.sh for
 * local setup, or .github/workflows/live-api-tests.yml for CI). Scope is
 * deliberately read-only: VerifyLogin with known-good credentials, GetMember,
 * GetMembers, groups listing, and fetching the test member's real photo
 * (uploaded specifically to exercise this - see testFetchesRealMemberPhoto()).
 * Deliberately NOT testing a wrong-password path - real VO lockout risk on
 * repeated failed attempts against a real account, not worth it for a
 * contract test.
 *
 * Skipped entirely (not failed) when the VO_TEST_* environment variables
 * aren't set - this suite is opt-in, not part of the regular unit/integration
 * runs, so it's safe for it to simply be absent in most environments.
 *
 * VerifyLogin with the real test member's credentials is called at most once
 * per test run (cached via resolveTestMemberId()) regardless of how many
 * tests need the resulting VO member ID - minimizes load on the real API and
 * avoids the small residual risk repeated login attempts carry, even with a
 * correct password. testVerifyLoginReturnsEmptyIdEntryForNonexistentUser()
 * below makes a second, separate VerifyLogin call, deliberately - it carries
 * no such risk (the username is nonexistent, so there's no real account to
 * affect) and is exactly the request production already issues on every
 * "Test Configuration" click.
 */
class VoApiContractTest extends TestCase {
	private static array $env;
	private static ?string $memberId = null;
	private static bool $loginAttempted = false;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		self::$env = [
			'url' => getenv('VO_TEST_API_URL') ?: null,
			'api_username' => getenv('VO_TEST_API_USERNAME') ?: null,
			'api_password' => getenv('VO_TEST_API_PASSWORD') ?: null,
			'member_username' => getenv('VO_TEST_MEMBER_USERNAME') ?: null,
			'member_password' => getenv('VO_TEST_MEMBER_PASSWORD') ?: null,
		];
	}

	protected function setUp(): void {
		parent::setUp();
		if (in_array(null, self::$env, true)) {
			$this->markTestSkipped(
				'VO_TEST_* environment variables not set - run via tests/run-live-api-tests.sh, ' .
				'or see .github/workflows/live-api-tests.yml for the CI equivalent.'
			);
		}
	}

	private function createBackend(): UserVOAuth {
		return new UserVOAuth(self::$env['url'], self::$env['api_username'], self::$env['api_password']);
	}

	/** Resolves (once per test run, cached) the test member's VO ID via a real VerifyLogin call. */
	private function resolveTestMemberId(): string {
		if (self::$loginAttempted) {
			$this->assertNotNull(self::$memberId, 'VerifyLogin already failed earlier in this test run');
			return self::$memberId;
		}
		self::$loginAttempted = true;

		$apiClient = new ApiClient(\OC::$server->get(LoggerInterface::class), \OC::$server->get(IClientService::class));
		$token = $apiClient->createToken(self::$env['api_username'], self::$env['api_password']);
		$result = $apiClient->makeRequest(
			rtrim(self::$env['url'], '/') . '/?api=VerifyLogin',
			['user' => self::$env['member_username'], 'password' => self::$env['member_password'], 'result' => 'id'],
			$token
		);
		self::$memberId = (string)($result[0] ?? '');
		$this->assertNotSame('', self::$memberId, 'VerifyLogin should return a non-empty VO member ID for known-good test credentials');
		return self::$memberId;
	}

	public function testVerifyLoginSucceedsWithKnownGoodCredentials(): void {
		$memberId = $this->resolveTestMemberId();
		$this->assertMatchesRegularExpression('/^\d+$/', $memberId);
	}

	/**
	 * Pins the exact response shape ConfigController::testApiConnection()
	 * relies on for its "connection successful" verdict when testing with a
	 * deliberately nonexistent user (exactly what that method itself does
	 * in production on every "Test Configuration" click, so this carries no
	 * additional real-account/lockout risk beyond what already happens
	 * there) - VO is documented to return `[""]` in this case, not e.g. an
	 * empty array or an {"error": ...} object. If VO's behavior here ever
	 * changes, admins with a correct config would start seeing "Unexpected
	 * response" on every connection test - this is the trip wire for that.
	 */
	public function testVerifyLoginReturnsEmptyIdEntryForNonexistentUser(): void {
		$apiClient = new ApiClient(\OC::$server->get(LoggerInterface::class), \OC::$server->get(IClientService::class));
		$token = $apiClient->createToken(self::$env['api_username'], self::$env['api_password']);

		$result = $apiClient->makeRequest(
			rtrim(self::$env['url'], '/') . '/?api=VerifyLogin',
			['user' => 'test_user_that_should_not_exist', 'password' => 'dummy_password', 'result' => 'id'],
			$token
		);

		$this->assertIsArray($result);
		$this->assertArrayHasKey(0, $result, 'ConfigController::testApiConnection() requires isset($response[0]) for a success verdict');
		$this->assertSame('', $result[0]);
	}

	public function testGetMemberReturnsNormalizedData(): void {
		$memberId = $this->resolveTestMemberId();
		$backend = $this->createBackend();

		$data = $backend->fetchUserDataFromVO($memberId);

		$this->assertIsArray($data);
		$this->assertArrayNotHasKey('_error', $data, 'fetchUserDataFromVO reported: ' . ($data['_message'] ?? ''));
		$this->assertSame(self::$env['member_username'], $data['username']);
		$this->assertArrayHasKey('firstname', $data);
		$this->assertArrayHasKey('lastname', $data);
		$this->assertArrayHasKey('group_ids', $data);
		$this->assertNotEmpty($data['group_ids'], 'Test member is expected to have at least one VO group');
	}

	public function testGetMembersListsMembersIncludingTestAccount(): void {
		$memberId = $this->resolveTestMemberId();
		$backend = $this->createBackend();

		$members = $backend->fetchAllMembers();

		$this->assertIsArray($members);
		$this->assertNotEmpty($members);
		$ids = array_column($members, 'id');
		$this->assertContains($memberId, $ids, 'Test member should appear in the GetMembers listing');
	}

	public function testFetchesRealMemberPhoto(): void {
		$memberId = $this->resolveTestMemberId();
		$backend = $this->createBackend();

		$memberData = $backend->fetchUserDataFromVO($memberId);
		$foto = $memberData['foto'] ?? '';
		$this->assertNotSame('', $foto, 'Test member is expected to have a real (non-default) photo set for this check');
		$this->assertNotSame('anonym.gif', $foto, 'Test member should have a real uploaded photo, not the VO default placeholder');

		// Same URL construction as UserVOAuth::syncUserData() - verifying only
		// that VO's photo-serving endpoint itself works as our app assumes
		// (right status, real image bytes), not the app's own download/
		// validation logic (already covered by the mocked
		// tests/Integration/SyncUserPhotoTest.php).
		$photoUrl = rtrim(self::$env['url'], '/') . '/fotos/' . $foto;
		$response = \OC::$server->get(IClientService::class)->newClient()->get($photoUrl, [
			'timeout' => 10,
			'http_errors' => false,
		]);

		$this->assertSame(200, $response->getStatusCode(), "VO photo endpoint should return 200 for $photoUrl");
		$body = $response->getBody();
		$this->assertIsString($body);
		$this->assertNotEmpty($body);

		$mimeType = (new \finfo(FILEINFO_MIME_TYPE))->buffer($body);
		$this->assertStringStartsWith('image/', $mimeType, "VO should serve real image bytes for the test member's photo");
	}

	public function testGetGroupsListsGroupsIncludingTestMemberGroup(): void {
		$memberId = $this->resolveTestMemberId();
		$backend = $this->createBackend();

		$memberData = $backend->fetchUserDataFromVO($memberId);
		$expectedGroupIds = array_filter(explode(',', $memberData['group_ids'] ?? ''));
		$this->assertNotEmpty($expectedGroupIds, "Need at least one group ID from the test member's data to verify against GetGroups");

		$groups = $backend->fetchAllGroups();

		$this->assertIsArray($groups);
		$this->assertNotEmpty($groups);
		$groupIds = array_column($groups, 'id');
		foreach ($expectedGroupIds as $expectedId) {
			$this->assertContains($expectedId, $groupIds, "Test member's group $expectedId should appear in the GetGroups listing");
		}
	}

	// --- GetMembers(filter=gruppe=<id>): the direct-per-group fetch that
	// replaced the old vo_group_ids cache-scan. Codifies the empirical
	// findings from the redesign's own investigation into the API's actual
	// contract, so a future VO change to any of this is caught here rather
	// than only discovered in production. ---

	public function testGetMembersWithGroupFilterReturnsTheTestMembersOwnGroup(): void {
		$memberId = $this->resolveTestMemberId();
		$backend = $this->createBackend();

		$memberData = $backend->fetchUserDataFromVO($memberId);
		$groupIds = array_filter(explode(',', $memberData['group_ids'] ?? ''));
		$this->assertNotEmpty($groupIds, "Need at least one of the test member's group IDs to filter by");
		$groupId = reset($groupIds);

		$members = $backend->fetchGroupMembers($groupId);

		$this->assertIsArray($members);
		$ids = array_column($members, 'id');
		$this->assertContains($memberId, $ids, "GetMembers(filter=gruppe=$groupId) should include the test member, a direct member of that group");
	}

	/**
	 * A group's direct-member filter must not implicitly include members of
	 * its descendant groups - verified this redesign's own investigation
	 * against a real parent/child hierarchy in this org (a parent group with
	 * zero direct members of its own, all members living in its children).
	 * Rediscovers such a pair at runtime rather than hardcoding VO-internal
	 * IDs, so this stays valid if the org's test data changes shape; skips
	 * (doesn't fail) if no parent/child pair with a non-empty child
	 * currently exists to check.
	 */
	public function testGetMembersWithGroupFilterDoesNotIncludeDescendantGroupMembers(): void {
		$backend = $this->createBackend();
		$groups = $backend->fetchAllGroups();
		$this->assertIsArray($groups);

		$childrenByParent = [];
		foreach ($groups as $group) {
			if (!empty($group['parentid'])) {
				$childrenByParent[$group['parentid']][] = $group['id'];
			}
		}

		foreach ($childrenByParent as $parentId => $childIds) {
			$parentMembers = $backend->fetchGroupMembers($parentId);
			$this->assertIsArray($parentMembers, "GetMembers(filter=gruppe=$parentId) should return a well-formed list, never an error envelope");
			$parentMemberIds = array_column($parentMembers, 'id');

			foreach ($childIds as $childId) {
				$childMembers = $backend->fetchGroupMembers($childId);
				$this->assertIsArray($childMembers);
				if (empty($childMembers)) {
					continue;
				}

				$overlap = array_intersect(array_column($childMembers, 'id'), $parentMemberIds);
				$this->assertEmpty($overlap, "Parent group $parentId's direct-member filter must not include child group $childId's members: " . implode(', ', $overlap));
				return; // Found and checked one usable pair - that's the contract this test exists to pin.
			}
		}

		$this->markTestSkipped('No parent group with a non-empty child group currently exists in this test org to verify against.');
	}

	/**
	 * The exact scenario the deleted_in_vo skip rule (GroupSyncService) is
	 * built around: a filter for a group id that no longer exists in VO at
	 * all must come back as a well-formed empty list, not an error - this is
	 * precisely what makes that response indistinguishable from "this group
	 * genuinely has zero members right now", and why the skip rule exists.
	 */
	public function testGetMembersWithNonexistentGroupIdReturnsEmptyArrayNotAnError(): void {
		$backend = $this->createBackend();

		$members = $backend->fetchGroupMembers('a_deliberately_nonexistent_group_id_zzz_999999');

		$this->assertIsArray($members, 'A nonexistent group id must produce a well-formed empty list, not null/an error envelope');
		$this->assertEmpty($members);
	}

	/**
	 * Pins a finding from the redesign's investigation, corrected against
	 * this test run's actual live response (an earlier draft of this test
	 * assumed the opposite - that geloescht was never returned): GetMembers
	 * DOES include a 'geloescht' field per member when explicitly requested
	 * via the felder parameter, as a string ("0"/"1"), not a bool - relevant
	 * for any future code that might want to filter by it via felder rather
	 * than assuming VO's own deletion state isn't observable through this
	 * endpoint. fetchGroupMembers() itself doesn't currently request felder
	 * at all, so this doesn't affect its own contract.
	 */
	public function testGetMembersIncludesAGeloeschtFieldAsAStringWhenRequested(): void {
		$memberId = $this->resolveTestMemberId();
		$memberData = $this->createBackend()->fetchUserDataFromVO($memberId);
		$groupIds = array_filter(explode(',', $memberData['group_ids'] ?? ''));
		$this->assertNotEmpty($groupIds);
		$groupId = reset($groupIds);

		$apiClient = new ApiClient(\OC::$server->get(LoggerInterface::class), \OC::$server->get(IClientService::class));
		$token = $apiClient->createToken(self::$env['api_username'], self::$env['api_password']);
		$result = $apiClient->makeRequest(
			rtrim(self::$env['url'], '/') . '/?api=GetMembers',
			['filter' => "gruppe=$groupId", 'felder' => 'id,name,geloescht'],
			$token
		);

		$this->assertIsArray($result);
		$this->assertNotEmpty($result);
		foreach ($result as $member) {
			$this->assertArrayHasKey('geloescht', $member, 'An explicitly felder-requested geloescht field should be present');
			$this->assertIsString($member['geloescht'], 'geloescht comes back as a string ("0"/"1"), not a native bool - relevant to any future code parsing it');
		}
	}
}
