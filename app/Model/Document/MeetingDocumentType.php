<?php

declare(strict_types=1);

namespace App\Model\Document;

use App\Model\Meeting\MeetingStatus;

enum MeetingDocumentType: string
{
	case Invitation = 'invitation';
	case Materials = 'materials';
	case Minutes = 'minutes';
	case Resolutions = 'resolutions';
	case AttendanceSheet = 'attendance';

	public function label(): string
	{
		return match ($this) {
			self::Invitation => 'Pozvánka / informace o zasedání',
			self::Materials => 'Podklady k zasedání',
			self::Minutes => 'Zápis ze zasedání',
			self::Resolutions => 'Usnesení ze zasedání',
			self::AttendanceSheet => 'Prezenční listina',
		};
	}

	public function description(): string
	{
		return match ($this) {
			self::Invitation => 'K vyvěšení na úřední desku (§ 93 zákona o obcích) a pro zastupitele: datum, místo a navržený program.',
			self::Materials => 'Program s důvodovými zprávami, návrhy usnesení a seznamem příloh u každého bodu.',
			self::Minutes => 'Náležitosti podle § 95 zákona o obcích: přítomní, program, diskuse, výsledky hlasování a usnesení s čísly, podpisy. Do 10 dnů od zasedání.',
			self::Resolutions => 'Všechna přijatá usnesení s čísly a podpisy starosty a místostarosty – k vyvěšení a do archivu.',
			self::AttendanceSheet => 'Členové orgánu s místem pro podpis a řádky pro hosty. Vytiskněte před zasedáním.',
		};
	}

	public function fileSlug(): string
	{
		return match ($this) {
			self::Invitation => 'pozvanka',
			self::Materials => 'podklady',
			self::Minutes => 'zapis',
			self::Resolutions => 'usneseni',
			self::AttendanceSheet => 'prezencni-listina',
		};
	}

	/**
	 * Dokument dává smysl až v určité fázi zasedání (zápis až po zahájení).
	 */
	public function isAvailableFor(MeetingStatus $status): bool
	{
		return match ($this) {
			self::Invitation, self::Materials, self::AttendanceSheet => true,
			self::Minutes, self::Resolutions => in_array($status, [MeetingStatus::InProgress, MeetingStatus::Closed, MeetingStatus::MinutesApproved], true),
		};
	}

	/**
	 * Lze stáhnout i pro úpravy ve Wordu (.doc).
	 */
	public function isEditable(): bool
	{
		return in_array($this, [self::Minutes, self::Resolutions, self::AttendanceSheet], true);
	}
}
