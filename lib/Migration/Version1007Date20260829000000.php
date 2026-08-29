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
 * Add vo_group_size and last_sync_attempt_at to user_vo_groups.
 *
 * vo_group_size is VO's own reported member count for a group (from the
 * direct GetMembers(filter=gruppe=<id>) fetch group sync now uses), distinct
 * from the existing vo_member_count (an NC-side count of current NC group
 * members whose backend is VO). They diverge whenever a VO group member has
 * never logged into NC - vo_group_size answers "how big does VO say this
 * group is", not "how many of the group's current NC members came from VO".
 *
 * last_sync_attempt_at is a scheduling-ordering signal, unrelated to
 * last_synced (which means "last successful sync" and drives the admin UI).
 * It's stamped on every sync attempt for a group, success or failure, so
 * GroupSyncLedgerService::findDirtyGroups() and syncAllManagedGroups()'s
 * query ordering can deprioritize a group that just failed instead of
 * retrying the same permanently-broken group first on every tick forever.
 * Stored as an integer Unix timestamp (not DATETIME) specifically so it can
 * be NOT NULL with a portable epoch-0 sentinel default - relying on
 * NULL-ordering for "never attempted sorts first" is DB-dependent
 * (SQLite/MySQL sort NULL first under ASC, PostgreSQL sorts it last), and
 * this app's CI only runs against SQLite, so that assumption could ship
 * silently broken on Postgres. Epoch 0 sorts first under ASC everywhere.
 */
class Version1007Date20260829000000 extends SimpleMigrationStep {

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
		$changed = false;

		if (!$table->hasColumn('vo_group_size')) {
			$table->addColumn('vo_group_size', Types::INTEGER, [
				'notnull' => false,
				'unsigned' => true,
				'comment' => 'VO-reported member count for this group (from GetMembers(filter=gruppe=<id>)), independent of whether those members have NC accounts'
			]);
			$output->info('Added column vo_group_size to user_vo_groups');
			$changed = true;
		}

		if (!$table->hasColumn('last_sync_attempt_at')) {
			$table->addColumn('last_sync_attempt_at', Types::BIGINT, [
				'notnull' => true,
				'default' => 0,
				'unsigned' => true,
				'comment' => 'Unix timestamp of the last sync attempt for this group, success or failure - scheduling-ordering signal only, distinct from last_synced (last successful sync)'
			]);
			$output->info('Added column last_sync_attempt_at to user_vo_groups');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
