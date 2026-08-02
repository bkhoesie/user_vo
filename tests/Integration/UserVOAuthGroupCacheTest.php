<?php
namespace OCA\UserVO\Tests\Integration;

use OCA\UserVO\Service\ApiClient;
use OCA\UserVO\UserVOAuth;
use OCP\ICache;
use OCP\ICacheFactory;
use Test\TestCase;

/**
 * Integration tests for UserVOAuth::fetchAllGroups()'s caching: only
 * allowCached: true callers (the login path) may get a cached/stale result;
 * every other caller (admin actions) always hits the API live. Overrides the
 * real ApiClient binding in NC's server container for the duration of each
 * test (restored in tearDown), matching the pattern already used for
 * syncUserPhoto()'s IClientService override.
 *
 * No explicit cache-invalidation-on-group-change exists (or is needed):
 * every GroupManagementService call site that touches groups (create,
 * delete's precondition check, bulk create/list) always does an
 * allowCached: false fetch, which - per testUncachedCallStillPopulatesTheCacheForLaterCachedCalls -
 * already refreshes the shared cache as a side effect regardless of whether
 * that specific call used it. So any admin action that creates or lists
 * groups already keeps the next login-triggered cached read fresh.
 *
 * Also overrides ICacheFactory so createDistributed() always returns the
 * same in-memory ICache instance. Under PHPUnit, Nextcloud forces all cache
 * backends to OC\Memcache\ArrayCache for test isolation (see
 * lib/private/Server.php's PHPUNIT_RUN check) - fine for real backends like
 * Redis, which are external/shared so a fresh PHP wrapper object still sees
 * the same data, but ArrayCache's storage is a plain instance property, so
 * two separately-constructed ArrayCache objects never share anything. The
 * app code intentionally resolves a fresh cache object on every call (which
 * is correct and cheap against a real backend); this override makes that
 * pattern work under the forced-ArrayCache test environment too, without
 * changing the app's actual caching architecture.
 *
 * @group DB
 */
class UserVOAuthGroupCacheTest extends TestCase {
	private const API_URL = 'https://vereinonline.org/test-cache-org';

	private ?ApiClient $originalApiClient = null;
	private ?ICacheFactory $originalCacheFactory = null;
	private ICache $cache;

	protected function setUp(): void {
		parent::setUp();

		$this->originalCacheFactory = \OC::$server->get(ICacheFactory::class);
		$this->cache = new \OC\Memcache\ArrayCache('test-user-vo-groups');
		$cache = $this->cache;
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);
		\OC::$server->registerService(ICacheFactory::class, fn () => $cacheFactory);
	}

	protected function tearDown(): void {
		if ($this->originalApiClient !== null) {
			$original = $this->originalApiClient;
			\OC::$server->registerService(ApiClient::class, fn () => $original);
			$this->originalApiClient = null;
		}
		if ($this->originalCacheFactory !== null) {
			$original = $this->originalCacheFactory;
			\OC::$server->registerService(ICacheFactory::class, fn () => $original);
			$this->originalCacheFactory = null;
		}
		parent::tearDown();
	}

	/**
	 * Registers a mock ApiClient for the rest of this test, returns it to
	 * configure. Safe to call more than once per test (e.g. to swap in
	 * different behavior partway through) - only the true original is ever
	 * saved for tearDown to restore, not a previously-installed mock.
	 */
	private function mockApiClient(): ApiClient {
		if ($this->originalApiClient === null) {
			$this->originalApiClient = \OC::$server->get(ApiClient::class);
		}

		$mock = $this->createMock(ApiClient::class);
		\OC::$server->registerService(ApiClient::class, fn () => $mock);

		return $mock;
	}

	private function createBackend(): UserVOAuth {
		return new UserVOAuth(self::API_URL, 'apiuser', 'apipass');
	}

	public function testAllowCachedFalseAlwaysHitsTheApi(): void {
		$apiClient = $this->mockApiClient();
		$apiClient->expects($this->exactly(2))->method('makeRequest')
			->willReturn([['id' => '1', 'name' => 'Group 1', 'parentid' => null, 'pos' => 1]]);

		$backend = $this->createBackend();
		$backend->fetchAllGroups(allowCached: false);
		$backend->fetchAllGroups(allowCached: false);
	}

	public function testAllowCachedTrueServesSecondCallFromCache(): void {
		$apiClient = $this->mockApiClient();
		$apiClient->expects($this->once())->method('makeRequest')
			->willReturn([['id' => '1', 'name' => 'Group 1', 'parentid' => null, 'pos' => 1]]);

		$backend = $this->createBackend();
		$first = $backend->fetchAllGroups(allowCached: true);
		$second = $backend->fetchAllGroups(allowCached: true);

		$this->assertEquals($first, $second);
	}

	public function testCachedResultIsTrimmedToTheProjectedFields(): void {
		$apiClient = $this->mockApiClient();
		$apiClient->method('makeRequest')->willReturn([
			['id' => '1', 'name' => 'Group 1', 'parentid' => null, 'pos' => 1, 'extra_field' => 'should not survive caching'],
		]);

		$backend = $this->createBackend();
		$live = $backend->fetchAllGroups(allowCached: true);
		$this->assertArrayHasKey('extra_field', $live[0], 'A live fetch should return the full data');

		$cached = $backend->fetchAllGroups(allowCached: true);
		$this->assertArrayNotHasKey('extra_field', $cached[0], 'The cached projection must be trimmed to id/name/parentid/pos');
		$this->assertEquals('1', $cached[0]['id']);
		$this->assertEquals('Group 1', $cached[0]['name']);
	}

	public function testUncachedCallStillPopulatesTheCacheForLaterCachedCalls(): void {
		$apiClient = $this->mockApiClient();
		$apiClient->expects($this->once())->method('makeRequest')
			->willReturn([['id' => '1', 'name' => 'Group 1', 'parentid' => null, 'pos' => 1]]);

		$backend = $this->createBackend();
		$backend->fetchAllGroups(allowCached: false);
		// Should be served from the cache the first call populated, not a second API hit.
		$cached = $backend->fetchAllGroups(allowCached: true);
		$this->assertNotNull($cached);
	}

	/**
	 * @return ApiClient A mock whose makeRequest() succeeds on the first call
	 *     and returns null (simulating a failed live fetch) on every call
	 *     after that. UserVOAuth resolves and caches its ApiClient once at
	 *     construction time, so a test that needs "succeeds, then later
	 *     fails" on the *same* backend instance must vary one mock's
	 *     behavior across calls rather than swapping the registered service
	 *     mid-test (which a constructed backend would never see).
	 */
	private function mockApiClientSucceedingOnceThenFailing(): ApiClient {
		$apiClient = $this->mockApiClient();
		$callCount = 0;
		$apiClient->method('makeRequest')->willReturnCallback(function () use (&$callCount) {
			$callCount++;
			return $callCount === 1 ? [['id' => '1', 'name' => 'Group 1', 'parentid' => null, 'pos' => 1]] : null;
		});
		return $apiClient;
	}

	public function testAllowCachedTrueFallsBackToStaleCacheWhenLiveFetchFails(): void {
		$this->mockApiClientSucceedingOnceThenFailing();
		$backend = $this->createBackend();
		$backend->fetchAllGroups(allowCached: true);

		// Simulate the short-lived entry expiring (TTL passed) while the
		// longer-lived stale fallback is still present.
		$this->cache->remove(md5(self::API_URL));

		// The second call's live fetch now fails (per the mock above).
		$result = $backend->fetchAllGroups(allowCached: true);
		$this->assertNotNull($result, 'Should fall back to the stale cached copy instead of failing outright');
		$this->assertEquals('1', $result[0]['id']);
	}

	public function testAllowCachedFalseReturnsNullOnFailureEvenWithAStaleCacheAvailable(): void {
		$this->mockApiClientSucceedingOnceThenFailing();
		$backend = $this->createBackend();
		$backend->fetchAllGroups(allowCached: true);

		// Admin/always-fresh callers must fail loudly, never silently serve stale data.
		$this->assertNull($backend->fetchAllGroups(allowCached: false));
	}
}
