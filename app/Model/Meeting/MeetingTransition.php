<?php

declare(strict_types=1);

namespace App\Model\Meeting;

/**
 * Povolené přechody stavového automatu zasedání:
 * Draft → Shared → InProgress → Closed → MinutesApproved, navíc Closed → InProgress (znovuotevření, jen správce).
 */
enum MeetingTransition: string
{
	case Share = 'share';
	case Start = 'start';
	case Close = 'close';
	case ApproveMinutes = 'approve_minutes';
	case Reopen = 'reopen';

	public function source(): MeetingStatus
	{
		return match ($this) {
			self::Share => MeetingStatus::Draft,
			self::Start => MeetingStatus::Shared,
			self::Close => MeetingStatus::InProgress,
			self::ApproveMinutes => MeetingStatus::Closed,
			self::Reopen => MeetingStatus::Closed,
		};
	}

	public function target(): MeetingStatus
	{
		return match ($this) {
			self::Share => MeetingStatus::Shared,
			self::Start => MeetingStatus::InProgress,
			self::Close => MeetingStatus::Closed,
			self::ApproveMinutes => MeetingStatus::MinutesApproved,
			self::Reopen => MeetingStatus::InProgress,
		};
	}

	public function label(): string
	{
		return match ($this) {
			self::Share => 'Sdílet podklady',
			self::Start => 'Zahájit zasedání',
			self::Close => 'Ukončit zasedání',
			self::ApproveMinutes => 'Zápis je ověřen',
			self::Reopen => 'Znovu otevřít',
		};
	}
}
