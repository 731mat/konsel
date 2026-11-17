<?php

declare(strict_types=1);

namespace App\Model\Meeting;

/**
 * Povolené přechody stavového automatu zasedání:
 * Draft → Shared → InProgress → Closed → MinutesApproved, navíc znovuotevření (jen správce, s důvodem):
 * Closed → InProgress a MinutesApproved → Closed (zrušení ověření zápisu).
 */
enum MeetingTransition: string
{
	case Share = 'share';
	case Start = 'start';
	case Close = 'close';
	case ApproveMinutes = 'approve_minutes';
	case Reopen = 'reopen';
	case ReopenMinutes = 'reopen_minutes';

	public function source(): MeetingStatus
	{
		return match ($this) {
			self::Share => MeetingStatus::Draft,
			self::Start => MeetingStatus::Shared,
			self::Close => MeetingStatus::InProgress,
			self::ApproveMinutes => MeetingStatus::Closed,
			self::Reopen => MeetingStatus::Closed,
			self::ReopenMinutes => MeetingStatus::MinutesApproved,
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
			self::ReopenMinutes => MeetingStatus::Closed,
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
			self::ReopenMinutes => 'Zrušit ověření zápisu',
		};
	}

	/**
	 * Znovuotevření: jen správce obce a vždy s důvodem (Meeting::reopen()).
	 */
	public function isReopening(): bool
	{
		return $this === self::Reopen || $this === self::ReopenMinutes;
	}
}
