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
 * VerifyLogin is called at most once per test run (cached via
 * resolveTestMemberId()) regardless of how many tests need the resulting VO
 * member ID - minimizes load on the real API and avoids the small residual
 * risk repeated login attempts carry, even with a correct password.
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
}
