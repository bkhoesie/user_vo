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
 * Migrates the legacy enable_nightly_sync config key forward into
 * enable_nightly_user_sync (what it always actually meant - the current
 * admin UI's "user sync" checkbox writes both keys, and never wrote the
 * legacy one for group sync), then removes it, so every read site can drop
 * the fallback-to-legacy expression duplicated across four places.
 */
class MigrateLegacyNightlySyncFlag implements IRepairStep {
	private IConfig $config;

	public function __construct(IConfig $config) {
		$this->config = $config;
	}

	public function getName(): string {
		return 'Migrate legacy enable_nightly_sync config key to enable_nightly_user_sync';
	}

	public function run(IOutput $output): void {
		$legacyValue = $this->config->getAppValue('user_vo', 'enable_nightly_sync', '');
		if ($legacyValue === '') {
			return;
		}

		$newValueAlreadySet = $this->config->getAppValue('user_vo', 'enable_nightly_user_sync', '') !== '';
		if (!$newValueAlreadySet) {
			$this->config->setAppValue('user_vo', 'enable_nightly_user_sync', $legacyValue);
			$output->info('Migrated legacy enable_nightly_sync value to enable_nightly_user_sync');
		}

		$this->config->deleteAppValue('user_vo', 'enable_nightly_sync');
		$output->info('Removed legacy enable_nightly_sync config key');
	}
}
