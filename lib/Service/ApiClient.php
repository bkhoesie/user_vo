<?php
declare(strict_types=1);

namespace OCA\UserVO\Service;

use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Centralized API client for VereinOnline API communication
 *
 * Eliminates duplicate makeRequest implementations and provides
 * consistent error handling across the application.
 */
class ApiClient {
    private LoggerInterface $logger;
    private IClientService $clientService;

    public function __construct(LoggerInterface $logger, IClientService $clientService) {
        $this->logger = $logger;
        $this->clientService = $clientService;
    }

    /**
     * Make an API request to VereinOnline
     *
     * @param string $url Full API endpoint URL
     * @param array $data Request payload
     * @param string $token Authorization token (format: A/{username}/{md5(password)})
     * @param bool $throwOnError If true, throws exceptions on failure. If false, returns null and logs error.
     * @return array|null Response data or null on failure (when throwOnError=false)
     * @throws \Exception When throwOnError=true and request fails
     */
    public function makeRequest(string $url, array $data, string $token, bool $throwOnError = true): ?array {
        try {
            $response = $this->clientService->newClient()->post($url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => $token,
                ],
                'body' => json_encode($data),
                'timeout' => 10, // seconds
                'connect_timeout' => 5, // seconds
                // Handle non-2xx status codes ourselves below (matching the previous
                // raw-curl behavior) rather than having the client throw on them.
                'http_errors' => false,
            ]);
        } catch (\Exception $e) {
            $errorMsg = 'API request failed: ' . $e->getMessage();
            $this->logger->error($errorMsg, ['app' => 'user_vo', 'url' => $url]);

            if ($throwOnError) {
                throw new \Exception($errorMsg);
            }
            return null;
        }

        $httpCode = $response->getStatusCode();

        // Handle authentication failures
        if ($httpCode === 401 || $httpCode === 403) {
            $errorMsg = 'Authentication failed (HTTP ' . $httpCode . ')';
            $this->logger->error($errorMsg, ['app' => 'user_vo', 'url' => $url]);

            if ($throwOnError) {
                throw new \Exception($errorMsg);
            }
            return null;
        }

        // Handle other HTTP errors
        if ($httpCode !== 200) {
            $errorMsg = 'API request returned HTTP ' . $httpCode;
            $this->logger->error($errorMsg, ['app' => 'user_vo', 'url' => $url]);

            if ($throwOnError) {
                throw new \Exception($errorMsg);
            }
            return null;
        }

        $body = $response->getBody();
        $decoded = is_string($body) ? json_decode($body, true) : null;
        if (!is_array($decoded)) {
            $errorMsg = 'API returned invalid or non-array JSON response';
            $this->logger->error($errorMsg, ['app' => 'user_vo', 'url' => $url]);

            if ($throwOnError) {
                throw new \Exception($errorMsg);
            }
            return null;
        }

        return $decoded;
    }

    /**
     * Create authorization token for VO API
     *
     * @param string $username VO API username
     * @param string $password VO API password
     * @return string Token in format: A/{username}/{md5(password)}
     */
    public function createToken(string $username, string $password): string {
        return 'A/' . $username . '/' . md5($password);
    }
}
