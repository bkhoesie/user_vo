<?php
namespace OCA\UserVO\Tests\Unit\Service;

use OCA\UserVO\Service\ApiClient;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * Unit tests for ApiClient.
 *
 * ApiClient uses raw curl_* calls directly rather than Nextcloud's injectable
 * IClientService, so its HTTP-response-handling branches (401/403, other
 * non-200, successful JSON decode) aren't mockable without either a bigger
 * refactor or standing up a real local HTTP fixture server - out of scope
 * here. What's covered: createToken() (pure logic), and the connection-
 * failure branch via a guaranteed-refused local address (127.0.0.1 on a port
 * nothing listens on - fast and reliable with no external network needed).
 */
class ApiClientTest extends TestCase {
	private ApiClient $apiClient;

	protected function setUp(): void {
		parent::setUp();
		$logger = $this->createMock(LoggerInterface::class);
		$this->apiClient = new ApiClient($logger);
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
}
