<?php
declare(strict_types=1);

namespace OCA\UserVO\Controller;

use OCA\UserVO\Service\ApiClient;
use OCA\UserVO\Service\ConfigService;
use OCA\UserVO\UserVOAuth;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for configuration operations
 *
 * Thin delegation layer - handles HTTP request/response for configuration endpoints.
 */
class ConfigController extends Controller {
	private ConfigService $configService;
	private ApiClient $apiClient;
	private IConfig $config;
	private LoggerInterface $logger;

	public function __construct(
		string $appName,
		IRequest $request,
		ConfigService $configService,
		ApiClient $apiClient,
		IConfig $config,
		LoggerInterface $logger
	) {
		parent::__construct($appName, $request);
		$this->configService = $configService;
		$this->apiClient = $apiClient;
		$this->config = $config;
		$this->logger = $logger;
	}

	/**
	 * Get configuration status (API endpoint)
	 *
	 * @NoCSRFRequired
	 * @return JSONResponse
	 */
	public function getConfigurationStatus(): JSONResponse {
		return new JSONResponse($this->configService->getConfigurationStatus());
	}

	/**
	 * Save configuration via admin interface
	 *
	 * @return JSONResponse
	 */
	public function saveConfiguration(): JSONResponse {
		$apiUrl = $this->request->getParam('api_url', '');
		$apiUsername = $this->request->getParam('api_username', '');
		$apiPassword = $this->request->getParam('api_password', '');

		// Check if we have partial configuration in database
		$existingUrl = $this->config->getAppValue('user_vo', 'api_url', '');
		$existingUsername = $this->config->getAppValue('user_vo', 'api_username', '');
		$existingPassword = $this->config->getAppValue('user_vo', 'api_password', '');

		$hasPartialConfig = !empty($existingUrl) || !empty($existingUsername) || !empty($existingPassword);
		$hasIncompleteConfig = empty($apiUrl) || empty($apiUsername) || (empty($apiPassword) && empty($existingPassword));

		if ($hasPartialConfig && $hasIncompleteConfig) {
			return new JSONResponse([
				'success' => false,
				'message' => 'Configuration is incomplete. Please provide all required fields (API URL, Username, and Password).'
			], 400);
		}

		// Validate required fields
		if (empty($apiUrl) || empty($apiUsername) || (empty($apiPassword) && empty($existingPassword))) {
			return new JSONResponse([
				'success' => false,
				'message' => 'API URL and Username are required. Password is required if not already set.'
			], 400);
		}

		// Validate URL format
		if (!filter_var($apiUrl, FILTER_VALIDATE_URL)) {
			return new JSONResponse([
				'success' => false,
				'message' => 'Invalid API URL format.'
			], 400);
		}

		// Save to database using service
		$this->configService->saveConfiguration($apiUrl, $apiUsername, $apiPassword);

		$this->logger->info('UserVO configuration updated via admin interface');

		return new JSONResponse([
			'success' => true,
			'message' => 'Configuration saved successfully.'
		]);
	}

	/**
	 * Test configuration by making a test API request
	 *
	 * @return JSONResponse
	 */
	public function testConfiguration(): JSONResponse {
		$apiUrl = $this->request->getParam('api_url', '');
		$apiUsername = $this->request->getParam('api_username', '');
		$apiPassword = $this->request->getParam('api_password', '');

		// If no password provided, get the actual password from configuration
		// For admin interface mode (URL/username provided), get from database only
		// For config.php mode (no URL/username), get from full configuration
		if (empty($apiPassword)) {
			if (!empty($apiUrl) && !empty($apiUsername)) {
				// Admin interface mode - get password from database only
				$apiPassword = $this->config->getAppValue('user_vo', 'api_password', '');
			} else {
				// Config.php mode - get everything from configuration (respects precedence)
				$configuration = $this->configService->loadConfiguration(maskPassword: false);
				$apiPassword = $configuration['api_password'];

				if (empty($apiUrl)) {
					$apiUrl = $configuration['api_url'];
				}
				if (empty($apiUsername)) {
					$apiUsername = $configuration['api_username'];
				}
			}
		}

		// Validate required fields
		if (empty($apiUrl) || empty($apiUsername) || empty($apiPassword)) {
			return new JSONResponse([
				'success' => false,
				'message' => 'API URL, Username, and Password are required for testing.'
			], 400);
		}

		// Validate URL format
		if (!filter_var($apiUrl, FILTER_VALIDATE_URL)) {
			return new JSONResponse([
				'success' => false,
				'message' => 'Invalid API URL format.'
			], 400);
		}

		try {
			// Test the API connection
			$result = $this->testApiConnection($apiUrl, $apiUsername, $apiPassword);

			if ($result['success']) {
				return new JSONResponse([
					'success' => true,
					'message' => 'Configuration test successful: ' . $result['message']
				]);
			} else {
				return new JSONResponse([
					'success' => false,
					'message' => 'Configuration test failed: ' . $result['message']
				], 400);
			}
		} catch (\Exception $e) {
			$this->logger->error('Configuration test error: ' . $e->getMessage());
			return new JSONResponse([
				'success' => false,
				'message' => 'Configuration test failed: ' . $e->getMessage()
			], 500);
		}
	}

	/**
	 * Test API connection with provided credentials
	 *
	 * @param string $apiUrl API URL
	 * @param string $username API username
	 * @param string $password API password
	 * @return array ['success' => bool, 'message' => string]
	 */
	private function testApiConnection(string $apiUrl, string $username, string $password): array {
		$token = 'A/' . $username . '/' . md5($password);
		$url = rtrim($apiUrl, '/') . '/?api=VerifyLogin';

		// Test with a dummy user to verify credentials without creating real users
		$data = [
			'user' => 'test_user_that_should_not_exist',
			'password' => 'dummy_password',
			'result' => 'id',
		];

		$response = $this->apiClient->makeRequest($url, $data, $token, throwOnError: true);

		if ($response === null) {
			return [
				'success' => false,
				'message' => 'Unable to connect to API. Please check the API URL and network connectivity.'
			];
		}

		// Check for authentication/authorization errors
		// VereinOnline API returns {"error":"Zugriff verweigert..."} for invalid credentials
		if (is_array($response) && isset($response['error'])) {
			$errorMessage = $response['error'];
			// Check for German "Zugriff verweigert" (Access denied) or English auth errors
			if (stripos($errorMessage, 'zugriff verweigert') !== false ||
				stripos($errorMessage, 'access denied') !== false ||
				stripos($errorMessage, 'authentication') !== false ||
				stripos($errorMessage, 'credential') !== false ||
				stripos($errorMessage, 'unauthorized') !== false) {
				return [
					'success' => false,
					'message' => 'Invalid API credentials. Please check your username and password.'
				];
			}
			// Other API errors - include the message but ensure proper encoding
			return [
				'success' => false,
				'message' => 'API error: ' . $errorMessage
			];
		}

		// If we get here, the API is reachable and credentials are valid
		// (even if the test user doesn't exist, which is expected - API returns [""] in this case)
		return [
			'success' => true,
			'message' => 'API connection successful. Credentials are valid.'
		];
	}

	/**
	 * Clear configuration from admin interface
	 *
	 * @return JSONResponse
	 */
	public function clearConfiguration(): JSONResponse {
		// Check if config.php has settings
		$auth = new UserVOAuth(null, null, null, $this->config);
		$configSource = $auth->getConfigurationSource();

		// Clear admin interface settings from database using service
		$this->configService->clearConfiguration();

		$this->logger->info('UserVO admin interface configuration cleared');

		if ($configSource === 'config.php') {
			return new JSONResponse([
				'success' => true,
				'message' => 'Admin interface configuration cleared successfully. Note: Configuration is still active via config.php file - remove the user_backends entry from config.php to fully disable the plugin.'
			]);
		} else {
			return new JSONResponse([
				'success' => true,
				'message' => 'Configuration cleared successfully. The plugin is now unconfigured.'
			]);
		}
	}

	/**
	 * Save user sync settings
	 *
	 * @return JSONResponse
	 */
	public function saveUserSyncSettings(): JSONResponse {
		$syncEmail = $this->request->getParam('sync_email', 'false');
		$syncPhoto = $this->request->getParam('sync_photo', 'false');

		// Convert to string 'true' or 'false' for consistency
		$emailValue = $syncEmail === 'true' || $syncEmail === true ? 'true' : 'false';
		$photoValue = $syncPhoto === 'true' || $syncPhoto === true ? 'true' : 'false';

		// Store as string 'true' or 'false' for consistency
		$this->configService->set('sync_email', $emailValue);
		$this->configService->set('sync_photo', $photoValue);

		$this->logger->info('User sync settings updated', [
			'sync_email' => $emailValue,
			'sync_photo' => $photoValue
		]);

		return new JSONResponse([
			'success' => true,
			'message' => 'Sync settings saved successfully.'
		]);
	}

	/**
	 * Save nightly sync setting
	 *
	 * @return JSONResponse
	 */
	public function saveNightlySyncSetting(): JSONResponse {
		$enabled = $this->request->getParam('enabled', false);
		$syncType = $this->request->getParam('sync_type', 'user'); // 'user' or 'group'

		if ($syncType === 'group') {
			$this->config->setAppValue('user_vo', 'enable_nightly_group_sync', $enabled ? 'true' : 'false');
		} else {
			$this->config->setAppValue('user_vo', 'enable_nightly_user_sync', $enabled ? 'true' : 'false');
		}

		return new JSONResponse([
			'success' => true,
			'message' => 'Nightly sync setting saved successfully.'
		]);
	}

	/**
	 * Get nightly sync status
	 *
	 * @NoCSRFRequired
	 * @return JSONResponse
	 */
	public function getNightlySyncStatus(): JSONResponse {
		$userSyncEnabled = $this->config->getAppValue('user_vo', 'enable_nightly_user_sync', 'true') === 'true';
		$groupSyncEnabled = $this->config->getAppValue('user_vo', 'enable_nightly_group_sync', 'true') === 'true';

		$lastRun = $this->config->getAppValue('user_vo', 'nightly_sync_last_run', '');
		$lastStatus = $this->config->getAppValue('user_vo', 'nightly_sync_last_status', 'never');
		$lastError = $this->config->getAppValue('user_vo', 'nightly_sync_last_error', '');
		$lastSummary = $this->config->getAppValue('user_vo', 'nightly_sync_last_summary', '{}');

		$summary = json_decode($lastSummary, true);

		return new JSONResponse([
			'success' => true,
			'user_sync_enabled' => $userSyncEnabled,
			'group_sync_enabled' => $groupSyncEnabled,
			'last_run' => $lastRun ? (int)$lastRun : null,
			'last_status' => $lastStatus,
			'last_error' => $lastError,
			'last_summary' => $summary
		]);
	}
}
