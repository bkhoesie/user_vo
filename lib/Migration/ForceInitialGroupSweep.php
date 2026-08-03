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

use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Forces exactly one sweep-driven resync of every existing managed group on
 * upgrade to this version. The dirty/clean ledger (Version1005Date20260803000000)
 * starts every row at 0/0 (clean) for schema-migration simplicity, but an
 * install upgrading to this fix may already carry real membership drift
 * caused by the very B1 gap the ledger closes going forward - 0/0 would
 * silently assert there is none. Bumping dirty_seq here means
 * GroupSyncSweepJob picks up every group on its next few ticks and repairs
 * whatever drift already existed, rather than only catching drift from here on.
 *
 * `SET dirty_seq = clean_seq + 1 WHERE dirty_seq <= clean_seq` (not a literal
 * 1, and not `= clean_seq`): post-migration repair steps run on *every* app
 * version bump, not just the one that introduces them (NC's
 * AppManager::upgradeApp() calls them unconditionally after migrate()). A
 * literal `dirty_seq = 1` only makes sense for a genuinely untouched (0,0)
 * row; on any later upgrade, a healthy *converged* group can legitimately be
 * at (N,N) for N > 0 from ordinary traffic, and setting dirty_seq back down
 * to 1 there wouldn't just no-op the backfill (dirty_seq stays <= clean_seq,
 * so findDirtyGroups() still ignores it) - it would leave dirty_seq trailing
 * N-1 increments behind clean_seq, meaning the group can't register as dirty
 * again until N more writes land. That's B1 silently reintroduced for
 * whatever real-world window it takes to accumulate N further writes -
 * exactly what this repair step exists to prevent, not cause. `clean_seq + 1`
 * always produces a value strictly greater than clean_seq regardless of N,
 * and `<=` (not `=`) makes this idempotent even if it already ran incorrectly
 * once: dirty_seq <= clean_seq is true for both a genuinely untouched row and
 * an already-inverted one, so either self-heals to the same correct shape.
 */
class ForceInitialGroupSweep implements IRepairStep {
	private IDBConnection $connection;

	public function __construct(IDBConnection $connection) {
		$this->connection = $connection;
	}

	public function getName(): string {
		return 'Mark all existing managed groups dirty for one initial sweep-driven resync';
	}

	public function run(IOutput $output): void {
		if (!$this->connection->tableExists('user_vo_groups')) {
			// Fresh install with no groups table yet - nothing to backfill.
			return;
		}

		$qb = $this->connection->getQueryBuilder();
		$qb->update('user_vo_groups')
			->set('dirty_seq', $qb->createFunction('clean_seq + 1'))
			->where($qb->expr()->lte('dirty_seq', 'clean_seq'));
		$affected = $qb->executeStatement();

		if ($affected > 0) {
			$output->info("Marked {$affected} existing managed group(s) dirty for an initial sweep-driven resync");
		}
	}
}
