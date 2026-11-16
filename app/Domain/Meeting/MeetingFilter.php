<?php

declare(strict_types=1);

namespace App\Domain\Meeting;

use App\Domain\People\Body;

/**
 * Kritéria seznamu zasedání (null = bez omezení).
 */
final readonly class MeetingFilter
{
	public function __construct(
		public ?Body $body = null,
		public ?int $year = null,
		public ?MeetingStatus $status = null,
	) {}
}
