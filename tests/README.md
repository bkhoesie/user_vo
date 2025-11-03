# User VO - Test Suite

Multi-layer testing strategy for the User VO plugin.

## Test Layers

### Layer 1: Smoke Tests (Bash + curl)

Fast regression tests to verify API endpoints are accessible.

**Run tests:**
```bash
./tests/smoke/test_api.sh                      # All tests (~10s)
./tests/smoke/test_api.sh --verbose            # Detailed output
./tests/smoke/test_api.sh --endpoint /admin/config-status  # Single endpoint
```

**Coverage:** 9/27 endpoints (33%)
- Tests read-only endpoints only
- Skips destructive operations (create/delete/sync)
- Skips slow endpoints (search ~8s)

**Use cases:**
- Pre-commit sanity check
- Before/after refactoring
- Quick verification after deployment

---

### Layer 2: Unit Tests (PHPUnit)

Tests individual classes in isolation using mocks.

**Run tests:**
```bash
./tests/run-unit-tests.sh                           # All unit tests
./tests/run-unit-tests.sh Service/ConfigServiceTest.php  # Specific test
```

**Current coverage:**
- `ConfigService`: 8 tests - Configuration loading precedence, password masking
- `GroupNameHarmonizer`: 11 tests - Name truncation, Unicode handling, fallbacks

**Test structure:**
```
tests/Unit/
├── bootstrap.php          # PHPUnit bootstrap
├── phpunit.xml            # PHPUnit configuration
├── Service/
│   ├── ConfigServiceTest.php
│   └── GroupNameHarmonizerTest.php
└── Controller/
    └── (future controller tests)
```

**Writing unit tests:**

```php
<?php
namespace OCA\UserVO\Tests\Unit\Service;

use OCA\UserVO\Service\MyService;
use OCP\SomeDependency;
use Test\TestCase;

class MyServiceTest extends TestCase {
    private SomeDependency $dependency;
    private MyService $service;

    protected function setUp(): void {
        parent::setUp();

        // Create mocks for dependencies
        $this->dependency = $this->createMock(SomeDependency::class);

        // Inject mocks into service
        $this->service = new MyService($this->dependency);
    }

    public function testSomeMethod(): void {
        // Arrange: Set up mock expectations
        $this->dependency->expects($this->once())
            ->method('doSomething')
            ->with('input')
            ->willReturn('output');

        // Act: Call the method
        $result = $this->service->someMethod('input');

        // Assert: Verify result
        $this->assertSame('expected', $result);
    }
}
```

---

### Layer 3: Integration Tests (PHPUnit + Nextcloud)

**Status:** Not yet implemented

Will test components with real Nextcloud environment (database, group manager, etc.).

**Planned coverage:**
- Controller integration tests
- Service integration tests with real database
- End-to-end API tests

---

## Running All Tests

```bash
# Layer 1: Smoke tests
./tests/smoke/test_api.sh

# Layer 2: Unit tests
./tests/run-unit-tests.sh

# Layer 3: Integration tests (when implemented)
# ./tests/run-integration-tests.sh
```

## Test Development Workflow

**Before refactoring:**
1. Run smoke tests to establish baseline
2. Run existing unit tests
3. Add new unit tests for code being refactored

**During refactoring:**
1. Run tests frequently
2. Add tests for new classes/methods
3. Update tests if behavior changes intentionally

**After refactoring:**
1. Verify all tests still pass
2. Run smoke tests to verify API compatibility
3. Manual testing for complex flows

## Coverage Goals

- **Layer 1 (Smoke):** 33% endpoint coverage (read-only operations)
- **Layer 2 (Unit):** 80%+ coverage of service layer logic
- **Layer 3 (Integration):** Key user flows and API endpoints

## CI/CD Integration

Tests can be run in CI by mounting the plugin into a Nextcloud container:

```bash
docker compose exec -u 33 stable31 bash -c \
  "cd /var/www/html/apps-shared/user_vo && phpunit -c tests/Unit/phpunit.xml"
```

## Troubleshooting

**PHPUnit not found:**
- Tests must run inside Nextcloud container
- Use `./tests/run-unit-tests.sh` helper script

**Test isolation issues:**
- Each test should clean up after itself
- Use `setUp()` and `tearDown()` for test fixtures
- Mock external dependencies

**Coverage warnings:**
- Xdebug required for coverage reports
- Warnings can be ignored for basic test runs
