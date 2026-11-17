<?php

declare(strict_types=1);

namespace App\Model\Mail;

enum MailStatus: string
{
	case Sent = 'sent';
	case Failed = 'failed';

	public function label(): string
	{
		return match ($this) {
			self::Sent => 'odesláno',
			self::Failed => 'chyba',
		};
	}

	public function tone(): string
	{
		return match ($this) {
			self::Sent => 'success',
			self::Failed => 'danger',
		};
	}
}
