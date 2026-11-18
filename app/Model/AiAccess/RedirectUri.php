<?php

declare(strict_types=1);

namespace App\Model\AiAccess;

/**
 * Pravidla adres pro návrat z přihlášení (OAuth redirect_uri).
 * https kdekoli, http jen na vlastním počítači (desktopové klienty), vlastní schéma aplikace (cursor://…);
 * nikdy javascript:, data:, file: ani fragment.
 */
final class RedirectUri
{
	private const array ForbiddenSchemes = ['javascript', 'data', 'file', 'vbscript', 'about', 'blob', 'ftp', 'ws', 'wss'];

	private const array LoopbackHosts = ['localhost', '127.0.0.1', '[::1]', '::1'];

	/**
	 * @throws OAuthException
	 */
	public static function validate(string $uri): string
	{
		$parts = parse_url($uri);
		$scheme = $parts === false ? '' : strtolower($parts['scheme'] ?? '');
		if ($parts === false || $scheme === '' || isset($parts['fragment']) || strlen($uri) > 2000) {
			throw new OAuthException('invalid_redirect_uri', "Invalid redirect URI: {$uri}");
		}

		if (in_array($scheme, self::ForbiddenSchemes, true) || preg_match('/^[a-z][a-z0-9+.-]*$/', $scheme) !== 1) {
			throw new OAuthException('invalid_redirect_uri', "Redirect URI scheme is not allowed: {$uri}");
		}

		if ($scheme === 'https' && ($parts['host'] ?? '') === '') {
			throw new OAuthException('invalid_redirect_uri', "Redirect URI must have a host: {$uri}");
		}

		if ($scheme === 'http' && !self::isLoopback($parts['host'] ?? '')) {
			throw new OAuthException('invalid_redirect_uri', "Plain http is allowed only for localhost: {$uri}");
		}

		return $uri;
	}

	/**
	 * Shoda s registrovanou adresou; u localhostu nezáleží na portu (RFC 8252, port volí klient při spuštění).
	 *
	 * @param list<string> $registered
	 */
	public static function matches(string $uri, array $registered): bool
	{
		if (in_array($uri, $registered, true)) {
			return true;
		}

		$parts = parse_url($uri);
		if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'http' || !self::isLoopback($parts['host'] ?? '')) {
			return false;
		}

		foreach ($registered as $candidate) {
			$other = parse_url($candidate);
			if ($other !== false
				&& strtolower($other['scheme'] ?? '') === 'http'
				&& ($other['host'] ?? null) === ($parts['host'] ?? null)
				&& ($other['path'] ?? '') === ($parts['path'] ?? '')
				&& ($other['query'] ?? '') === ($parts['query'] ?? '')) {
				return true;
			}
		}

		return false;
	}

	private static function isLoopback(string $host): bool
	{
		return in_array(strtolower($host), self::LoopbackHosts, true);
	}
}
