<?php

declare(strict_types=1);

namespace App\Model\Audit;

enum AuditAction: string
{
	case Created = 'created';
	case Updated = 'updated';
	case Deleted = 'deleted';

	public function label(): string
	{
		return match ($this) {
			self::Created => 'vytvořeno',
			self::Updated => 'změněno',
			self::Deleted => 'smazáno',
		};
	}

	public function tone(): string
	{
		return match ($this) {
			self::Created => 'success',
			self::Updated => 'primary',
			self::Deleted => 'danger',
		};
	}
}
