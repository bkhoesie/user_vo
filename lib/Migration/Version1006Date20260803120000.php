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
 * Creates user_vo_audit_log: a DB-backed audit trail for state changes and
 * failures this plugin is responsible for (logins, provisioning, group
 * membership, sync failures, config changes) - not a general activity feed,
 * and deliberately not a log file. The driving constraint is that the
 * Hetzner-managed production deployment has no direct nextcloud.log access
 * without going through support, so anything worth debugging later needs to
 * be queryable via the DB access this deployment does have (occ or a small
 * admin-UI page), with its own bounded retention (see AuditLogCleanupJob)
 * rather than relying on log rotation.
 */
class Version1006Date20260803120000 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('user_vo_audit_log')) {
			return null;
		}

		$table = $schema->createTable('user_vo_audit_log');

		$table->addColumn('id', Types::INTEGER, [
			'autoincrement' => true,
			'notnull' => true,
			'unsigned' => true,
		]);

		$table->addColumn('created_at', Types::DATETIME, [
			'notnull' => true,
			'comment' => 'When the event was logged - indexed for both retention cleanup and display ordering'
		]);

		$table->addColumn('event_type', Types::STRING, [
			'notnull' => true,
			'length' => 64,
			'comment' => 'Short machine-readable event kind, e.g. login_failed, group_membership_changed'
		]);

		$table->addColumn('uid', Types::STRING, [
			'notnull' => false,
			'length' => 64,
			'comment' => 'NC/VO uid involved, if applicable'
		]);

		$table->addColumn('group_id', Types::STRING, [
			'notnull' => false,
			'length' => 64,
			'comment' => 'VO group id involved, if applicable'
		]);

		$table->addColumn('message', Types::TEXT, [
			'notnull' => true,
			'comment' => 'Human-readable detail - never includes secrets (passwords, API credentials)'
		]);

		$table->setPrimaryKey(['id']);
		$table->addIndex(['created_at'], 'user_vo_audit_log_created_at');

		$output->info('Created table user_vo_audit_log');

		return $schema;
	}
}
