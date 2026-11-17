<?php

declare(strict_types=1);

namespace App\Model\Shared;

/**
 * Řazení podle české abecedy (Č za C, Ř za R, „ch“ za H) – pro seznamy osob v úředních dokumentech.
 */
final class CzechCollation
{
	private static ?\Collator $collator = null;

	public static function compare(string $a, string $b): int
	{
		self::$collator ??= new \Collator('cs_CZ');
		$result = self::$collator->compare($a, $b);

		return $result === false ? strcmp($a, $b) : $result;
	}
}
