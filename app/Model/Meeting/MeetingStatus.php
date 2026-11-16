<?php

declare(strict_types=1);

namespace App\Model\Meeting;

/**
 * Životní cyklus zasedání. Přechody mezi stavy popisuje MeetingTransition, hlídá je Meeting.
 */
enum MeetingStatus: string
{
	case Draft = 'draft';
	case Shared = 'shared';
	case InProgress = 'in_progress';
	case Closed = 'closed';
	case MinutesApproved = 'minutes_approved';

	public function label(): string
	{
		return match ($this) {
			self::Draft => 'V přípravě',
			self::Shared => 'Sdíleno',
			self::InProgress => 'Probíhá',
			self::Closed => 'Ukončeno',
			self::MinutesApproved => 'Zápis ověřen',
		};
	}

	/**
	 * Zasedání ještě nezačalo – lze měnit datum, místo, typ a program.
	 */
	public function isPlanning(): bool
	{
		return $this === self::Draft || $this === self::Shared;
	}

	/**
	 * Zasedání proběhlo (ukončeno, případně i s ověřeným zápisem).
	 */
	public function isFinished(): bool
	{
		return $this === self::Closed || $this === self::MinutesApproved;
	}
}
