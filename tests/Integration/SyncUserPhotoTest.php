<?php
namespace OCA\UserVO\Tests\Integration;

use OCA\UserVO\UserVOAuth;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAvatarManager;
use OCP\IUserManager;
use Test\TestCase;

/**
 * Integration tests for UserVOAuth::syncUserPhoto() - downloads a member's
 * photo from VereinOnline and sets it as their NC avatar. This is the exact
 * method where the original NC33 production incident happened
 * (\OC\Server::getAvatarManager() removed upstream) and had zero test
 * coverage before this - previously untestable because its raw curl_* calls
 * weren't mockable (fixed alongside ApiClient's IClientService refactor).
 *
 * Overrides the real IClientService binding in NC's server container for the
 * duration of each test (restored in tearDown): syncUserPhoto() resolves it
 * via \OC::$server->get() internally rather than constructor injection -
 * UserVOAuth is manually constructed in ~10 places throughout the app
 * (Application.php, several controllers, GroupSyncService, SyncUsersJob), so
 * adding a required constructor parameter just for this one method wasn't a
 * proportionate change.
 *
 * @group DB
 */
class SyncUserPhotoTest extends TestCase {
	private const UID_PREFIX = 'zzz_test_photo_';

	private IUserManager $userManager;
	private ?IClientService $originalClientService = null;

	protected function setUp(): void {
		parent::setUp();
		$this->userManager = \OC::$server->get(IUserManager::class);
		$this->cleanupTestData();
	}

	protected function tearDown(): void {
		if ($this->originalClientService !== null) {
			$original = $this->originalClientService;
			\OC::$server->registerService(IClientService::class, fn () => $original);
			$this->originalClientService = null;
		}
		$this->cleanupTestData();
		parent::tearDown();
	}

	private function cleanupTestData(): void {
		foreach ($this->userManager->search(self::UID_PREFIX) as $user) {
			$user->delete();
		}
	}

	/** Registers a mock IClientService for the rest of this test, returns the mock IClient to configure. */
	private function mockClientService(): IClient {
		$this->originalClientService = \OC::$server->get(IClientService::class);

		$client = $this->createMock(IClient::class);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);
		\OC::$server->registerService(IClientService::class, fn () => $clientService);

		return $client;
	}

	private function mockResponse(int $statusCode, $body, array $headers = []): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($statusCode);
		$response->method('getBody')->willReturn($body);
		$response->method('getHeader')->willReturnCallback(fn ($key) => $headers[$key] ?? '');
		return $response;
	}

	private function generatePng(int $width, int $height): string {
		$image = imagecreatetruecolor($width, $height);
		ob_start();
		imagepng($image);
		$bytes = ob_get_clean();
		imagedestroy($image);
		return $bytes;
	}

	private function callSyncUserPhoto(UserVOAuth $auth, string $uid, string $photoUrl): array {
		$ref = new \ReflectionMethod(UserVOAuth::class, 'syncUserPhoto');
		$ref->setAccessible(true);
		return $ref->invoke($auth, $uid, $photoUrl);
	}

	private function createAuth(): UserVOAuth {
		return new UserVOAuth('https://vo.test/org', 'apiuser', 'apipass');
	}

	public function testRejectsUrlNotFromVereinonline(): void {
		$result = $this->callSyncUserPhoto($this->createAuth(), self::UID_PREFIX . 'x', 'https://evil.example/photo.jpg');

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('Invalid URL', $result['message']);
	}

	public function testFailsWhenHeadRequestThrows(): void {
		$client = $this->mockClientService();
		$client->method('head')->willThrowException(new \Exception('DNS failure'));

		$result = $this->callSyncUserPhoto($this->createAuth(), self::UID_PREFIX . 'x', 'https://vereinonline.org/photo.jpg');

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('not accessible', $result['message']);
	}

	public function testFailsWhenHeadReturnsNon200(): void {
		$client = $this->mockClientService();
		$client->method('head')->willReturn($this->mockResponse(404, ''));

		$result = $this->callSyncUserPhoto($this->createAuth(), self::UID_PREFIX . 'x', 'https://vereinonline.org/photo.jpg');

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('HTTP 404', $result['message']);
	}

	public function testFailsWhenPhotoTooLarge(): void {
		$client = $this->mockClientService();
		$client->method('head')->willReturn($this->mockResponse(200, '', ['Content-Length' => (string)(11 * 1024 * 1024)]));

		$result = $this->callSyncUserPhoto($this->createAuth(), self::UID_PREFIX . 'x', 'https://vereinonline.org/photo.jpg');

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('too large', $result['message']);
	}

	public function testFailsWhenGetRequestThrows(): void {
		$client = $this->mockClientService();
		$client->method('head')->willReturn($this->mockResponse(200, '', ['Content-Length' => '100']));
		$client->method('get')->willThrowException(new \Exception('Connection reset'));

		$result = $this->callSyncUserPhoto($this->createAuth(), self::UID_PREFIX . 'x', 'https://vereinonline.org/photo.jpg');

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('Download failed', $result['message']);
	}

	public function testFailsWhenGetReturnsNon200(): void {
		$client = $this->mockClientService();
		$client->method('head')->willReturn($this->mockResponse(200, '', ['Content-Length' => '100']));
		$client->method('get')->willReturn($this->mockResponse(500, ''));

		$result = $this->callSyncUserPhoto($this->createAuth(), self::UID_PREFIX . 'x', 'https://vereinonline.org/photo.jpg');

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('Download failed', $result['message']);
	}

	public function testFailsWhenDownloadedContentIsNotAnImage(): void {
		$client = $this->mockClientService();
		$client->method('head')->willReturn($this->mockResponse(200, '', ['Content-Length' => '20']));
		$client->method('get')->willReturn($this->mockResponse(200, 'not actually an image'));

		$result = $this->callSyncUserPhoto($this->createAuth(), self::UID_PREFIX . 'x', 'https://vereinonline.org/photo.jpg');

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('Not an image', $result['message']);
	}

	public function testFailsWhenUserDoesNotExist(): void {
		$png = $this->generatePng(2, 2);
		$client = $this->mockClientService();
		$client->method('head')->willReturn($this->mockResponse(200, '', ['Content-Length' => (string)strlen($png)]));
		$client->method('get')->willReturn($this->mockResponse(200, $png));

		$result = $this->callSyncUserPhoto($this->createAuth(), self::UID_PREFIX . 'nonexistent', 'https://vereinonline.org/photo.jpg');

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('User not found', $result['message']);
	}

	public function testSucceedsAndSetsAvatarForRealUser(): void {
		$uid = self::UID_PREFIX . 'realuser';
		$this->userManager->createUser($uid, 'ATestPassword123!');

		$png = $this->generatePng(2, 2);
		$client = $this->mockClientService();
		$client->method('head')->willReturn($this->mockResponse(200, '', ['Content-Length' => (string)strlen($png)]));
		$client->method('get')->willReturn($this->mockResponse(200, $png));

		$result = $this->callSyncUserPhoto($this->createAuth(), $uid, 'https://vereinonline.org/photo.jpg');

		$this->assertTrue($result['success'], $result['message'] ?? '');
		$avatar = \OC::$server->get(IAvatarManager::class)->getAvatar($uid);
		$this->assertTrue($avatar->exists());
	}

	public function testCropsNonSquareImageBeforeSettingAvatar(): void {
		$uid = self::UID_PREFIX . 'rectuser';
		$this->userManager->createUser($uid, 'ATestPassword123!');

		// A wide, non-square image - must be cropped to a square avatar.
		$png = $this->generatePng(6, 2);
		$client = $this->mockClientService();
		$client->method('head')->willReturn($this->mockResponse(200, '', ['Content-Length' => (string)strlen($png)]));
		$client->method('get')->willReturn($this->mockResponse(200, $png));

		$result = $this->callSyncUserPhoto($this->createAuth(), $uid, 'https://vereinonline.org/photo.jpg');

		$this->assertTrue($result['success'], $result['message'] ?? '');
		$avatar = \OC::$server->get(IAvatarManager::class)->getAvatar($uid);
		$this->assertTrue($avatar->exists());
	}
}
