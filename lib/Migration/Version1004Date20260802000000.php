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

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add sync_lock_until to user_vo_groups: a per-group DB-backed lease used by
 * GroupSyncLockService to serialize concurrent syncs of the same group.
 *
 * Nextcloud's 5-minute credential-token revalidation re-runs a full group
 * sync per active session on every login; at real production scale this
 * means shared/popular groups get resynced by many overlapping requests.
 * Without serialization, two overlapping syncs of the same group can read
 * different snapshots of user_vo (rewritten by other concurrent logins) and
 * the losing thread's stale read can silently restore a user's membership
 * in a group they were just removed from in VO. The lease closes that race.
 */
class Version1004Date20260802000000 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('user_vo_groups')) {
			return null;
		}

		$table = $schema->getTable('user_vo_groups');

		if (!$table->hasColumn('sync_lock_until')) {
			$table->addColumn('sync_lock_until', Types::DATETIME, [
				'notnull' => false,
				'comment' => 'Lease expiry for the per-group sync lock - NULL or in the past means unlocked'
			]);
			$output->info('Added column sync_lock_until to user_vo_groups');
			return $schema;
		}

		return null;
	}
}
