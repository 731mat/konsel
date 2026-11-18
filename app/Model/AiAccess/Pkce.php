<?php

declare(strict_types=1);

namespace App\Model\AiAccess;

/**
 * PKCE (RFC 7636) – jen metoda S256, OAuth 2.1 plain nepovoluje.
 */
final class Pkce
{
	public static function isValidChallenge(string $challenge): bool
	{
		return preg_match('/^[A-Za-z0-9_-]{43,128}$/', $challenge) === 1;
	}

	public static function verify(#[\SensitiveParameter] string $verifier, string $challenge): bool
	{
		if (preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier) !== 1) {
			return false;
		}

		return hash_equals($challenge, rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='));
	}
}
