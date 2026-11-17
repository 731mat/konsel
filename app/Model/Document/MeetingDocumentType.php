<?php

declare(strict_types=1);

namespace App\Model\Document;

use App\Model\Meeting\MeetingStatus;

enum MeetingDocumentType: string
{
	case Invitation = 'invitation';
	case Materials = 'materials';
	case Minutes = 'minutes';

	public function label(): string
	{
		return match ($this) {
			self::Invitation => 'Pozvánka / informace o zasedání',
			self::Materials => 'Podklady k zasedání',
			self::Minutes => 'Zápis ze zasedání',
		};
	}

	public function description(): string
	{
		return match ($this) {
			self::Invitation => 'K vyvěšení na úřední desku (§ 93 zákona o obcích) a pro zastupitele: datum, místo a navržený program.',
			self::Materials => 'Program s důvodovými zprávami, návrhy usnesení a seznamem příloh u každého bodu.',
			self::Minutes => 'Náležitosti podle § 95 zákona o obcích: přítomní, program, diskuse, výsledky hlasování a usnesení s čísly, podpisy. Do 10 dnů od zasedání.',
		};
	}

	public function fileSlug(): string
	{
		return match ($this) {
			self::Invitation => 'pozvanka',
			self::Materials => 'podklady',
			self::Minutes => 'zapis',
		};
	}

	/**
	 * Dokument dává smysl až v určité fázi zasedání (zápis až po zahájení).
	 */
	public function isAvailableFor(MeetingStatus $status): bool
	{
		return match ($this) {
			self::Invitation, self::Materials => true,
			self::Minutes => in_array($status, [MeetingStatus::InProgress, MeetingStatus::Closed, MeetingStatus::MinutesApproved], true),
		};
	}

	/**
	 * Lze stáhnout i pro úpravy ve Wordu (.doc).
	 */
	public function isEditable(): bool
	{
		return $this === self::Minutes;
	}
}
