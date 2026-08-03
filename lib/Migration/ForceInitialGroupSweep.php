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
 * silently assert there is none. Setting dirty_seq = 1 here means
 * GroupSyncSweepJob picks up every group on its next few ticks and repairs
 * whatever drift already existed, rather than only catching drift from here on.
 *
 * `WHERE dirty_seq = clean_seq` makes this idempotent: a group already
 * legitimately dirty (e.g. from a write that landed between the schema
 * migration and this repair step) is left alone rather than having its real
 * dirty_seq clobbered back down to 1.
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
			->set('dirty_seq', $qb->createNamedParameter(1, \PDO::PARAM_INT))
			->where($qb->expr()->eq('dirty_seq', 'clean_seq'));
		$affected = $qb->executeStatement();

		if ($affected > 0) {
			$output->info("Marked {$affected} existing managed group(s) dirty for an initial sweep-driven resync");
		}
	}
}
