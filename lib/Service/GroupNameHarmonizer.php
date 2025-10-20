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

namespace OCA\UserVO\Service;

/**
 * Prepare VereinOnline group names for Nextcloud
 *
 * Nextcloud supports UTF-8 group names (umlauts, spaces, special chars).
 * This class only performs minimal sanitization:
 * - Enforce 64 character limit
 * - Handle empty/null names
 * - Trim whitespace
 */
class GroupNameHarmonizer {

	/**
	 * Prepare VereinOnline group name for Nextcloud
	 *
	 * Nextcloud natively supports UTF-8 including umlauts, spaces, and special characters.
	 * We preserve the original name and only enforce minimal constraints.
	 *
	 * @param string $voName Original VO group name
	 * @return string Sanitized name ready for NC
	 */
	public function harmonize(string $voName): string {
		// Step 1: Trim leading/trailing whitespace
		$name = trim($voName);

		// Step 2: Handle empty names - use MD5-based fallback
		if (empty($name)) {
			$name = 'group_' . substr(md5($voName ?: 'empty'), 0, 10);
			return $name;
		}

		// Step 3: Enforce 64 character limit (Nextcloud group name max length)
		if (mb_strlen($name) > 64) {
			$name = mb_substr($name, 0, 64);
			// Trim whitespace again if truncation created trailing space
			$name = rtrim($name);
		}

		return $name;
	}

	/**
	 * Check if a group name needs sanitization
	 *
	 * @param string $voName Original VO group name
	 * @return bool True if sanitization would change the name
	 */
	public function needsHarmonization(string $voName): bool {
		return $this->harmonize($voName) !== $voName;
	}

	/**
	 * Get both original and sanitized names
	 *
	 * @param string $voName Original VO group name
	 * @return array ['original' => string, 'harmonized' => string, 'changed' => bool]
	 */
	public function getNames(string $voName): array {
		$harmonized = $this->harmonize($voName);
		return [
			'original' => $voName,
			'harmonized' => $harmonized,
			'changed' => $voName !== $harmonized
		];
	}

}
