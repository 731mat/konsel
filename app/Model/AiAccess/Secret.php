<?php

declare(strict_types=1);

namespace App\Model\AiAccess;

/**
 * Náhodné tajné řetězce (tokeny, kódy, client secret). V DB se ukládá jen SHA-256 hash.
 */
final class Secret
{
	public static function generate(string $prefix = ''): string
	{
		return $prefix . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
	}

	public static function hash(#[\SensitiveParameter] string $secret): string
	{
		return hash('sha256', $secret);
	}
}
