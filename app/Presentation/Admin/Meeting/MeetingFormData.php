<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Meeting;

final class MeetingFormData
{
	/**
	 * Jen při založení (orgán zasedání nelze později změnit).
	 */
	public ?int $body = null;

	public string $type;

	public \DateTimeImmutable $scheduledAt;

	public string $place;

	public ?int $number = null;
}
