<?php
namespace OCA\UserVO\Tests\Unit\Controller;

use Test\TestCase;

/**
 * Regression test for a real CSRF gap found via review: several state-changing POST
 * endpoints (ConfigController::saveConfiguration/testConfiguration/clearConfiguration/
 * saveUserSyncSettings/saveNightlySyncSetting, all 3 GroupSyncController sync endpoints,
 * UserSyncController::syncFromVO/syncSelectedUsers) carried a stale @NoCSRFRequired
 * annotation, meaning a cross-site POST from a page an admin merely visits could rewrite
 * the VO API config or trigger a sync with no CSRF check. Nextcloud's CSRF check only
 * applies to non-GET requests, so @NoCSRFRequired must never appear on a route routed as
 * POST/PUT/DELETE. This test parses the real appinfo/routes.php and reflects on the real
 * controller methods, so it fails immediately if the annotation reappears on any
 * state-changing route - by accident or via a new route added later.
 */
class CsrfAnnotationTest extends TestCase {
	private const APP_ROOT = __DIR__ . '/../../..';

	public function testNoStateChangingRouteAllowsCsrfBypass(): void {
		$routes = require self::APP_ROOT . '/appinfo/routes.php';
		$violations = [];

		foreach ($routes['routes'] as $route) {
			if ($route['verb'] === 'GET') {
				continue;
			}

			[$controllerShortName, $methodName] = explode('#', $route['name']);
			$className = 'OCA\\UserVO\\Controller\\' . $this->pascalCase($controllerShortName) . 'Controller';

			$this->assertTrue(class_exists($className), "Controller class $className referenced by route {$route['name']} does not exist");

			$reflectionMethod = new \ReflectionMethod($className, $methodName);
			$docComment = $reflectionMethod->getDocComment() ?: '';

			if (str_contains($docComment, '@NoCSRFRequired')) {
				$violations[] = "{$route['verb']} {$route['url']} -> $className::$methodName";
			}
		}

		$this->assertEmpty(
			$violations,
			"State-changing routes must never carry @NoCSRFRequired (see class docblock for why):\n" . implode("\n", $violations)
		);
	}

	private function pascalCase(string $snakeCase): string {
		return str_replace('_', '', ucwords($snakeCase, '_'));
	}
}
