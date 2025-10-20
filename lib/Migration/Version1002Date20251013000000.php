<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2025 Nikolaus Demmel <nikolaus@nikolaus-demmel.de>
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
 * Create user_vo_groups table for managing synchronized groups from VereinOnline:
 * - Tracks which VO groups have been created in Nextcloud
 * - Stores sync status and member counts
 * - Detects deleted/renamed groups
 */
class Version1002Date20251013000000 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// Create user_vo_groups table if it doesn't exist
		if (!$schema->hasTable('user_vo_groups')) {
			$table = $schema->createTable('user_vo_groups');

			// Primary key
			$table->addColumn('id', Types::INTEGER, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);

			// VereinOnline group ID
			$table->addColumn('vo_group_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
				'comment' => 'VereinOnline group ID'
			]);

			// VereinOnline group name (updated during sync)
			$table->addColumn('vo_group_name', Types::STRING, [
				'notnull' => true,
				'length' => 255,
				'comment' => 'Current group name in VereinOnline (updated during sync)'
			]);

			// Nextcloud group ID (uservo_ prefix format)
			$table->addColumn('nc_group_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
				'comment' => 'NC group ID in uservo_{vo_group_id} format'
			]);

			// Nextcloud group display name (harmonized from VO name)
			$table->addColumn('nc_display_name', Types::STRING, [
				'notnull' => false,
				'length' => 255,
				'comment' => 'Current display name in Nextcloud (auto-synced from VO)'
			]);

			// Hierarchy and sorting
			$table->addColumn('vo_parent_id', Types::STRING, [
				'notnull' => false,
				'length' => 64,
				'comment' => 'Parent group ID in VereinOnline (0 or empty = root group)'
			]);

			$table->addColumn('vo_position', Types::INTEGER, [
				'notnull' => false,
				'unsigned' => true,
				'comment' => 'Sort position within sibling groups in VereinOnline'
			]);

			$table->addColumn('vo_position_index', Types::STRING, [
				'notnull' => false,
				'length' => 64,
				'comment' => 'Full hierarchical position index (e.g., "2", "2.5", "2.5.1") for sorting'
			]);

			// Deleted flag (set when group no longer exists in VO)
			$table->addColumn('deleted_in_vo', Types::SMALLINT, [
				'notnull' => true,
				'default' => 0,
				'length' => 1,
				'comment' => '1 if group was deleted in VereinOnline, 0 otherwise'
			]);

			// Last sync timestamp
			$table->addColumn('last_synced', Types::DATETIME, [
				'notnull' => false,
				'comment' => 'Last time group members were synchronized'
			]);

			// Member count fields (cached)
			$table->addColumn('member_count', Types::INTEGER, [
				'notnull' => false,
				'unsigned' => true,
				'comment' => 'Total number of members in NC group'
			]);

			$table->addColumn('vo_member_count', Types::INTEGER, [
				'notnull' => false,
				'unsigned' => true,
				'comment' => 'Number of VO backend users in group'
			]);

			$table->addColumn('non_vo_member_count', Types::INTEGER, [
				'notnull' => false,
				'unsigned' => true,
				'comment' => 'Number of non-VO users in group (local/LDAP/etc)'
			]);

			// Set primary key
			$table->setPrimaryKey(['id']);

			// Add indexes for efficient lookups
			$table->addIndex(['vo_group_id'], 'user_vo_groups_vo_gid');
			$table->addIndex(['nc_group_id'], 'user_vo_groups_nc_gid');

			$output->info('Created table user_vo_groups');

			return $schema;
		}

		return null;
	}
}
