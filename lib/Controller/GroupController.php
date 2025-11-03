<?php
declare(strict_types=1);

namespace OCA\UserVO\Controller;

use OCA\UserVO\Service\ConfigService;
use OCA\UserVO\Service\GroupManagementService;
use OCA\UserVO\UserVOAuth;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for group management operations
 *
 * Handles:
 * - Fetching groups from VereinOnline
 * - Creating/deleting Nextcloud groups
 * - Bulk operations
 */
class GroupController extends Controller {
	private GroupManagementService $groupManagementService;
	private ConfigService $configService;
	private LoggerInterface $logger;

	public function __construct(
		string $appName,
		IRequest $request,
		GroupManagementService $groupManagementService,
		ConfigService $configService,
		LoggerInterface $logger
	) {
		parent::__construct($appName, $request);
		$this->groupManagementService = $groupManagementService;
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
	 * Fetch all groups from VereinOnline
	 *
	 * @return JSONResponse
	 */
	public function fetchAllVOGroups(): JSONResponse {
		try {
			$backend = $this->createBackend();
			$result = $this->groupManagementService->fetchAllVOGroups($backend);

			if (!$result['success']) {
				return new JSONResponse([
					'success' => false,
					'error' => $result['error']
				], 500);
			}

			return new JSONResponse($result);

		} catch (\Exception $e) {
			$this->logger->error('Failed to fetch VO groups', [
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
	 * Fetch managed groups from database
	 *
	 * @return JSONResponse
	 */
	public function fetchManagedGroups(): JSONResponse {
		try {
			$backend = $this->createBackend();
			$result = $this->groupManagementService->fetchManagedGroups($backend);

			if (!$result['success']) {
				return new JSONResponse([
					'success' => false,
					'error' => $result['error']
				], 500);
			}

			return new JSONResponse($result);

		} catch (\Exception $e) {
			$this->logger->error('Failed to fetch managed groups', [
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
	 * Create a Nextcloud group from a VereinOnline group
	 *
	 * @return JSONResponse
	 */
	public function createGroup(): JSONResponse {
		try {
			$voGroupId = $this->request->getParam('vo_group_id', '');

			if (empty($voGroupId)) {
				return new JSONResponse([
					'success' => false,
					'error' => 'VO group ID is required'
				], 400);
			}

			$backend = $this->createBackend();
			$result = $this->groupManagementService->createGroup($voGroupId, $backend);

			if (!$result['success']) {
				$statusCode = $result['status_code'] ?? 500;
				return new JSONResponse([
					'success' => false,
					'error' => $result['error']
				], $statusCode);
			}

			return new JSONResponse($result);

		} catch (\Exception $e) {
			$this->logger->error('Failed to create group', [
				'app' => 'user_vo',
				'vo_group_id' => $voGroupId ?? 'unknown',
				'error' => $e->getMessage()
			]);

			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage()
			], 500);
		}
	}

	/**
	 * Delete a managed group
	 *
	 * @return JSONResponse
	 */
	public function deleteGroup(): JSONResponse {
		try {
			$voGroupId = $this->request->getParam('vo_group_id', '');

			if (empty($voGroupId)) {
				return new JSONResponse([
					'success' => false,
					'error' => 'VO group ID is required'
				], 400);
			}

			$result = $this->groupManagementService->deleteGroup($voGroupId);

			if (!$result['success']) {
				$statusCode = $result['status_code'] ?? 500;
				return new JSONResponse([
					'success' => false,
					'error' => $result['error']
				], $statusCode);
			}

			return new JSONResponse($result);

		} catch (\Exception $e) {
			$this->logger->error('Failed to delete group', [
				'app' => 'user_vo',
				'vo_group_id' => $voGroupId ?? 'unknown',
				'error' => $e->getMessage()
			]);

			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage()
			], 500);
		}
	}

	/**
	 * Bulk create groups from VereinOnline
	 *
	 * @return JSONResponse
	 */
	public function bulkCreateGroups(): JSONResponse {
		try {
			$voGroupIds = $this->request->getParam('vo_group_ids', []);

			if (empty($voGroupIds) || !is_array($voGroupIds)) {
				return new JSONResponse([
					'success' => false,
					'error' => 'No group IDs provided'
				], 400);
			}

			$backend = $this->createBackend();
			$results = $this->groupManagementService->bulkCreateGroups($voGroupIds, $backend);

			return new JSONResponse([
				'success' => true,
				'results' => $results,
				'summary' => [
					'total' => count($voGroupIds),
					'created' => count($results['created']),
					'skipped' => count($results['skipped']),
					'errors' => count($results['errors'])
				]
			]);

		} catch (\Exception $e) {
			$this->logger->error('Failed to bulk create groups', [
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
	 * Bulk delete groups
	 *
	 * @return JSONResponse
	 */
	public function bulkDeleteGroups(): JSONResponse {
		try {
			$voGroupIds = $this->request->getParam('vo_group_ids', []);

			if (empty($voGroupIds) || !is_array($voGroupIds)) {
				return new JSONResponse([
					'success' => false,
					'error' => 'No group IDs provided'
				], 400);
			}

			$results = $this->groupManagementService->bulkDeleteGroups($voGroupIds);

			return new JSONResponse([
				'success' => true,
				'results' => $results,
				'summary' => [
					'total' => count($voGroupIds),
					'deleted' => count($results['deleted']),
					'errors' => count($results['errors'])
				]
			]);

		} catch (\Exception $e) {
			$this->logger->error('Failed to bulk delete groups', [
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
