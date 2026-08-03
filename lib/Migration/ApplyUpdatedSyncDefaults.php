<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Nikolaus Demmel <nikolaus@nikolaus-demmel.de>
 *
 * @author Nikolaus Demmel <nikolaus@nikolaus-demmel.de>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\UserVO\Migration;

use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Backfills sync_photo and enable_nightly_user_sync to 'true' for installs
 * that never explicitly saved a value for them - matching this version's new
 * defaults - without touching any install that ever did save a value
 * (whether true or false), since a stored value is a real, deliberate choice
 * regardless of when it was made.
 *
 * "Never explicitly saved" is detected the same way
 * MigrateLegacyNightlySyncFlag detects an untouched enable_nightly_user_sync:
 * an empty-string getAppValue() default, since every writer of these keys
 * (ConfigController::saveUserSyncSettings()/saveNightlySyncSetting()) always
 * writes an explicit 'true'/'false' string, never leaves the key half-set.
 *
 * Must run after MigrateLegacyNightlySyncFlag (registration order in
 * info.xml): that step may itself set enable_nightly_user_sync from the old
 * enable_nightly_sync key - once it has, this step correctly sees a
 * non-empty value and leaves it alone, respecting whatever the admin
 * originally chose via the legacy toggle.
 *
 * enable_nightly_group_sync gets no such treatment here - it has never
 * shipped in a release, so its code-level default alone (see
 * UserVOAdminSettings/ConfigController/SyncUsersJob) already covers every
 * install; there is no "existing value" to preserve.
 *
 * sync_email is unaffected - it already defaulted to 'true'.
 */
class ApplyUpdatedSyncDefaults implements IRepairStep {
	private IConfig $config;

	public function __construct(IConfig $config) {
		$this->config = $config;
	}

	public function getName(): string {
		return 'Default sync_photo and enable_nightly_user_sync to enabled for installs that never set them';
	}

	public function run(IOutput $output): void {
		foreach (['sync_photo', 'enable_nightly_user_sync'] as $key) {
			if ($this->config->getAppValue('user_vo', $key, '') === '') {
				$this->config->setAppValue('user_vo', $key, 'true');
				$output->info("Defaulted {$key} to enabled (was never explicitly set)");
			}
		}
	}
}
