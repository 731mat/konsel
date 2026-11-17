<?php

declare(strict_types=1);

namespace App\Model\Meeting;

use App\Model\Shared\Exception\InvalidStateException;

/**
 * Usnášeníschopnost: přítomna nadpoloviční většina všech členů orgánu (zákon o obcích § 92 odst. 3, u rady § 101).
 * „Všichni členové“ = počet členů orgánu (místa), ne jen právě obsazená.
 */
final readonly class Quorum
{
	public int $required;

	public function __construct(
		public int $members,
		public int $present,
	) {
		if ($members < 1 || $present < 0 || $present > $members) {
			throw new InvalidStateException("Neplatný počet pro usnášeníschopnost: přítomno {$present} z {$members}.");
		}

		$this->required = intdiv($members, 2) + 1;
	}

	public function isReached(): bool
	{
		return $this->present >= $this->required;
	}

	/**
	 * Kolik členů ještě chybí do usnášeníschopnosti.
	 */
	public function missing(): int
	{
		return max(0, $this->required - $this->present);
	}
}
