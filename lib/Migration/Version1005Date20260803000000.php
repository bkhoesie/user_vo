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
 * Add dirty_seq/clean_seq to user_vo_groups: a monotonic per-group ledger
 * used by GroupSyncLedgerService to detect membership writes that a group
 * sync may have missed.
 *
 * The per-group sync lease (Version1004) closes sync-vs-sync races, but not
 * sync-vs-write races: a user's own VO-metadata write (the only writer of
 * user_vo.vo_group_ids) isn't synchronized with a concurrent full sync's read
 * of VO membership for that group. If the write lands after the sync's read
 * but before the sync finishes, the sync can overwrite NC membership with
 * data that doesn't reflect it - silently losing the write until the next
 * full sync of that group.
 *
 * dirty_seq is bumped (by the metadata writer) whenever a write may have
 * changed a group's membership predicate. clean_seq is advanced (by a
 * completed sync) to the dirty_seq value it captured *before* its own read -
 * never after, and never past a value it didn't itself observe as a
 * pre-read snapshot. dirty_seq > clean_seq means the group needs a resync;
 * GroupSyncSweepJob (Version1005+) periodically resyncs such groups through
 * the existing lease machinery. A boolean or timestamp flag can't express
 * "this mark is newer than the snapshot I acted on", which is why this needs
 * a monotonic counter pair instead.
 *
 * No new index: user_vo_groups holds one row per managed VO group (tens to
 * low hundreds in practice), and the app already full-scans it on admin page
 * loads and in syncAllManagedGroups() - a periodic scan for dirty_seq >
 * clean_seq costs the same order of magnitude and isn't worth a third
 * column/invariant to avoid.
 */
class Version1005Date20260803000000 extends SimpleMigrationStep {

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

		if (!$table->hasColumn('dirty_seq')) {
			$table->addColumn('dirty_seq', Types::BIGINT, [
				'notnull' => true,
				'default' => 0,
				'unsigned' => true,
				'comment' => 'Monotone counter bumped by writes that may have made NC membership stale vs. user_vo. Never reset, only incremented.'
			]);
			$output->info('Added column dirty_seq to user_vo_groups');
			$changed = true;
		}

		if (!$table->hasColumn('clean_seq')) {
			$table->addColumn('clean_seq', Types::BIGINT, [
				'notnull' => true,
				'default' => 0,
				'unsigned' => true,
				'comment' => 'Highest dirty_seq a completed sync had already accounted for. dirty_seq > clean_seq means the group needs a resync.'
			]);
			$output->info('Added column clean_seq to user_vo_groups');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
