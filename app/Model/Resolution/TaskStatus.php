<?php

declare(strict_types=1);

namespace App\Model\Resolution;

enum TaskStatus: string
{
	case Open = 'open';
	case Done = 'done';
	case Cancelled = 'cancelled';

	public function label(): string
	{
		return match ($this) {
			self::Open => 'otevřený',
			self::Done => 'splněný',
			self::Cancelled => 'zrušený',
		};
	}

	public function tone(): string
	{
		return match ($this) {
			self::Open => 'primary',
			self::Done => 'success',
			self::Cancelled => 'secondary',
		};
	}
}
