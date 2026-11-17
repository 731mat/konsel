<?php

declare(strict_types=1);

namespace App\Model\Document;

enum ArchivedDocumentOrigin: string
{
	case Generated = 'generated';
	case Uploaded = 'uploaded';
	case Final = 'final';

	public function label(): string
	{
		return match ($this) {
			self::Generated => 'Uložená verze',
			self::Uploaded => 'Podepsaný originál',
			self::Final => 'Po ověření zápisu',
		};
	}

	public function icon(): string
	{
		return match ($this) {
			self::Generated => 'file-earmark-pdf',
			self::Uploaded => 'pen',
			self::Final => 'patch-check',
		};
	}
}
