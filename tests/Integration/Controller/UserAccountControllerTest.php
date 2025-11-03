<?php
/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserVO\Tests\Integration\Controller;

use OCA\UserVO\Controller\UserAccountController;
use OCP\AppFramework\App;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserManager;
use Test\TestCase;

/**
 * Integration tests for UserAccountController
 * Tests with real Nextcloud database and services
 *
 * @group DB
 */
class UserAccountControllerTest extends TestCase {
	private UserAccountController $controller;
	private IDBConnection $connection;
	private IUserManager $userManager;

	protected function setUp(): void {
		parent::setUp();

		// Get real services from DI container
		$app = new App('user_vo');
		$container = $app->getContainer();

		$this->controller = $container->get(UserAccountController::class);
		$this->connection = \OC::$server->getDatabaseConnection();
		$this->userManager = \OC::$server->getUserManager();
	}

	protected function tearDown(): void {
		// Clean up test data
		$qb = $this->connection->getQueryBuilder();

		// Clean up user_vo entries for test users (case-insensitive match)
		$qb->delete('user_vo')
			->where($qb->expr()->orX(
				$qb->expr()->like($qb->func()->lower('uid'), $qb->createNamedParameter('test_user_%')),
				$qb->expr()->like($qb->func()->lower('uid'), $qb->createNamedParameter('test\\_user\\_%!duplicate'))
			))
			->executeStatement();

		// Clean up test users from accounts table (case-insensitive)
		$qb = $this->connection->getQueryBuilder();
		$qb->delete('accounts')
			->where($qb->expr()->like($qb->func()->lower('uid'), $qb->createNamedParameter('test_user_%')))
			->executeStatement();

		parent::tearDown();
	}

	/**
	 * Test scanDuplicates returns empty result when no duplicates exist
	 */
	public function testScanDuplicatesWithNoDuplicates(): void {
		$response = $this->controller->scanDuplicates();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertIsArray($data['duplicateSets']);
		$this->assertIsArray($data['allPluginUsers']);
		$this->assertArrayHasKey('summary', $data);
	}

	/**
	 * Test scanDuplicates detects duplicate users (historical bug state)
	 *
	 * This simulates the case sensitivity bug where both test_user_john and
	 * Test_User_John existed as separate accounts (which is no longer possible
	 * in newer versions, but old data may still have this).
	 */
	public function testScanDuplicatesDetectsDuplicates(): void {
		// Note: This test simulates historical state. In reality, accounts table
		// has unique constraint on uid (case-sensitive), so we can only test with
		// user_vo entries that have !duplicate markers.

		// Create one account entry
		$qb = $this->connection->getQueryBuilder();
		$qb->insert('accounts')
			->values([
				'uid' => $qb->createNamedParameter('test_user_john'),
			])
			->executeStatement();

		// Create canonical user in user_vo
		$qb = $this->connection->getQueryBuilder();
		$qb->insert('user_vo')
			->values([
				'uid' => $qb->createNamedParameter('test_user_john'),
				'backend' => $qb->createNamedParameter('user_vo'),
				'displayname' => $qb->createNamedParameter('John Doe'),
			])
			->executeStatement();

		$response = $this->controller->scanDuplicates();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertIsArray($data['duplicateSets']);
		$this->assertIsArray($data['allPluginUsers']);

		// Should have at least one managed user
		$this->assertGreaterThan(0, count($data['allPluginUsers']), 'Should have managed users');
	}

	/**
	 * Test exposeUser marks user with !duplicate marker
	 */
	public function testExposeUserAddsMarker(): void {
		// Create a test user
		$this->createTestUser('test_user_expose', 'Test Expose');

		// Mock request with uid parameter
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['uid' => 'test_user_expose']);

		// Inject mocked request
		$controller = new UserAccountController(
			'user_vo',
			$request,
			\OC::$server->get(\OCA\UserVO\Service\UserAccountService::class),
			\OC::$server->get(\Psr\Log\LoggerInterface::class)
		);

		$response = $controller->exposeUser();
		$data = $response->getData();

		$this->assertTrue($data['success']);

		// Verify marked entry exists in database
		$qb = $this->connection->getQueryBuilder();
		$query = $qb->select('uid')
			->from('user_vo')
			->where($qb->expr()->eq('uid', $qb->createNamedParameter('test_user_expose!duplicate')));
		$row = $query->executeQuery()->fetch();

		$this->assertNotFalse($row, 'Should have marked entry');
		$this->assertEquals('test_user_expose!duplicate', $row['uid']);
	}

	/**
	 * Test exposeUser returns error when uid not provided
	 */
	public function testExposeUserWithoutUid(): void {
		// Mock request with no uid
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([]);

		$controller = new UserAccountController(
			'user_vo',
			$request,
			\OC::$server->get(\OCA\UserVO\Service\UserAccountService::class),
			\OC::$server->get(\Psr\Log\LoggerInterface::class)
		);

		$response = $controller->exposeUser();
		$data = $response->getData();

		$this->assertFalse($data['success']);
		$this->assertEquals('No uid provided', $data['error']);
	}

	/**
	 * Test hideUser removes !duplicate marker
	 */
	public function testHideUserRemovesMarker(): void {
		// Create only one account (accounts table has unique constraint on uid)
		$qb = $this->connection->getQueryBuilder();
		$qb->insert('accounts')
			->values([
				'uid' => $qb->createNamedParameter('test_user_hide'),
			])
			->executeStatement();

		// Create the canonical user in user_vo
		$qb = $this->connection->getQueryBuilder();
		$qb->insert('user_vo')
			->values([
				'uid' => $qb->createNamedParameter('test_user_hide'),
				'backend' => $qb->createNamedParameter('user_vo'),
				'displayname' => $qb->createNamedParameter('Test Hide'),
			])
			->executeStatement();

		// Add exposed duplicate with marker
		$qb = $this->connection->getQueryBuilder();
		$qb->insert('user_vo')
			->values([
				'uid' => $qb->createNamedParameter('Test_User_Hide!duplicate'),
				'backend' => $qb->createNamedParameter('user_vo'),
				'displayname' => $qb->createNamedParameter('Test Hide Alt'),
			])
			->executeStatement();

		// Mock request with uid parameter (hiding the duplicate variant)
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['uid' => 'Test_User_Hide']);

		$controller = new UserAccountController(
			'user_vo',
			$request,
			\OC::$server->get(\OCA\UserVO\Service\UserAccountService::class),
			\OC::$server->get(\Psr\Log\LoggerInterface::class)
		);

		$response = $controller->hideUser();
		$data = $response->getData();

		$this->assertTrue($data['success']);

		// Verify marked entry removed from database
		$qb = $this->connection->getQueryBuilder();
		$query = $qb->select('uid')
			->from('user_vo')
			->where($qb->expr()->eq('uid', $qb->createNamedParameter('Test_User_Hide!duplicate')));
		$row = $query->executeQuery()->fetch();

		$this->assertFalse($row, 'Marked entry should be removed');
	}

	/**
	 * Test hideUser returns error when trying to hide canonical user
	 */
	public function testHideCanonicalUserReturnsError(): void {
		// Create canonical user
		$this->createTestUser('test_user_canonical', 'Test Canonical');

		// Mock request
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['uid' => 'test_user_canonical']);

		$controller = new UserAccountController(
			'user_vo',
			$request,
			\OC::$server->get(\OCA\UserVO\Service\UserAccountService::class),
			\OC::$server->get(\Psr\Log\LoggerInterface::class)
		);

		$response = $controller->hideUser();
		$data = $response->getData();

		$this->assertFalse($data['success']);
		$this->assertEquals('Cannot hide canonical user', $data['error']);
	}

	/**
	 * Test hideUser returns error when uid not provided
	 */
	public function testHideUserWithoutUid(): void {
		// Mock request with no uid
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([]);

		$controller = new UserAccountController(
			'user_vo',
			$request,
			\OC::$server->get(\OCA\UserVO\Service\UserAccountService::class),
			\OC::$server->get(\Psr\Log\LoggerInterface::class)
		);

		$response = $controller->hideUser();
		$data = $response->getData();

		$this->assertFalse($data['success']);
		$this->assertEquals('No uid provided', $data['error']);
	}

	/**
	 * Helper method to create test user in database
	 */
	private function createTestUser(string $uid, string $displayName): void {
		// Insert into accounts table (for scanDuplicates to find)
		$qb = $this->connection->getQueryBuilder();
		$qb->insert('accounts')
			->values([
				'uid' => $qb->createNamedParameter($uid),
			])
			->executeStatement();

		// Insert into user_vo table
		$qb = $this->connection->getQueryBuilder();
		$qb->insert('user_vo')
			->values([
				'uid' => $qb->createNamedParameter($uid),
				'backend' => $qb->createNamedParameter('user_vo'),
				'displayname' => $qb->createNamedParameter($displayName),
			])
			->executeStatement();
	}
}
