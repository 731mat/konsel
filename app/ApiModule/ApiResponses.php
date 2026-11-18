<?php

declare(strict_types=1);

namespace App\ApiModule;

use Nette\Http\IRequest;
use Nette\Http\IResponse;

/**
 * Společné hlavičky API pro AI klienty: CORS (přihlášení nese token, ne cookie, takže stačí „*“) a adresy serveru.
 */
final class ApiResponses
{
	public static function cors(IResponse $response): void
	{
		$response->setHeader('Access-Control-Allow-Origin', '*');
		$response->setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
		$response->setHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, Accept, Mcp-Session-Id, MCP-Protocol-Version, Last-Event-ID');
		$response->setHeader('Access-Control-Expose-Headers', 'WWW-Authenticate, Mcp-Session-Id');
		$response->setHeader('Access-Control-Max-Age', '86400');
	}

	/**
	 * Adresa aplikace bez lomítka na konci (vydavatel OAuth). Za proxy správně https díky KONSEL_TRUSTED_PROXIES.
	 */
	public static function baseUrl(IRequest $request): string
	{
		return rtrim($request->getUrl()->getBaseUrl(), '/');
	}

	public static function mcpUrl(IRequest $request): string
	{
		return self::baseUrl($request) . '/mcp';
	}

	public static function resourceMetadataUrl(IRequest $request): string
	{
		return self::baseUrl($request) . '/.well-known/oauth-protected-resource/mcp';
	}

	/**
	 * Hlavička WWW-Authenticate pro 401 – klient z ní zjistí, kde se přihlásit (RFC 9728).
	 */
	public static function bearerChallenge(IRequest $request, bool $invalidToken): string
	{
		return sprintf('Bearer resource_metadata="%s"%s', self::resourceMetadataUrl($request), $invalidToken ? ', error="invalid_token"' : '');
	}
}
