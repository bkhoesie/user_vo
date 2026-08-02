<?php
namespace OCA\UserVO\Tests\Unit\Service;

use OCA\UserVO\Service\ApiClient;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * Unit tests for ApiClient.
 *
 * ApiClient uses raw curl_* calls directly rather than Nextcloud's injectable
 * IClientService, so most HTTP-response-handling branches (401/403, other
 * non-200) aren't mockable without either a bigger refactor or a real local
 * HTTP fixture server - out of scope here. What's covered: createToken()
 * (pure logic), the connection-failure branch via a guaranteed-refused local
 * address (127.0.0.1 on a port nothing listens on), and the non-array-JSON
 * response guard via a minimal PHP built-in server fixture (cheap enough to
 * justify for this one specific regression: a non-array 200-OK JSON body
 * used to throw an uncaught TypeError on makeRequest()'s ?array return type,
 * fatal on the login path).
 */
class ApiClientTest extends TestCase {
	private ApiClient $apiClient;

	/** @var resource|null */
	private $fixtureServerProcess = null;
	private ?string $fixtureServerDocroot = null;
	private int $fixtureServerPort = 18923;

	protected function setUp(): void {
		parent::setUp();
		$logger = $this->createMock(LoggerInterface::class);
		$this->apiClient = new ApiClient($logger);
	}

	protected function tearDown(): void {
		if ($this->fixtureServerProcess !== null) {
			proc_terminate($this->fixtureServerProcess);
			proc_close($this->fixtureServerProcess);
			$this->fixtureServerProcess = null;
		}
		if ($this->fixtureServerDocroot !== null) {
			@unlink($this->fixtureServerDocroot . '/index.php');
			@rmdir($this->fixtureServerDocroot);
			$this->fixtureServerDocroot = null;
		}
		parent::tearDown();
	}

	/**
	 * Starts a minimal PHP built-in server on 127.0.0.1 that always responds
	 * with $responseBody as the raw HTTP body (Content-Type: application/json).
	 */
	private function startFixtureServer(string $responseBody): string {
		$this->fixtureServerDocroot = sys_get_temp_dir() . '/user_vo_apiclient_test_' . uniqid();
		mkdir($this->fixtureServerDocroot);
		file_put_contents(
			$this->fixtureServerDocroot . '/index.php',
			'<?php header("Content-Type: application/json"); echo ' . var_export($responseBody, true) . ';'
		);

		$this->fixtureServerProcess = proc_open(
			['php', '-S', '127.0.0.1:' . $this->fixtureServerPort, '-t', $this->fixtureServerDocroot],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes
		);
		$this->assertIsResource($this->fixtureServerProcess, 'Failed to start PHP fixture server');

		$deadline = microtime(true) + 3;
		while (microtime(true) < $deadline) {
			$conn = @fsockopen('127.0.0.1', $this->fixtureServerPort, $errno, $errstr, 0.2);
			if ($conn) {
				fclose($conn);
				return 'http://127.0.0.1:' . $this->fixtureServerPort . '/';
			}
			usleep(50000);
		}
		$this->fail('Fixture server did not start listening in time');
	}

	public function testCreateTokenFormatsCorrectly(): void {
		$token = $this->apiClient->createToken('apiuser', 'apipass');
		$this->assertEquals('A/apiuser/' . md5('apipass'), $token);
	}

	public function testCreateTokenHashesPasswordNotUsername(): void {
		$token = $this->apiClient->createToken('apiuser', 'apipass');
		$this->assertStringNotContainsString('apipass', $token, 'Raw password must never appear in the token');
	}

	public function testMakeRequestReturnsNullOnConnectionFailureWhenNotThrowing(): void {
		$result = $this->apiClient->makeRequest(
			'http://127.0.0.1:1/', // nothing listens here - guaranteed connection refused
			[],
			'A/test/test',
			throwOnError: false
		);

		$this->assertNull($result);
	}

	public function testMakeRequestThrowsOnConnectionFailureWhenThrowOnErrorTrue(): void {
		$this->expectException(\Exception::class);

		$this->apiClient->makeRequest(
			'http://127.0.0.1:1/',
			[],
			'A/test/test',
			throwOnError: true
		);
	}

	public function testMakeRequestReturnsNullOnNonArrayJsonResponseWhenNotThrowing(): void {
		$url = $this->startFixtureServer('"just a plain string, not an object or array"');

		$result = $this->apiClient->makeRequest($url, [], 'A/test/test', throwOnError: false);

		$this->assertNull($result);
	}

	public function testMakeRequestThrowsOnNonArrayJsonResponseWhenThrowOnErrorTrue(): void {
		$url = $this->startFixtureServer('true');

		$this->expectException(\Exception::class);
		$this->apiClient->makeRequest($url, [], 'A/test/test', throwOnError: true);
	}

	public function testMakeRequestReturnsArrayForValidJsonObjectResponse(): void {
		$url = $this->startFixtureServer('{"id": "42"}');

		$result = $this->apiClient->makeRequest($url, [], 'A/test/test', throwOnError: false);

		$this->assertSame(['id' => '42'], $result);
	}
}
