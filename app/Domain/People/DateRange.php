<?php

declare(strict_types=1);

namespace App\Domain\People;

/**
 * Práce s uzavřenými intervaly dnů [od, do], kde `do = null` znamená „dosud“.
 */
final class DateRange
{
	public static function contains(\DateTimeImmutable $from, ?\DateTimeImmutable $to, \DateTimeImmutable $date): bool
	{
		$day = $date->format('Y-m-d');

		return $from->format('Y-m-d') <= $day && ($to === null || $day <= $to->format('Y-m-d'));
	}

	public static function overlap(
		\DateTimeImmutable $fromA,
		?\DateTimeImmutable $toA,
		\DateTimeImmutable $fromB,
		?\DateTimeImmutable $toB,
	): bool {
		$aStartsBeforeBEnds = $toB === null || $fromA->format('Y-m-d') <= $toB->format('Y-m-d');
		$bStartsBeforeAEnds = $toA === null || $fromB->format('Y-m-d') <= $toA->format('Y-m-d');

		return $aStartsBeforeBEnds && $bStartsBeforeAEnds;
	}
}
