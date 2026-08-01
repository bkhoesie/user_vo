<?php
namespace OCA\UserVO\Tests\Unit;

use OCA\UserVO\Service\ApiClient;
use OCA\UserVO\UserVOAuth;
use Test\TestCase;

/**
 * Unit tests for UserVOAuth's pure logic - the actual VO API communication
 * (ApiClient) is mocked throughout, per this project's Unit/Integration split.
 *
 * ApiClient isn't constructor-injected (UserVOAuth resolves it from the DI
 * container internally), so tests substitute a mock via reflection after
 * construction - using the constructor's own "testing" parameters
 * (api_url/username/password) to skip the config-loading branch entirely.
 */
class UserVOAuthTest extends TestCase {
	private function createAuthWithMockedApiClient(ApiClient $apiClient): UserVOAuth {
		$auth = new UserVOAuth('https://vo.test/org', 'apiuser', 'apipass');

		$ref = new \ReflectionProperty(UserVOAuth::class, 'apiClient');
		$ref->setAccessible(true);
		$ref->setValue($auth, $apiClient);

		return $auth;
	}

	private function mockApiClient(callable $makeRequestCallback): ApiClient {
		$apiClient = $this->getMockBuilder(ApiClient::class)
			->disableOriginalConstructor()
			->getMock();
		$apiClient->method('makeRequest')->willReturnCallback($makeRequestCallback);
		return $apiClient;
	}

	// --- fetchUserDataFromVO() ---

	public function testFetchUserDataFromVOParsesSuccessfulResponse(): void {
		$auth = $this->createAuthWithMockedApiClient($this->mockApiClient(
			fn() => [
				'id' => '42',
				'userlogin' => 'jane.doe',
				'vorname' => 'Jane',
				'nachname' => 'Doe',
				'p_email' => 'jane@example.test',
				'gruppenids' => '1,2,3',
				'foto' => 'jane.jpg',
				'geloescht' => '0',
			]
		));

		$result = $auth->fetchUserDataFromVO('42');

		$this->assertEquals('42', $result['id']);
		$this->assertEquals('jane.doe', $result['username']);
		$this->assertEquals('Jane', $result['firstname']);
		$this->assertEquals('Doe', $result['lastname']);
		$this->assertEquals('jane@example.test', $result['email']);
		$this->assertEquals('1,2,3', $result['group_ids']);
		$this->assertFalse($result['_deleted']);
	}

	public function testFetchUserDataFromVOMarksDeletedUser(): void {
		$auth = $this->createAuthWithMockedApiClient($this->mockApiClient(
			fn() => ['id' => '1', 'userlogin' => 'gone.user', 'geloescht' => '1']
		));

		$result = $auth->fetchUserDataFromVO('1');

		$this->assertTrue($result['_deleted']);
	}

	public function testFetchUserDataFromVOReturnsErrorOnApiFailure(): void {
		$auth = $this->createAuthWithMockedApiClient($this->mockApiClient(fn() => null));

		$result = $auth->fetchUserDataFromVO('1');

		$this->assertEquals('api_error', $result['_error']);
	}

	public function testFetchUserDataFromVOReturnsErrorOnApiErrorField(): void {
		$auth = $this->createAuthWithMockedApiClient($this->mockApiClient(
			fn() => ['error' => 'Member not found']
		));

		$result = $auth->fetchUserDataFromVO('999');

		$this->assertEquals('api_error', $result['_error']);
		$this->assertEquals('Member not found', $result['_message']);
	}

	public function testFetchUserDataFromVOFiltersUsersWithoutLoginCredentials(): void {
		// Members without VO login credentials aren't real NC users - critical filter.
		$auth = $this->createAuthWithMockedApiClient($this->mockApiClient(
			fn() => ['id' => '1', 'vorname' => 'No', 'nachname' => 'Login', 'userlogin' => '']
		));

		$result = $auth->fetchUserDataFromVO('1');

		$this->assertEquals('no_login', $result['_error']);
	}

	// --- fetchMembersMapForUsers() ---

	public function testFetchMembersMapForUsersReturnsEmptyForEmptyTargetList(): void {
		$calls = 0;
		$auth = $this->createAuthWithMockedApiClient($this->mockApiClient(function () use (&$calls) {
			$calls++;
			return [];
		}));

		$map = $auth->fetchMembersMapForUsers([]);

		$this->assertEquals([], $map);
	}

	public function testFetchMembersMapForUsersReturnsEmptyWhenMemberListFetchFails(): void {
		$auth = $this->createAuthWithMockedApiClient($this->mockApiClient(fn() => null));

		$map = $auth->fetchMembersMapForUsers(['jane.doe']);

		$this->assertEquals([], $map);
	}

	public function testFetchMembersMapForUsersFindsExactNameMatch(): void {
		$auth = $this->createAuthWithMockedApiClient($this->mockApiClient(
			function ($url, $data) {
				if (str_contains($url, 'GetMembers')) {
					return [
						['id' => '1', 'name' => 'Doe, Jane'],
						['id' => '2', 'name' => 'Smith, John'],
					];
				}
				// GetMember detail calls
				$id = $data['id'];
				$members = [
					'1' => ['id' => '1', 'userlogin' => 'jane.doe'],
					'2' => ['id' => '2', 'userlogin' => 'john.smith'],
				];
				return $members[$id];
			}
		));

		$map = $auth->fetchMembersMapForUsers(['jane.doe']);

		$this->assertArrayHasKey('jane.doe', $map);
		$this->assertEquals('1', $map['jane.doe']['vo_user_id']);
		$this->assertEquals('jane.doe', $map['jane.doe']['vo_username']);
	}

	public function testFetchMembersMapForUsersStopsEarlyOnceAllTargetsFound(): void {
		$detailCallIds = [];
		$auth = $this->createAuthWithMockedApiClient($this->mockApiClient(
			function ($url, $data) use (&$detailCallIds) {
				if (str_contains($url, 'GetMembers')) {
					// "target.user" scores highest via fuzzy match and should be checked
					// first; the other 5 are irrelevant filler that should never be queried.
					return [
						['id' => '1', 'name' => 'User, Target'],
						['id' => '2', 'name' => 'Nobody, Aaa'],
						['id' => '3', 'name' => 'Nobody, Bbb'],
						['id' => '4', 'name' => 'Nobody, Ccc'],
						['id' => '5', 'name' => 'Nobody, Ddd'],
						['id' => '6', 'name' => 'Nobody, Eee'],
					];
				}
				$detailCallIds[] = $data['id'];
				return $data['id'] === '1' ? ['id' => '1', 'userlogin' => 'target.user'] : ['id' => $data['id'], 'userlogin' => 'irrelevant'];
			}
		));

		$map = $auth->fetchMembersMapForUsers(['target.user']);

		$this->assertArrayHasKey('target.user', $map);
		$this->assertCount(1, $detailCallIds, 'Must stop querying once the single target user is found, not check all 6 candidates');
	}

	public function testFetchMembersMapForUsersSkipsMembersWithoutLoginCredentials(): void {
		$auth = $this->createAuthWithMockedApiClient($this->mockApiClient(
			function ($url, $data) {
				if (str_contains($url, 'GetMembers')) {
					return [['id' => '1', 'name' => 'Doe, Jane']];
				}
				return ['id' => '1', 'userlogin' => '']; // no login credentials
			}
		));

		$map = $auth->fetchMembersMapForUsers(['jane.doe']);

		$this->assertEquals([], $map);
	}

	public function testFetchMembersMapForUsersReturnsPartialMapWhenNotAllFound(): void {
		$auth = $this->createAuthWithMockedApiClient($this->mockApiClient(
			function ($url, $data) {
				if (str_contains($url, 'GetMembers')) {
					return [['id' => '1', 'name' => 'Doe, Jane']];
				}
				return ['id' => '1', 'userlogin' => 'jane.doe'];
			}
		));

		$map = $auth->fetchMembersMapForUsers(['jane.doe', 'nobody.else']);

		$this->assertArrayHasKey('jane.doe', $map);
		$this->assertArrayNotHasKey('nobody.else', $map);
		$this->assertCount(1, $map);
	}
}
