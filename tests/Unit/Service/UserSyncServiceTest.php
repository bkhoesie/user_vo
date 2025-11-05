<?php
/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserVO\Tests\Unit\Service;

use OCA\UserVO\Service\UserSyncService;
use OCA\UserVO\UserVOAuth;
use OCP\IDBConnection;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * Unit tests for UserSyncService
 * Tests sync logic with mocked dependencies
 */
class UserSyncServiceTest extends TestCase {
    private IDBConnection $connection;
    private IConfig $config;
    private LoggerInterface $logger;
    private UserVOAuth $backend;
    private UserSyncService $service;

    protected function setUp(): void {
        parent::setUp();
        $this->connection = $this->createMock(IDBConnection::class);
        $this->config = $this->createMock(IConfig::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->backend = $this->createMock(UserVOAuth::class);

        $this->service = new UserSyncService(
            $this->connection,
            $this->config,
            $this->logger
        );
    }

    /**
     * Test syncSelectedUsers returns error when no user IDs provided
     */
    public function testSyncSelectedUsersWithoutUserIds(): void {
        $result = $this->service->syncSelectedUsers([], $this->backend);

        $this->assertFalse($result['success']);
        $this->assertSame('No user IDs provided', $result['message']);
    }

    /**
     * Test syncSelectedUsers returns error when user IDs is not an array
     */
    public function testSyncSelectedUsersWithInvalidUserIds(): void {
        $result = $this->service->syncSelectedUsers('not-an-array', $this->backend);

        $this->assertFalse($result['success']);
        $this->assertSame('No user IDs provided', $result['message']);
    }

    /**
     * Note: Database-heavy methods (previewLocalUsers, previewVOUsers, sync methods)
     * are better tested with integration tests where we have a real database.
     * Unit testing these with mocks would require extensive mock setup for minimal value.
     * See tests/Integration/Controller/UserSyncControllerTest.php for comprehensive testing.
     */
}
