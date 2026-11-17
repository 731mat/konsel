<?php

declare(strict_types=1);

namespace App\Model\Search;

/**
 * Co se dá najít. Kód slouží k sestavení jednoznačného rowid v indexu (kód × miliarda + ID entity) – neměnit.
 */
enum SearchDocumentType: string
{
	case Meeting = 'meeting';
	case AgendaItem = 'agenda_item';
	case Resolution = 'resolution';
	case ItemComment = 'item_comment';
	case Project = 'project';
	case ProjectComment = 'project_comment';
	case ResolutionTemplate = 'resolution_template';
	case MeetingTemplate = 'meeting_template';

	public const int RowIdFactor = 1_000_000_000;

	public function label(): string
	{
		return match ($this) {
			self::Meeting => 'Zasedání',
			self::AgendaItem => 'Bod programu',
			self::Resolution => 'Usnesení',
			self::ItemComment => 'Diskuse u bodu',
			self::Project => 'Úkolníček',
			self::ProjectComment => 'Diskuse v úkolníčku',
			self::ResolutionTemplate => 'Šablona usnesení',
			self::MeetingTemplate => 'Šablona programu',
		};
	}

	public function icon(): string
	{
		return match ($this) {
			self::Meeting => 'calendar-event',
			self::AgendaItem => 'list-ol',
			self::Resolution => 'journal-check',
			self::ItemComment, self::ProjectComment => 'chat-left-text',
			self::Project => 'kanban',
			self::ResolutionTemplate, self::MeetingTemplate => 'files',
		};
	}

	public function code(): int
	{
		return match ($this) {
			self::Meeting => 1,
			self::AgendaItem => 2,
			self::Resolution => 3,
			self::ItemComment => 4,
			self::Project => 5,
			self::ProjectComment => 6,
			self::ResolutionTemplate => 7,
			self::MeetingTemplate => 8,
		};
	}

	public function rowId(int $entityId): int
	{
		return $this->code() * self::RowIdFactor + $entityId;
	}
}
