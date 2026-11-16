<?php

declare(strict_types=1);

namespace App\Model\Meeting\Command;

use App\Model\Meeting\MeetingType;

final readonly class SaveMeeting
{
	/**
	 * @param ?int $number pořadové číslo v období; null = další v pořadí (při založení) / beze změny (při úpravě)
	 */
	public function __construct(
		public MeetingType $type,
		public \DateTimeImmutable $scheduledAt,
		public string $place,
		public ?int $number = null,
	) {}
}
