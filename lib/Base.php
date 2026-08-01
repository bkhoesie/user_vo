<?php
/**
 * @author Jonas Sulzer <jonas@violoncello.ch>
 * @author Christian Weiske <cweiske@cweiske.de>
 * @author Nikolaus Demmel <nikolaus@nikolaus-demmel.de>
 * @copyright (c) 2014 Christian Weiske <cweiske@cweiske.de>
 *                2023 Nikolaus Demmel <nikolaus@nikolaus-demmel.de>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the LICENSE file.
 */
namespace OCA\UserVO;

/**
 * Base class for external auth implementations that stores users
 * on their first login in a local table.
 * This is required for making many of the user-related ownCloud functions
 * work, including sharing files with them.
 *
 * @category Apps
 * @package  UserVO
 * @author   Christian Weiske <cweiske@cweiske.de>
 * @author   Nikolaus Demmel <nikolaus@nikolaus-demmel.de>
 * @license  http://www.gnu.org/licenses/agpl AGPL
 * @link     http://github.com/owncloud/apps
 */
abstract class Base extends \OC\User\Backend {
	protected $backend = '';

	/**
	 * Create new instance, set backend name
	 *
	 * @param string $backend Identifier of the backend
	 */
	public function __construct($backend) {
		$this->backend = $backend;
	}

	/**
	 * Delete a user
	 *
	 * @param string $uid The username of the user to delete
	 *
	 * @return bool
	 */
	public function deleteUser($uid) {
		$connection = \OC::$server->get(\OCP\IDBConnection::class);
		$query = $connection->getQueryBuilder();
		
		// Delete user with exact uid or uid + !duplicate suffix
		$query->delete('user_vo')
			->where($query->expr()->orX(
				$query->expr()->eq('uid', $query->createNamedParameter($uid)),
				$query->expr()->eq('uid', $query->createNamedParameter($uid . '!duplicate'))
			))
			->andWhere($query->expr()->eq('backend', $query->createNamedParameter($this->backend)));
		$query->executeStatement();
		
		return true;
	}

	/**
	 * Helper to strip the !duplicate marker from a uid
	 */
	private function stripDuplicateMarker($uid) {
		if (str_ends_with($uid, '!duplicate')) {
			return substr($uid, 0, -10);
		}
		return $uid;
	}

	/**
	 * Helper to format display name with (D) prefix for duplicates
	 */
	private function formatDisplayName($storedUid, $storedDisplayName) {
		$displayName = !empty($storedDisplayName) ? $storedDisplayName : $this->stripDuplicateMarker($storedUid);
		
		// Add (D) prefix if this is a duplicate
		if (str_ends_with($storedUid, '!duplicate')) {
			return '(D) ' . $displayName;
		}
		
		return $displayName;
	}

	/**
	 * Handle login: First check if exact capitalization exists (exposed or canonical),
	 * otherwise map to canonical user. For new users, create as lowercase.
	 */
	public function checkPassword($uid, $password) {
		// Step 1: Check if this exact capitalization exists in user_vo
		if ($this->userExists($uid)) {
			// User with this exact capitalization is exposed/canonical - login directly
			return $this->checkCanonicalPassword($uid, $password);
		}
		
		// Step 2: No exact match - find canonical user for this normalized username
		$normalizedUid = strtolower($uid);
		$canonicalUid = $this->findCanonicalUserForNormalizedUid($normalizedUid);
		
		if ($canonicalUid) {
			// Canonical user exists - login as canonical
			return $this->checkCanonicalPassword($canonicalUid, $password);
		}
		
		// Step 3: No user exists - for new users, create as lowercase
		return $this->checkCanonicalPassword($normalizedUid, $password);
	}
	
	/**
	 * Find the canonical user (first user without !duplicate marker) for a normalized uid
	 */
	private function findCanonicalUserForNormalizedUid($normalizedUid) {
		$connection = \OC::$server->get(\OCP\IDBConnection::class);
		$query = $connection->getQueryBuilder();
		$query->select('uid')
			->from('user_vo')
			->where($query->expr()->eq('backend', $query->createNamedParameter($this->backend)))
			->andWhere($query->expr()->notLike('uid', $query->createNamedParameter('%!duplicate')))
			->andWhere($query->expr()->eq(
				$query->func()->lower('uid'), 
				$query->createNamedParameter($normalizedUid)
			))
			->setMaxResults(1);
		$result = $query->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row ? $row['uid'] : null;
	}



	/**
	 * Canonical password check (to be implemented in subclass).
	 */
	abstract protected function checkCanonicalPassword($uid, $password);

	/**
	 * Get display name of the user, strip !duplicate marker from returned uid.
	 */
	public function getDisplayName($uid) {
		$connection = \OC::$server->get(\OCP\IDBConnection::class);
		$query = $connection->getQueryBuilder();
		
		// Find user with exact uid or uid + !duplicate suffix
		$query->select('uid', 'displayname')
			->from('user_vo')
			->where($query->expr()->orX(
				$query->expr()->eq('uid', $query->createNamedParameter($uid)),
				$query->expr()->eq('uid', $query->createNamedParameter($uid . '!duplicate'))
			))
			->andWhere($query->expr()->eq('backend', $query->createNamedParameter($this->backend)));
		$result = $query->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		
		if (!$row) {
			return $uid; // Fallback to the original uid
		}
		
		return $this->formatDisplayName($row['uid'], $row['displayname']);
	}

	/**
	 * Get a list of all display names and user ids (strip !duplicate marker from returned uids).
	 */
	public function getDisplayNames($search = '', $limit = null, $offset = null) {
		$connection = \OC::$server->get(\OCP\IDBConnection::class);
		$query = $connection->getQueryBuilder();
		$query->select('uid', 'displayname')
			->from('user_vo')
			->where($query->expr()->eq('backend', $query->createNamedParameter($this->backend)));
		if ($search) {
			$query->andWhere(
				$query->expr()->orX(
					$query->expr()->iLike('displayname', $query->createNamedParameter('%' . $connection->escapeLikeParameter($search) . '%')),
					$query->expr()->iLike('uid', $query->createNamedParameter('%' . $connection->escapeLikeParameter($search) . '%'))
				)
			);
		}
		if ($limit) {
			$query->setMaxResults($limit);
		}
		if ($offset) {
			$query->setFirstResult($offset);
		}
		$result = $query->executeQuery();

		$displayNames = [];
		while ($row = $result->fetch()) {
			$displayNames[$this->stripDuplicateMarker($row['uid'])] = $this->formatDisplayName($row['uid'], $row['displayname']);
		}
		$result->closeCursor();

		return $displayNames;
	}

	/**
	 * Get a list of all users (strip !duplicate marker from returned uids)
	 */
	public function getUsers($search = '', $limit = null, $offset = null) {
		$connection = \OC::$server->get(\OCP\IDBConnection::class);
		$query = $connection->getQueryBuilder();
		$query->select('uid')
			->from('user_vo')
			->where($query->expr()->eq('backend', $query->createNamedParameter($this->backend)));
		if ($search) {
			$query->andWhere($query->expr()->iLike('uid', $query->createNamedParameter($connection->escapeLikeParameter($search) . '%')));
		}
		if ($limit) {
			$query->setMaxResults($limit);
		}
		if ($offset) {
			$query->setFirstResult($offset);
		}
		$result = $query->executeQuery();

		$users = [];
		while ($row = $result->fetch()) {
			$users[] = $this->stripDuplicateMarker($row['uid']);
		}
		$result->closeCursor();

		return $users;
	}

	/**
	 * Determines if the backend can enlist users
	 *
	 * @return bool
	 */
	public function hasUserListings() {
		return true;
	}

	/**
	 * Change the display name of a user (strip !duplicate marker from input and table entries).
	 */
	public function setDisplayName($uid, $displayName) {
		// Strip "(D) " prefix if present - Nextcloud might pass this back to us
		// since we return display names with this prefix for duplicates
		if (str_starts_with($displayName, '(D) ')) {
			$displayName = substr($displayName, 4);
		}
		
		$connection = \OC::$server->get(\OCP\IDBConnection::class);
		$query = $connection->getQueryBuilder();
		$query->update('user_vo')
			->set('displayname', $query->createNamedParameter($displayName))
			->where($query->expr()->orX(
				$query->expr()->eq('uid', $query->createNamedParameter($uid)),
				$query->expr()->eq('uid', $query->createNamedParameter($uid . '!duplicate'))
			))
			->andWhere($query->expr()->eq('backend', $query->createNamedParameter($this->backend)));
		$query->executeStatement();
		return true;
	}

	/**
	 * Create user record in database
	 *
	 * @param string $uid The username - should be lowercase for new users, existing capitalization for existing users
	 * @param array $groups Groups to add the user to on creation
	 *
	 * @return void
	 */
	public function storeUser($uid, $groups = []) {
		// Check for !duplicate marker - this should never happen for any user
		if (str_ends_with($uid, '!duplicate')) {
			error_log("ERROR: storeUser() called with !duplicate marker '$uid'. This indicates a serious bug in the login flow. Stripping marker.");
			$uid = $this->stripDuplicateMarker($uid);
		}
		
		if (!$this->userExists($uid)) {
			// This is a new user - verify it's lowercase (as per our design)
			if ($uid !== strtolower($uid)) {
				error_log("WARNING: storeUser() creating new user with non-lowercase uid '$uid'. This suggests a bug in the login flow. Forcing lowercase.");
				$uid = strtolower($uid);
			}
			
			// uid is now clean and lowercase for new users
			$cleanUid = $uid;
			
			$query = \OC::$server->get(\OCP\IDBConnection::class)->getQueryBuilder();
			$query->insert('user_vo')
				->values([
					'uid' => $query->createNamedParameter($uid),
					'backend' => $query->createNamedParameter($this->backend),
				]);
			$query->executeStatement();

			if ($groups) {
				$createduser = \OC::$server->get(\OCP\IUserManager::class)->get($cleanUid);
				foreach ($groups as $group) {
					\OC::$server->get(\OCP\IGroupManager::class)->createGroup($group)->addUser($createduser);
				}
			}
		}
	}

	/**
	 * Check if a user exists (exact case-sensitive matching).
	 * Input should never contain !duplicate markers.
	 */
	public function userExists($uid) {
		// Input should never have markers - it comes from Nextcloud core
		$connection = \OC::$server->get(\OCP\IDBConnection::class);
		$query = $connection->getQueryBuilder();
		$query->select('uid')
			->from('user_vo')
			->where($query->expr()->orX(
				$query->expr()->eq('uid', $query->createNamedParameter($uid)),
				$query->expr()->eq('uid', $query->createNamedParameter($uid . '!duplicate'))
			))
			->andWhere($query->expr()->eq('backend', $query->createNamedParameter($this->backend)));
		$result = $query->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row !== false;
	}

	/**
	 * Count the number of users.
	 *
	 * @return int|bool The number of users on success false on failure
	 */
	public function countUsers() {
		$connection = \OC::$server->get(\OCP\IDBConnection::class);
		$query = $connection->getQueryBuilder();
		$query->select($query->func()->count('*', 'num_users'))
			->from('user_vo')
			->where($query->expr()->eq('backend', $query->createNamedParameter($this->backend)));
		$result = $query->executeQuery();
		$users = $result->fetchColumn();
		$result->closeCursor();

		return $users > 0;
	}


}
