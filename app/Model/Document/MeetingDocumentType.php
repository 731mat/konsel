<?php

declare(strict_types=1);

namespace App\Model\Document;

enum MeetingDocumentType: string
{
	case Invitation = 'invitation';
	case Materials = 'materials';

	public function label(): string
	{
		return match ($this) {
			self::Invitation => 'Pozvánka / informace o zasedání',
			self::Materials => 'Podklady k zasedání',
		};
	}

	public function description(): string
	{
		return match ($this) {
			self::Invitation => 'K vyvěšení na úřední desku (§ 93 zákona o obcích) a pro zastupitele: datum, místo a navržený program.',
			self::Materials => 'Program s důvodovými zprávami, návrhy usnesení a seznamem příloh u každého bodu.',
		};
	}

	public function fileSlug(): string
	{
		return match ($this) {
			self::Invitation => 'pozvanka',
			self::Materials => 'podklady',
		};
	}
}
