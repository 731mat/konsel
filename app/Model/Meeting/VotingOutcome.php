<?php

declare(strict_types=1);

namespace App\Model\Meeting;

use App\Model\Resolution\Resolution;

/**
 * Výsledek ukončeného hlasování a případné usnesení s číslem.
 */
final readonly class VotingOutcome
{
	public function __construct(
		public VotingResult $result,
		public ?Resolution $resolution,
	) {}
}
