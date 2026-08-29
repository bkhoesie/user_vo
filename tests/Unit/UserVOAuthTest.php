<?php
namespace OCA\UserVO\Tests\Unit;

use OCA\UserVO\Service\ApiClient;
use OCA\UserVO\Service\Exception\VoGroupDataUnusableException;
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

	/**
	 * An 'error' marker on the per-candidate GetMember response must take
	 * precedence over an otherwise-present, otherwise-matching userlogin -
	 * without this check, a response carrying both would previously have
	 * been added to the map anyway (only `empty($memberData['userlogin'])`
	 * was checked), silently accepting VO's own error indicator as if it
	 * were real data for that candidate.
	 */
	public function testFetchMembersMapForUsersSkipsMemberWhenErrorMarkerIsPresentEvenWithAUserlogin(): void {
		$auth = $this->createAuthWithMockedApiClient($this->mockApiClient(
			function ($url, $data) {
				if (str_contains($url, 'GetMembers')) {
					return [['id' => '1', 'name' => 'Doe, Jane']];
				}
				return ['id' => '1', 'userlogin' => 'jane.doe', 'error' => 'rate limited'];
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

	// --- fetchAllMembers() / fetchAllGroups() malformed-response rejection ---
	//
	// VO reports errors (auth failure, rate limit, transient backend issue,
	// ...) as a single associative array like {"error": "..."} - still a
	// non-empty, truthy PHP array once decoded. A caller that only checked
	// "is this a non-empty array" would treat it as "VO has zero
	// groups/members", which then looks identical to every managed
	// group/member having been deleted (the actual bug this covers).

	public function testFetchAllMembersReturnsNullOnVOErrorShapedResponse(): void {
		$auth = $this->createAuthWithMockedApiClient($this->mockApiClient(
			fn() => ['error' => 'Zugriff verweigert']
		));

		$this->assertNull($auth->fetchAllMembers());
	}

	public function testFetchAllMembersReturnsNullWhenEntriesAreNotRecords(): void {
		// Any other non-list-of-records shape must be rejected too, not just
		// the specific {"error": ...} convention.
		$auth = $this->createAuthWithMockedApiClient($this->mockApiClient(
			fn() => ['just', 'some', 'strings']
		));

		$this->assertNull($auth->fetchAllMembers());
	}

	public function testFetchAllMembersReturnsDataForAWellFormedList(): void {
		$auth = $this->createAuthWithMockedApiClient($this->mockApiClient(
			fn() => [['id' => '1', 'name' => 'Doe, Jane']]
		));

		$this->assertEquals([['id' => '1', 'name' => 'Doe, Jane']], $auth->fetchAllMembers());
	}

	// These are shapes a non-array-entries check alone would miss (a single
	// associative array whose *values* happen to be arrays too) - each one
	// is still a non-empty, truthy PHP array, so a caller checking only "is
	// this a non-empty array" would treat it as real VO data with zero
	// usable entries, the same production incident this covers. Explicit
	// per-shape test methods, not a data provider - this codebase doesn't
	// use PHPUnit data providers elsewhere, and the docblock-style
	// @dataProvider annotation isn't portable across every PHPUnit version
	// this app's CI matrix runs (confirmed: fails with an ArgumentCountError
	// on at least one PHP/PHPUnit combination).

	public function testFetchAllMembersRejectsErrorEnvelopeWithArrayValue(): void {
		$auth = $this->createAuthWithMockedApiClient($this->mockApiClient(
			fn() => ['errors' => [['code' => 5]]]
		));

		$this->assertNull($auth->fetchAllMembers());
	}

	public function testFetchAllMembersRejectsWrappedEnvelopeResponse(): void {
		$auth = $this->createAuthWithMockedApiClient($this->mockApiClient(
			fn() => ['data' => [['id' => '1']], 'meta' => []]
		));

		$this->assertNull($auth->fetchAllMembers());
	}

	public function testFetchAllMembersRejectsDifferentlyKeyedEnvelope(): void {
		$auth = $this->createAuthWithMockedApiClient($this->mockApiClient(
			fn() => ['result' => [], 'meta' => []]
		));

		$this->assertNull($auth->fetchAllMembers());
	}

	public function testFetchAllMembersRejectsListOfRecordsMissingId(): void {
		$auth = $this->createAuthWithMockedApiClient($this->mockApiClient(
			fn() => [['name' => 'No ID Member']]
		));

		$this->assertNull($auth->fetchAllMembers());
	}

	public function testFetchAllMembersRejectsListContainingAnEmptyRecord(): void {
		$auth = $this->createAuthWithMockedApiClient($this->mockApiClient(
			fn() => [[], []]
		));

		$this->assertNull($auth->fetchAllMembers());
	}

	/**
	 * fetchAllGroups() shares the exact same isWellFormedVOList() check as
	 * fetchAllMembers() above - one representative malformed shape here just
	 * confirms it's actually wired up, not a full re-test of every case.
	 */
	public function testFetchAllGroupsRejectsWrappedEnvelopeResponse(): void {
		$auth = $this->createAuthWithMockedApiClient($this->mockApiClient(
			fn() => ['data' => [['id' => '1']], 'meta' => []]
		));

		$this->assertNull($auth->fetchAllGroups());
	}

	public function testFetchAllGroupsReturnsNullOnVOErrorShapedResponse(): void {
		$auth = $this->createAuthWithMockedApiClient($this->mockApiClient(
			fn() => ['error' => 'Rate limited']
		));

		$this->assertNull($auth->fetchAllGroups());
	}

	public function testFetchAllGroupsReturnsDataForAWellFormedList(): void {
		$auth = $this->createAuthWithMockedApiClient($this->mockApiClient(
			fn() => [['id' => '1', 'name' => 'Test Group', 'parentid' => null, 'pos' => 1]]
		));

		$groups = $auth->fetchAllGroups();

		$this->assertNotNull($groups);
		$this->assertEquals('1', $groups[0]['id']);
	}

	// --- fetchGroupMembers() ---
	//
	// Split failure handling (see CLAUDE.md's "Group Membership Sync"
	// section): a transport/HTTP-level failure (null from makeRequest())
	// returns null - the ONLY signal circuit breakers upstream treat as
	// "VO looks unreachable". A malformed-but-present response throws
	// VoGroupDataUnusableException instead - a per-group problem, deliberately
	// never counted toward that same breaker. A well-formed EMPTY list is a
	// third, valid outcome - VO's own report that this group currently has
	// zero direct members - and must be returned normally, not treated as
	// either failure shape.

	public function testFetchGroupMembersReturnsNullOnTransportFailure(): void {
		$auth = $this->createAuthWithMockedApiClient($this->mockApiClient(fn() => null));

		$this->assertNull($auth->fetchGroupMembers('123'));
	}

	public function testFetchGroupMembersReturnsWellFormedEmptyListNormally(): void {
		$auth = $this->createAuthWithMockedApiClient($this->mockApiClient(fn() => []));

		$this->assertSame([], $auth->fetchGroupMembers('123'));
	}

	public function testFetchGroupMembersReturnsDataForAWellFormedList(): void {
		$auth = $this->createAuthWithMockedApiClient($this->mockApiClient(
			fn() => [['id' => '1', 'name' => 'Doe, Jane']]
		));

		$members = $auth->fetchGroupMembers('123');

		$this->assertNotNull($members);
		$this->assertEquals('1', $members[0]['id']);
	}

	public function testFetchGroupMembersThrowsOnErrorEnvelopeResponse(): void {
		$auth = $this->createAuthWithMockedApiClient($this->mockApiClient(
			fn() => ['error' => 'Rate limited']
		));

		$this->expectException(VoGroupDataUnusableException::class);
		$auth->fetchGroupMembers('123');
	}

	public function testFetchGroupMembersThrowsOnListMissingId(): void {
		$auth = $this->createAuthWithMockedApiClient($this->mockApiClient(
			fn() => [['name' => 'No ID Member']]
		));

		$this->expectException(VoGroupDataUnusableException::class);
		$auth->fetchGroupMembers('123');
	}

	public function testFetchGroupMembersUsesTheGruppeFilter(): void {
		$capturedData = null;
		$auth = $this->createAuthWithMockedApiClient($this->mockApiClient(
			function ($url, $data) use (&$capturedData) {
				$capturedData = $data;
				return [];
			}
		));

		$auth->fetchGroupMembers('6298');

		$this->assertEquals(['filter' => 'gruppe=6298'], $capturedData);
	}
}
