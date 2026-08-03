<?php
declare(strict_types=1);

namespace OCA\UserVO\Controller;

use OCA\UserVO\Service\ConfigService;
use OCA\UserVO\Service\UserProvisioningService;
use OCA\UserVO\UserVOAuth;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for user provisioning operations
 *
 * Handles:
 * - Searching for VO users
 * - Creating NC accounts from VO users
 * - Bulk account provisioning
 */
class UserProvisioningController extends Controller {
	private UserProvisioningService $userProvisioningService;
	private ConfigService $configService;
	private LoggerInterface $logger;

	public function __construct(
		string $appName,
		IRequest $request,
		UserProvisioningService $userProvisioningService,
		ConfigService $configService,
		LoggerInterface $logger
	) {
		parent::__construct($appName, $request);
		$this->userProvisioningService = $userProvisioningService;
		$this->configService = $configService;
		$this->logger = $logger;
	}

	/**
	 * Factory method to create UserVOAuth backend instance
	 *
	 * @return UserVOAuth Configured backend instance
	 */
	private function createBackend(): UserVOAuth {
		$configuration = $this->configService->loadConfiguration(maskPassword: false);
		return new UserVOAuth(
			$configuration['api_url'],
			$configuration['api_username'],
			$configuration['api_password']
		);
	}

	/**
	 * Search for VO users who could log in and check their NC account status
	 *
	 * @param string $searchTerm Partial name to search (empty = all users, with warning)
	 * @return JSONResponse
	 */
	public function searchVOUsers(): JSONResponse {
		try {
			$searchTerm = $this->request->getParam('search_term', '');
			$this->logger->info('[searchVOUsers] Starting search', ['app' => 'user_vo', 'search_term' => $searchTerm]);

			// Delegate to service
			$backend = $this->createBackend();
			$result = $this->userProvisioningService->searchVOUsers($searchTerm, $backend);

			if (!$result['success']) {
				return new JSONResponse([
					'success' => false,
					'error' => $result['error']
				], 500);
			}

			return new JSONResponse([
				'success' => true,
				'users' => $result['users'],
				'count' => $result['total'],
				'search_term' => $searchTerm,
				'is_all_users' => empty($searchTerm)
			]);

		} catch (\Exception $e) {
			$this->logger->error('Failed to search VO users', [
				'app' => 'user_vo',
				'error' => $e->getMessage()
			]);

			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage()
			], 500);
		}
	}

	/**
	 * Create a Nextcloud account for a VO user
	 *
	 * @return JSONResponse
	 */
	public function createAccountFromVO(string $voUserId = ''): JSONResponse {
		try {
			// Allow parameter to be passed directly or from request
			if (empty($voUserId)) {
				$voUserId = $this->request->getParam('vo_user_id', '');
			}

			if (empty($voUserId)) {
				return new JSONResponse([
					'success' => false,
					'error' => 'VO user ID is required'
				], 400);
			}

			// Delegate to service
			$backend = $this->createBackend();
			$result = $this->userProvisioningService->createAccountFromVO($voUserId, $backend);

			if (!$result['success']) {
				// Determine HTTP status code based on error message
				$statusCode = 500;
				if (isset($result['error'])) {
					if (str_contains($result['error'], 'already exists')) {
						$statusCode = 409;
					} elseif (str_contains($result['error'], 'does not have login') ||
					          str_contains($result['error'], 'marked as deleted')) {
						$statusCode = 400;
					}
				}

				return new JSONResponse([
					'success' => false,
					'error' => $result['error'],
					'backend_conflict' => $result['backend_conflict'] ?? false,
					'conflicting_backend' => $result['conflicting_backend'] ?? null
				], $statusCode);
			}

			return new JSONResponse([
				'success' => true,
				'nc_username' => $result['username'],
				'message' => $result['message'],
				'groups_synced' => $result['groups_synced'] ?? 0,
				'groups_failed' => $result['groups_failed'] ?? 0,
				'group_sync_error' => $result['group_sync_error'] ?? null
			]);

		} catch (\Exception $e) {
			$this->logger->error('Failed to create account from VO', [
				'app' => 'user_vo',
				'vo_user_id' => $voUserId ?? 'unknown',
				'error' => $e->getMessage()
			]);

			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage()
			], 500);
		}
	}

	/**
	 * Bulk create accounts for multiple VO users
	 *
	 * @return JSONResponse
	 */
	public function bulkCreateAccountsFromVO(): JSONResponse {
		try {
			$voUserIds = $this->request->getParam('vo_user_ids', []);

			if (empty($voUserIds) || !is_array($voUserIds)) {
				return new JSONResponse([
					'success' => false,
					'error' => 'No user IDs provided'
				], 400);
			}

			// Delegate to service
			$backend = $this->createBackend();
			$results = $this->userProvisioningService->bulkCreateAccounts($voUserIds, $backend);

			return new JSONResponse([
				'success' => true,
				'summary' => [
					'total' => count($voUserIds),
					'created' => count($results['created']),
					'skipped' => count($results['skipped']),
					'errors' => count($results['errors'])
				],
				'results' => $results
			]);

		} catch (\Exception $e) {
			$this->logger->error('Failed to bulk create accounts', [
				'app' => 'user_vo',
				'error' => $e->getMessage()
			]);

			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage()
			], 500);
		}
	}
}
