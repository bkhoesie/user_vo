<?php
/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

if (!defined('PHPUNIT_RUN')) {
	define('PHPUNIT_RUN', 1);
}

// Load Nextcloud's test bootstrap (provides Test\TestCase and full NC environment)
require_once __DIR__ . '/../../../../lib/base.php';

// Add Test namespace for Nextcloud test helpers
\OC::$composerAutoloader->addPsr4('Test\\', OC::$SERVERROOT . '/tests/lib/', true);

// Load the user_vo app
\OC_App::loadApp('user_vo');

// Clear all hooks to ensure clean test state
OC_Hook::clear();
