<?php

declare(strict_types=1);

namespace App\Model\Audit;

enum AuditEntityType: string
{
	case Meeting = 'meeting';
	case AgendaItem = 'agenda_item';
	case VotingRound = 'voting_round';
	case Vote = 'vote';
	case Resolution = 'resolution';
	case Attendance = 'attendance';
	case Membership = 'membership';
	case User = 'user';
	case ResolutionTask = 'resolution_task';
	case Attachment = 'attachment';
	case Recording = 'recording';
	case ArchivedDocument = 'archived_document';
	case MeetingReopening = 'meeting_reopening';

	public function label(): string
	{
		return match ($this) {
			self::Meeting => 'Zasedání',
			self::AgendaItem => 'Bod programu',
			self::VotingRound => 'Hlasování',
			self::Vote => 'Hlas',
			self::Resolution => 'Usnesení',
			self::Attendance => 'Docházka',
			self::Membership => 'Členství v orgánu',
			self::User => 'Uživatel',
			self::ResolutionTask => 'Úkol z usnesení',
			self::Attachment => 'Příloha',
			self::Recording => 'Záznam zasedání',
			self::ArchivedDocument => 'Archiv dokumentů',
			self::MeetingReopening => 'Znovuotevření',
		};
	}
}
