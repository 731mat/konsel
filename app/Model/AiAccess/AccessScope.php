<?php

declare(strict_types=1);

namespace App\Model\AiAccess;

/**
 * Rozsah připojení AI: jen čtení, nebo i změny (přidání bodu, komentáře…). Vždy jen v mezích role uživatele.
 */
enum AccessScope: string
{
	case Read = 'read';
	case Write = 'write';

	public function label(): string
	{
		return match ($this) {
			self::Read => 'jen čtení',
			self::Write => 'čtení i změny',
		};
	}

	/**
	 * Hodnota "scope" v odpovědi OAuth.
	 */
	public function oauthScope(): string
	{
		return match ($this) {
			self::Read => 'read',
			self::Write => 'read write',
		};
	}

	/**
	 * Požadovaný scope od klienta ("read write", "konsel:write"…) – změny jen při výslovném "write".
	 */
	public static function fromOAuthScope(?string $scope): self
	{
		$items = preg_split('/\s+/', (string) $scope);
		foreach ($items === false ? [] : $items as $item) {
			if ($item === 'write' || substr($item, -6) === ':write') {
				return self::Write;
			}
		}

		return self::Read;
	}
}
