<?php
namespace OCA\UserVO\Tests\Unit\Service;

use OCA\UserVO\Service\ApiClient;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * Unit tests for ApiClient. Now that it goes through Nextcloud's injectable
 * IClientService (previously raw curl_*), every response branch is mockable
 * - no more real network dependency needed to cover 401/403/other-status/
 * malformed-body handling.
 */
class ApiClientTest extends TestCase {
    private LoggerInterface $logger;
    private IClient $client;
    private ApiClient $apiClient;

    protected function setUp(): void {
        parent::setUp();
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->client = $this->createMock(IClient::class);
        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($this->client);
        $this->apiClient = new ApiClient($this->logger, $clientService);
    }

    private function mockResponse(int $statusCode, $body): IResponse {
        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('getBody')->willReturn($body);
        return $response;
    }

    public function testCreateTokenFormatsCorrectly(): void {
        $token = $this->apiClient->createToken('apiuser', 'apipass');
        $this->assertEquals('A/apiuser/' . md5('apipass'), $token);
    }

    public function testCreateTokenHashesPasswordNotUsername(): void {
        $token = $this->apiClient->createToken('apiuser', 'apipass');
        $this->assertStringNotContainsString('apipass', $token, 'Raw password must never appear in the token');
    }

    public function testMakeRequestReturnsDecodedArrayOnSuccess(): void {
        $this->client->method('post')->willReturn($this->mockResponse(200, '{"id": "42"}'));

        $result = $this->apiClient->makeRequest('https://vo.example/', [], 'A/test/test');

        $this->assertSame(['id' => '42'], $result);
    }

    public function testMakeRequestSendsExpectedRequestShape(): void {
        $this->client->expects($this->once())
            ->method('post')
            ->with(
                'https://vo.example/?api=Test',
                $this->callback(function (array $options) {
                    return $options['headers']['Authorization'] === 'A/test/token'
                        && $options['headers']['Content-Type'] === 'application/json'
                        && $options['body'] === json_encode(['foo' => 'bar'])
                        && $options['http_errors'] === false;
                })
            )
            ->willReturn($this->mockResponse(200, '{}'));

        $this->apiClient->makeRequest('https://vo.example/?api=Test', ['foo' => 'bar'], 'A/test/token');
    }

    public function testMakeRequestReturnsNullOnConnectionFailureWhenNotThrowing(): void {
        $this->client->method('post')->willThrowException(new \Exception('Connection refused'));

        $result = $this->apiClient->makeRequest('https://vo.example/', [], 'A/test/test', throwOnError: false);

        $this->assertNull($result);
    }

    public function testMakeRequestThrowsOnConnectionFailureWhenThrowOnErrorTrue(): void {
        $this->client->method('post')->willThrowException(new \Exception('Connection refused'));

        $this->expectException(\Exception::class);
        $this->apiClient->makeRequest('https://vo.example/', [], 'A/test/test', throwOnError: true);
    }

    public function testMakeRequestReturnsNullOn401WhenNotThrowing(): void {
        $this->client->method('post')->willReturn($this->mockResponse(401, ''));

        $result = $this->apiClient->makeRequest('https://vo.example/', [], 'A/test/test', throwOnError: false);

        $this->assertNull($result);
    }

    public function testMakeRequestThrowsOn403WhenThrowOnErrorTrue(): void {
        $this->client->method('post')->willReturn($this->mockResponse(403, ''));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Authentication failed/');
        $this->apiClient->makeRequest('https://vo.example/', [], 'A/test/test', throwOnError: true);
    }

    public function testMakeRequestReturnsNullOnOtherHttpErrorWhenNotThrowing(): void {
        $this->client->method('post')->willReturn($this->mockResponse(500, 'Internal Server Error'));

        $result = $this->apiClient->makeRequest('https://vo.example/', [], 'A/test/test', throwOnError: false);

        $this->assertNull($result);
    }

    public function testMakeRequestReturnsNullOnNonArrayJsonResponseWhenNotThrowing(): void {
        $this->client->method('post')->willReturn($this->mockResponse(200, '"just a plain string, not an object or array"'));

        $result = $this->apiClient->makeRequest('https://vo.example/', [], 'A/test/test', throwOnError: false);

        $this->assertNull($result);
    }

    public function testMakeRequestThrowsOnNonArrayJsonResponseWhenThrowOnErrorTrue(): void {
        $this->client->method('post')->willReturn($this->mockResponse(200, 'true'));

        $this->expectException(\Exception::class);
        $this->apiClient->makeRequest('https://vo.example/', [], 'A/test/test', throwOnError: true);
    }

    public function testMakeRequestReturnsNullOnMalformedJsonWhenNotThrowing(): void {
        $this->client->method('post')->willReturn($this->mockResponse(200, '{not valid json'));

        $result = $this->apiClient->makeRequest('https://vo.example/', [], 'A/test/test', throwOnError: false);

        $this->assertNull($result);
    }

    public function testMakeRequestReturnsNullWhenBodyIsNotAString(): void {
        // IResponse::getBody() is typed null|resource|string - a non-string body
        // (e.g. a stream resource) must be handled gracefully, not passed to
        // json_decode() directly.
        $this->client->method('post')->willReturn($this->mockResponse(200, null));

        $result = $this->apiClient->makeRequest('https://vo.example/', [], 'A/test/test', throwOnError: false);

        $this->assertNull($result);
    }
}
