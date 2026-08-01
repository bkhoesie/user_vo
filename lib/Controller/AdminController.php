<?php

namespace OCA\UserVO\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IConfig;
use OCA\UserVO\UserVOAuth;
use OCA\UserVO\Service\ApiClient;
use OCA\UserVO\Service\ConfigService;
use OCA\UserVO\Service\GroupNameHarmonizer;
use OCA\UserVO\Service\GroupSyncService;
use OCA\UserVO\Service\UserProvisioningService;
use OCA\UserVO\Service\UserAccountService;
use OCA\UserVO\Service\GroupManagementService;
use Psr\Log\LoggerInterface;

class AdminController extends Controller {

    private $connection;
    private $logger;
    private $groupManager;
    private $config;
    private $configService;
    private $groupNameHarmonizer;
    private $groupSyncService;
    private $apiClient;
    private $userProvisioningService;
    private $userAccountService;
    private $groupManagementService;

    public function __construct(
        $appName,
        IRequest $request,
        IDBConnection $connection,
        LoggerInterface $logger,
        IGroupManager $groupManager,
        IConfig $config,
        ConfigService $configService,
        GroupNameHarmonizer $groupNameHarmonizer,
        GroupSyncService $groupSyncService,
        ApiClient $apiClient,
        UserProvisioningService $userProvisioningService,
        UserAccountService $userAccountService,
        GroupManagementService $groupManagementService
    ) {
        parent::__construct($appName, $request);
        $this->connection = $connection;
        $this->logger = $logger;
        $this->groupManager = $groupManager;
        $this->config = $config;
        $this->configService = $configService;
        $this->groupNameHarmonizer = $groupNameHarmonizer;
        $this->groupSyncService = $groupSyncService;
        $this->apiClient = $apiClient;
        $this->userProvisioningService = $userProvisioningService;
        $this->userAccountService = $userAccountService;
        $this->groupManagementService = $groupManagementService;
    }

    /**
     * Factory method to create UserVOAuth backend instance
     *
     * Eliminates repetitive instantiation code throughout the controller.
     *
     * @return UserVOAuth Configured backend instance
     */
    private function createBackend(): UserVOAuth {
        $configuration = $this->configService->loadConfiguration(maskPassword: false);
        return new UserVOAuth(
            $configuration['api_url'],
            $configuration['api_username'],
            $configuration['api_password'],
            $this->config
        );
    }

    /**
     * Admin settings page
     *
     * @NoCSRFRequired
     */
    public function index() {
        // Get current configuration status from service
        $configStatus = $this->configService->getConfigurationStatus();

        return new TemplateResponse('user_vo', 'admin', [
            'config_status' => $configStatus
        ], 'admin');
    }
}
