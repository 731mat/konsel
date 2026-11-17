<?php

declare(strict_types=1);

namespace App\Model\Resolution;

enum ResolutionStatus: string
{
	case Adopted = 'adopted';
	case Rejected = 'rejected';
	case Postponed = 'postponed';
	case Revoked = 'revoked';

	public function label(): string
	{
		return match ($this) {
			self::Adopted => 'přijato',
			self::Rejected => 'zamítnuto',
			self::Postponed => 'odloženo',
			self::Revoked => 'revokováno',
		};
	}

	public function tone(): string
	{
		return match ($this) {
			self::Adopted => 'success',
			self::Rejected => 'danger',
			self::Postponed => 'warning',
			self::Revoked => 'secondary',
		};
	}
}
