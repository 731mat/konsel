<?php

declare(strict_types=1);

use App\Model\AiAccess\AccessScope;
use App\Model\AiAccess\OAuthException;
use App\Model\AiAccess\Pkce;
use App\Model\AiAccess\RedirectUri;
use Tester\Assert;

require __DIR__ . '/../../../bootstrap.php';

test('redirect URI: https anywhere, http only on loopback, app schemes, no dangerous schemes', function (): void {
	foreach (['https://claude.ai/api/mcp/auth_callback', 'http://localhost:33418/callback', 'http://127.0.0.1:6274/oauth/callback', 'cursor://anysphere.cursor-retrieval/oauth/callback'] as $uri) {
		Assert::same($uri, RedirectUri::validate($uri));
	}

	foreach (['javascript:alert(1)', 'data:text/html,x', 'http://example.com/cb', 'https://claude.ai/cb#x', 'file:///etc/passwd', 'https:///nohost', 'relative/path'] as $uri) {
		Assert::exception(fn() => RedirectUri::validate($uri), OAuthException::class);
	}
});

test('redirect URI matching: exact, loopback port may differ', function (): void {
	$registered = ['https://claude.ai/api/mcp/auth_callback', 'http://127.0.0.1:1234/callback'];
	Assert::true(RedirectUri::matches('https://claude.ai/api/mcp/auth_callback', $registered));
	Assert::true(RedirectUri::matches('http://127.0.0.1:5555/callback', $registered));
	Assert::false(RedirectUri::matches('http://127.0.0.1:5555/other', $registered));
	Assert::false(RedirectUri::matches('https://claude.ai/api/mcp/auth_callback?x=1', $registered));
	Assert::false(RedirectUri::matches('https://evil.example/api/mcp/auth_callback', $registered));
});

test('PKCE S256 per RFC 7636 example', function (): void {
	$verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
	$challenge = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
	Assert::true(Pkce::isValidChallenge($challenge));
	Assert::true(Pkce::verify($verifier, $challenge));
	Assert::false(Pkce::verify($verifier . 'x', $challenge));
	Assert::false(Pkce::verify('short', $challenge));
	Assert::false(Pkce::isValidChallenge('plain'));
});

test('requested scope: changes only when explicitly asked', function (): void {
	Assert::same(AccessScope::Read, AccessScope::fromOAuthScope(null));
	Assert::same(AccessScope::Read, AccessScope::fromOAuthScope('read'));
	Assert::same(AccessScope::Write, AccessScope::fromOAuthScope('read write'));
	Assert::same(AccessScope::Write, AccessScope::fromOAuthScope('konsel:write'));
	Assert::same('read write', AccessScope::Write->oauthScope());
});
