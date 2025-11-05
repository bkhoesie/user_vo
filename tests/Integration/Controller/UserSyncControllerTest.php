<?php
/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserVO\Tests\Integration\Controller;

use OCA\UserVO\Controller\UserSyncController;
use OCA\UserVO\Service\ConfigService;
use OCA\UserVO\Service\UserSyncService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Test\TestCase as NextcloudTestCase;

/**
 * Integration tests for UserSyncController
 *
 * Tests controller delegation to service and HTTP response handling
 *
 * @group DB
 */
class UserSyncControllerTest extends NextcloudTestCase {
    private UserSyncController $controller;
    private IConfig $config;
    private ConfigService $configService;
    private UserSyncService $userSyncService;
    private LoggerInterface $logger;
    private IRequest $request;

    // Store original config values for restoration
    private array $originalConfig = [];

    protected function setUp(): void {
        parent::setUp();

        // Get real Nextcloud services
        $this->config = \OC::$server->get(IConfig::class);
        $this->logger = \OC::$server->get(LoggerInterface::class);
        $connection = \OC::$server->getDatabaseConnection();
        $this->request = $this->createMock(IRequest::class);

        // Save original configuration
        $this->originalConfig = [
            'api_url' => $this->config->getAppValue('user_vo', 'api_url', ''),
            'api_username' => $this->config->getAppValue('user_vo', 'api_username', ''),
            'api_password' => $this->config->getAppValue('user_vo', 'api_password', ''),
        ];

        // Create real services
        $this->configService = new ConfigService($this->config);
        $this->userSyncService = new UserSyncService(
            $connection,
            $this->config,
            $this->logger
        );

        // Set up test configuration
        $this->config->setAppValue('user_vo', 'api_url', 'https://test.example.org');
        $this->config->setAppValue('user_vo', 'api_username', 'test_user');
        $this->config->setAppValue('user_vo', 'api_password', 'test_password');

        // Create controller
        $this->controller = new UserSyncController(
            'user_vo',
            $this->request,
            $this->userSyncService,
            $this->configService,
            $this->logger
        );
    }

    protected function tearDown(): void {
        // Restore original configuration
        foreach ($this->originalConfig as $key => $value) {
            if ($value === '') {
                // If original value was empty, delete the key
                $this->config->deleteAppValue('user_vo', $key);
            } else {
                // Otherwise restore the original value
                $this->config->setAppValue('user_vo', $key, $value);
            }
        }

        parent::tearDown();
    }

    /**
     * Test previewLocalUsers returns JSONResponse with success
     */
    public function testPreviewLocalUsersReturnsJsonResponse(): void {
        $response = $this->controller->previewLocalUsers();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());

        $data = $response->getData();
        $this->assertIsArray($data);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('results', $data);
        $this->assertArrayHasKey('total', $data);
    }

    /**
     * Test syncSelectedUsers returns error for empty user IDs
     */
    public function testSyncSelectedUsersReturnsErrorForEmptyUserIds(): void {
        $this->request->method('getParam')
            ->with('user_ids', [])
            ->willReturn([]);

        $response = $this->controller->syncSelectedUsers();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());

        $data = $response->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('No user IDs provided', $data['message']);
    }

    /**
     * Test syncSelectedUsers returns error for invalid user IDs type
     */
    public function testSyncSelectedUsersReturnsErrorForInvalidUserIds(): void {
        $this->request->method('getParam')
            ->with('user_ids', [])
            ->willReturn('not-an-array');

        $response = $this->controller->syncSelectedUsers();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());

        $data = $response->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('No user IDs provided', $data['message']);
    }
}
