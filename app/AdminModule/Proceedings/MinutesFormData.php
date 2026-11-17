<?php

declare(strict_types=1);

namespace App\AdminModule\Proceedings;

final class MinutesFormData
{
	public ?string $startedAt = null;

	public ?string $endedAt = null;

	public ?int $guestCount = null;

	public ?string $minutesNote = null;
}
