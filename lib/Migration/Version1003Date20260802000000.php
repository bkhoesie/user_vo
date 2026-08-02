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
 */
class Version1003Date20260802000000 extends SimpleMigrationStep {

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
