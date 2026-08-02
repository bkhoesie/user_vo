<?php
declare(strict_types=1);

namespace OCA\UserVO\Controller;

use OCA\UserVO\Service\ConfigService;
use OCA\UserVO\Service\GroupSyncService;
use OCA\UserVO\UserVOAuth;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for group synchronization operations
 *
 * Thin delegation layer - all business logic is in GroupSyncService.
 * Handles HTTP request/response for group sync endpoints.
 */
class GroupSyncController extends Controller {
	private GroupSyncService $groupSyncService;
	private ConfigService $configService;
	private LoggerInterface $logger;

	public function __construct(
		string $appName,
		IRequest $request,
		GroupSyncService $groupSyncService,
		ConfigService $configService,
		LoggerInterface $logger
	) {
		parent::__construct($appName, $request);
		$this->groupSyncService = $groupSyncService;
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
	 * Sync a single group's members from VereinOnline
	 *
	 * @return JSONResponse
	 */
	public function syncGroup(): JSONResponse {
		$voGroupId = $this->request->getParam('vo_group_id', '');
		$backend = $this->createBackend();
		$result = $this->groupSyncService->syncSingleGroupById($voGroupId, $backend);

		$statusCode = $result['success'] ? 200 : ($result['status_code'] ?? 500);
		return new JSONResponse($result, $statusCode);
	}

	/**
	 * Sync selected groups
	 *
	 * @return JSONResponse
	 */
	public function syncSelectedGroups(): JSONResponse {
		$voGroupIds = $this->request->getParam('vo_group_ids', []);

		if (empty($voGroupIds) || !is_array($voGroupIds)) {
			return new JSONResponse([
				'success' => false,
				'error' => 'No groups selected'
			], 400);
		}

		$result = $this->groupSyncService->syncGroupsByIds($voGroupIds);

		// Transform response format to match frontend expectations
		// Frontend expects: {success, summary: {total, succeeded, failed}, results}
		// Service returns: {success, synced, failed, results}
		if (isset($result['synced']) && isset($result['failed'])) {
			$result['summary'] = [
				'total' => $result['synced'] + $result['failed'],
				'succeeded' => $result['synced'],
				'failed' => $result['failed']
			];
		}

		return new JSONResponse($result);
	}

	/**
	 * Sync all managed groups
	 *
	 * @return JSONResponse
	 */
	public function syncAllGroups(): JSONResponse {
		$backend = $this->createBackend();
		$result = $this->groupSyncService->syncAllManagedGroups($backend);

		$statusCode = $result['success'] ? 200 : ($result['status_code'] ?? 500);
		return new JSONResponse($result, $statusCode);
	}
}
