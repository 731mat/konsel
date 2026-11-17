<?php

declare(strict_types=1);

namespace App\Model\Resolution;

/**
 * Kritéria přehledu usnesení (null = bez omezení). Text hledá ve znění i čísle.
 */
final readonly class ResolutionSearch
{
	public function __construct(
		public ?int $bodyId = null,
		public ?int $year = null,
		public ?ResolutionStatus $status = null,
		public ?string $text = null,
	) {}

	public function isEmpty(): bool
	{
		return $this->bodyId === null && $this->year === null && $this->status === null && ($this->text === null || trim($this->text) === '');
	}
}
