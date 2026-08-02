<?php
declare(strict_types=1);

namespace OCA\UserVO\Service;

use Psr\Log\LoggerInterface;

/**
 * Centralized API client for VereinOnline API communication
 *
 * Eliminates duplicate makeRequest implementations and provides
 * consistent error handling across the application.
 */
class ApiClient {
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger) {
        $this->logger = $logger;
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
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: ' . $token,
        ]);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_TIMEOUT, 10); // 10 second timeout
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5); // 5 second connection timeout

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);

        curl_close($curl);

        // Handle connection failures
        if ($response === false) {
            $errorMsg = 'API request failed: ' . $error;
            $this->logger->error($errorMsg, ['app' => 'user_vo', 'url' => $url]);

            if ($throwOnError) {
                throw new \Exception($errorMsg);
            }
            return null;
        }

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

        $decoded = json_decode($response, true);
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
