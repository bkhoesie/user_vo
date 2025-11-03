<?php
/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserVO\Tests\Unit\Service;

use OCA\UserVO\Service\GroupNameHarmonizer;
use Test\TestCase;

/**
 * Unit tests for GroupNameHarmonizer
 * Tests group name harmonization logic
 */
class GroupNameHarmonizerTest extends TestCase {
	private GroupNameHarmonizer $harmonizer;

	protected function setUp(): void {
		parent::setUp();
		$this->harmonizer = new GroupNameHarmonizer();
	}

	/**
	 * Test short group names remain unchanged
	 */
	public function testShortGroupNamesUnchanged(): void {
		$result = $this->harmonizer->harmonize('Short Name');

		$this->assertSame('Short Name', $result);
	}

	/**
	 * Test group names at exactly 64 characters remain unchanged
	 */
	public function testExactly64CharactersUnchanged(): void {
		// Create name that's exactly 64 characters
		$name = str_repeat('a', 64);
		$result = $this->harmonizer->harmonize($name);

		$this->assertSame($name, $result);
		$this->assertSame(64, mb_strlen($result));
	}

	/**
	 * Test long group names get truncated
	 */
	public function testLongGroupNamesGetTruncated(): void {
		$longName = 'A Very Long Group Name That Exceeds The Maximum Length Of Sixty Four Characters';

		$result = $this->harmonizer->harmonize($longName);

		// Should be truncated to 64 chars or less (trailing space trimmed)
		$this->assertLessThanOrEqual(64, mb_strlen($result));

		// Should start with the original name
		$this->assertStringStartsWith('A Very Long Group Name', $result);
	}

	/**
	 * Test truncation is consistent for same input
	 */
	public function testTruncationIsConsistent(): void {
		$name = str_repeat('Long Group Name ', 10); // > 64 chars

		$result1 = $this->harmonizer->harmonize($name);
		$result2 = $this->harmonizer->harmonize($name);

		$this->assertSame($result1, $result2);
	}

	/**
	 * Test empty group name gets MD5 fallback
	 */
	public function testEmptyGroupNameGetsFallback(): void {
		$result = $this->harmonizer->harmonize('');

		// Should get group_ prefix with MD5 hash
		$this->assertStringStartsWith('group_', $result);
		$this->assertSame(16, mb_strlen($result)); // 'group_' + 10 chars
	}

	/**
	 * Test whitespace-only name gets MD5 fallback
	 */
	public function testWhitespaceOnlyNameGetsFallback(): void {
		$result = $this->harmonizer->harmonize('   ');

		// Should get group_ prefix with MD5 hash
		$this->assertStringStartsWith('group_', $result);
	}

	/**
	 * Test special characters in group name
	 */
	public function testSpecialCharactersInName(): void {
		$name = 'Gruppe für Schüler & Lehrer (über 18 Jahre) - Spezial!';

		$result = $this->harmonizer->harmonize($name);

		// Short enough to not need truncation
		$this->assertSame($name, $result);
	}

	/**
	 * Test Unicode characters in long name
	 */
	public function testUnicodeCharactersInLongName(): void {
		$name = 'Musikschüler für Klavier, Geige, Trompete und andere Instrumente über 18 Jahre';

		$result = $this->harmonizer->harmonize($name);

		// Should be truncated to 64 chars or less
		$this->assertLessThanOrEqual(64, mb_strlen($result));

		// Should start with original name
		$this->assertStringStartsWith('Musikschüler für Klavier', $result);
	}

	/**
	 * Test needsHarmonization() detects changes
	 */
	public function testNeedsHarmonizationDetectsChanges(): void {
		$shortName = 'Short Name';
		$longName = str_repeat('a', 100);

		$this->assertFalse($this->harmonizer->needsHarmonization($shortName));
		$this->assertTrue($this->harmonizer->needsHarmonization($longName));
	}

	/**
	 * Test getNames() returns complete info
	 */
	public function testGetNamesReturnsCompleteInfo(): void {
		$longName = str_repeat('Test ', 20); // > 64 chars

		$result = $this->harmonizer->getNames($longName);

		$this->assertArrayHasKey('original', $result);
		$this->assertArrayHasKey('harmonized', $result);
		$this->assertArrayHasKey('changed', $result);
		$this->assertSame($longName, $result['original']);
		$this->assertTrue($result['changed']);
		$this->assertLessThanOrEqual(64, mb_strlen($result['harmonized']));
	}

	/**
	 * Test trailing whitespace after truncation is trimmed
	 */
	public function testTrailingWhitespaceAfterTruncationIsTrimmed(): void {
		// Create name that when truncated at 64 chars would end with space
		$name = str_repeat('word ', 20); // Will create trailing space at char 64

		$result = $this->harmonizer->harmonize($name);

		// Should not end with whitespace
		$this->assertNotSame(' ', mb_substr($result, -1));
	}
}
