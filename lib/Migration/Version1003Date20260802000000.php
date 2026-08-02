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
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Make vo_group_id/nc_group_id unique on user_vo_groups.
 *
 * Closes a race where two concurrent "create group" requests for the same
 * VO group both pass the check-then-insert's existence check and each
 * insert their own row - previously nothing prevented or later repaired
 * the resulting duplicate. GroupManagementService::createGroupFromData()
 * now catches the resulting constraint violation and reports it as an
 * already-managed 409, same as the pre-check path.
 *
 * Since that race is exactly what this migration protects against going
 * forward, an install that already hit it could have duplicate rows sitting
 * in the table - adding a unique index directly on top of those would abort
 * the whole upgrade with a raw constraint-violation error and no guidance.
 * preSchemaChange() removes any pre-existing duplicates (keeping the oldest
 * row per vo_group_id, logging what it removed) before the index is added.
 */
class Version1003Date20260802000000 extends SimpleMigrationStep {
	private IDBConnection $connection;

	public function __construct(IDBConnection $connection) {
		$this->connection = $connection;
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 */
	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('user_vo_groups')) {
			return;
		}

		$qb = $this->connection->getQueryBuilder();
		$qb->select('vo_group_id')
			->from('user_vo_groups')
			->groupBy('vo_group_id')
			->having($qb->expr()->gt($qb->func()->count('*'), $qb->createNamedParameter(1, \PDO::PARAM_INT)));
		$result = $qb->executeQuery();
		$duplicatedGroupIds = array_column($result->fetchAll(), 'vo_group_id');
		$result->closeCursor();

		foreach ($duplicatedGroupIds as $voGroupId) {
			$rowsQb = $this->connection->getQueryBuilder();
			$rowsQb->select('id')
				->from('user_vo_groups')
				->where($rowsQb->expr()->eq('vo_group_id', $rowsQb->createNamedParameter($voGroupId)))
				->orderBy('id', 'ASC');
			$rows = $rowsQb->executeQuery()->fetchAll();

			// Keep the oldest row, delete the rest.
			$idsToDelete = array_column(array_slice($rows, 1), 'id');
			$output->warning(sprintf(
				'user_vo_groups had %d duplicate row(s) for vo_group_id "%s" (from the create-group '
				. 'race this migration closes) - kept id %d, removed id(s): %s',
				count($idsToDelete),
				$voGroupId,
				$rows[0]['id'],
				implode(', ', $idsToDelete)
			));

			$deleteQb = $this->connection->getQueryBuilder();
			$deleteQb->delete('user_vo_groups')
				->where($deleteQb->expr()->in('id', $deleteQb->createNamedParameter($idsToDelete, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
			$deleteQb->executeStatement();
		}
	}

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

		if ($table->hasIndex('user_vo_groups_vo_gid') && !$table->hasIndex('user_vo_groups_vo_gid_u')) {
			$table->dropIndex('user_vo_groups_vo_gid');
			$table->addUniqueIndex(['vo_group_id'], 'user_vo_groups_vo_gid_u');
			$output->info('Made vo_group_id unique on user_vo_groups');
			$changed = true;
		}

		if ($table->hasIndex('user_vo_groups_nc_gid') && !$table->hasIndex('user_vo_groups_nc_gid_u')) {
			$table->dropIndex('user_vo_groups_nc_gid');
			$table->addUniqueIndex(['nc_group_id'], 'user_vo_groups_nc_gid_u');
			$output->info('Made nc_group_id unique on user_vo_groups');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
