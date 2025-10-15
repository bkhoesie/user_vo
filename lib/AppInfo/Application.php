<?php

declare(strict_types=1);

namespace OCA\UserVO\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCA\UserVO\Controller\AdminController;
use OCA\UserVO\UserVOAuth;
use OCA\UserVO\Listener\GroupDeletedListener;
use OCP\Group\Events\GroupDeletedEvent;
use OCP\IConfig;

class Application extends App implements IBootstrap {
    public function __construct() {
        parent::__construct('user_vo');
    }

    public function register(IRegistrationContext $context): void {
        // Register event listener for group deletion
        $context->registerEventListener(GroupDeletedEvent::class, GroupDeletedListener::class);
    }

    public function boot(IBootContext $context): void {
        $this->registerUserBackend($context);
    }

    private function registerUserBackend(IBootContext $context): void {
        $config = $context->getServerContainer()->get(IConfig::class);

        // Check if user_backends is already configured in config.php
        $configBackends = $config->getSystemValue('user_backends', []);
        foreach ($configBackends as $backend) {
            if (isset($backend['class'])) {
                $normalizedClass = str_replace('\\\\', '\\', $backend['class']);
                if ($normalizedClass === '\OCA\UserVO\UserVOAuth') {
                    // Backend is already configured in config.php, don't register dynamically
                    return;
                }
            }
        }

        // Always register the backend to ensure existing users remain accessible
        // The backend will handle incomplete configuration gracefully
        $userBackend = new UserVOAuth(null, null, null, $config);
        $context->getServerContainer()->getUserManager()->registerBackend($userBackend);
    }
}
