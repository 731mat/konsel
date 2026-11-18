<?php

declare(strict_types=1);

namespace App\Model\AiAccess;

enum AccessTokenKind: string
{
	/**
	 * Token, který si uživatel vytvořil sám a vložil do AI klienta (hlavička Authorization).
	 */
	case Personal = 'personal';

	/**
	 * Aplikace připojená přihlášením a souhlasem (OAuth) – Claude, ChatGPT…
	 */
	case OAuth = 'oauth';

	public function label(): string
	{
		return match ($this) {
			self::Personal => 'Osobní token',
			self::OAuth => 'Připojená aplikace',
		};
	}
}
