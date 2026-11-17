<?php

declare(strict_types=1);

namespace App\Model\Meeting;

use App\Model\People\Body;
use App\Model\Shared\Exception\InvalidStateException;

/**
 * Kdy je návrh přijat. Zastupitelstvo (§ 87), rada (§ 101 odst. 2) i výbory: hlasů pro je víc než
 * polovina všech členů orgánu – nerozhoduje počet přítomných, zdržení se je fakticky hlas proti.
 */
final readonly class VotingRule
{
	public function __construct(
		public int $members,
	) {
		if ($members < 1) {
			throw new InvalidStateException('Orgán musí mít alespoň jednoho člena.');
		}
	}

	public static function forBody(Body $body): self
	{
		return new self($body->getSeatCount());
	}

	/**
	 * Nejmenší počet hlasů pro, který stačí k přijetí.
	 */
	public function required(): int
	{
		return intdiv($this->members, 2) + 1;
	}

	public function evaluate(int $for): VotingResult
	{
		return $for >= $this->required() ? VotingResult::Adopted : VotingResult::Rejected;
	}
}
