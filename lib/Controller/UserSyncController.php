<?php
/**
 * @author Nikolaus Demmel <nikolaus@nikolaus-demmel.de>
 * @copyright (c) 2025 Nikolaus Demmel <nikolaus@nikolaus-demmel.de>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the LICENSE file.
 */

declare(strict_types=1);

namespace OCA\UserVO\Controller;

use OCA\UserVO\Service\ConfigService;
use OCA\UserVO\Service\UserSyncService;
use OCA\UserVO\UserVOAuth;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for user synchronization operations
 *
 * Thin delegation layer - all business logic is in UserSyncService.
 * Handles HTTP request/response for user sync endpoints.
 */
class UserSyncController extends Controller {
    private UserSyncService $userSyncService;
    private ConfigService $configService;
    private LoggerInterface $logger;

    public function __construct(
        string $appName,
        IRequest $request,
        UserSyncService $userSyncService,
        ConfigService $configService,
        LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
        $this->userSyncService = $userSyncService;
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
     * Preview local user data (no API calls, read-only)
     *
     * @NoCSRFRequired
     * @return JSONResponse
     */
    public function previewLocalUsers(): JSONResponse {
        $result = $this->userSyncService->previewLocalUsers();
        $statusCode = $result['success'] ? 200 : 500;
        return new JSONResponse($result, $statusCode);
    }

    /**
     * Preview user data from VO API without syncing (read-only)
     *
     * @NoCSRFRequired
     * @return JSONResponse
     */
    public function previewVOUsers(): JSONResponse {
        $backend = $this->createBackend();
        $result = $this->userSyncService->previewVOUsers($backend);
        $statusCode = $result['success'] ? 200 : 500;
        return new JSONResponse($result, $statusCode);
    }

    /**
     * Sync all users from VO API
     *
     * @NoCSRFRequired
     * @return JSONResponse
     */
    public function syncFromVO(): JSONResponse {
        $backend = $this->createBackend();
        $result = $this->userSyncService->syncAllUsers($backend);
        $statusCode = $result['success'] ? 200 : 500;
        return new JSONResponse($result, $statusCode);
    }

    /**
     * Sync selected users from VereinOnline
     *
     * @NoCSRFRequired
     * @return JSONResponse
     */
    public function syncSelectedUsers(): JSONResponse {
        $userIds = $this->request->getParam('user_ids', []);
        $backend = $this->createBackend();
        $result = $this->userSyncService->syncSelectedUsers($userIds, $backend);

        // Return 400 for validation errors
        if (!$result['success'] && isset($result['message']) && $result['message'] === 'No user IDs provided') {
            return new JSONResponse($result, 400);
        }

        $statusCode = $result['success'] ? 200 : 500;
        return new JSONResponse($result, $statusCode);
    }
}
