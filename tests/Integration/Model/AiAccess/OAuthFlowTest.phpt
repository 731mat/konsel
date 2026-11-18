<?php

declare(strict_types=1);

use App\Model\AiAccess\AccessScope;
use App\Model\AiAccess\AiAccessFacade;
use App\Model\AiAccess\OAuthException;
use App\Model\AiAccess\OAuthFacade;
use App\Model\Municipality\Module;
use App\Model\Municipality\MunicipalityFacade;
use App\Model\Shared\Exception\AccessDeniedException;
use App\Model\Shared\Exception\ValidationException;
use App\Model\User\Role;
use App\Model\User\UserFacade;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\Fixtures;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../../bootstrap.php';

const Verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
const Challenge = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
const Callback = 'https://claude.ai/api/mcp/auth_callback';

/**
 * @param callable(): mixed $operation
 */
function oauthError(callable $operation): string
{
	try {
		$operation();
	} catch (OAuthException $e) {
		return $e->error;
	}

	Assert::fail('OAuthException expected');

	return '';
}

test('authorization code flow with PKCE, refresh rotation and revocation', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$fixtures = new Fixtures($container);
	$user = $fixtures->user($fixtures->municipality(), 'spravce@neplachovice.cz', Role::Admin);
	$oauth = $container->getByType(OAuthFacade::class);
	$access = $container->getByType(AiAccessFacade::class);
	$clock = $container->getByType(ClockInterface::class);
	assert($clock instanceof MockClock);

	$client = $oauth->registerClient('Claude', [Callback], 'none')->client;
	$code = $oauth->authorize($user->getId(), $client->getClientId(), Callback, Challenge, AccessScope::Write);

	Assert::same('invalid_grant', oauthError(fn() => $oauth->exchangeCode($client->getClientId(), null, $code, Callback, Verifier . 'x')));
	Assert::same('invalid_grant', oauthError(fn() => $oauth->exchangeCode($client->getClientId(), null, $code, 'https://claude.ai/other', Verifier)));
	Assert::same('invalid_client', oauthError(fn() => $oauth->exchangeCode('unknown', null, $code, Callback, Verifier)));

	$issued = $oauth->exchangeCode($client->getClientId(), null, $code, Callback, Verifier);
	Assert::same(AccessScope::Write, $issued->token->getScope());
	Assert::same('Claude', $issued->token->getName());
	Assert::same($user->getId(), $access->authenticate($issued->accessToken)->getUser()->getId());
	Assert::same('invalid_grant', oauthError(fn() => $oauth->exchangeCode($client->getClientId(), null, $code, Callback, Verifier)), 'kód jen jednou');

	// přístupový token vyprší, obnovovací ho vymění a sám přestane platit
	$clock->modify('+2 hours');
	Assert::exception(fn() => $access->authenticate($issued->accessToken), AccessDeniedException::class);
	$refreshed = $oauth->refresh($client->getClientId(), null, (string) $issued->refreshToken);
	Assert::same($issued->token->getId(), $refreshed->token->getId(), 'stále jedno připojení');
	Assert::same($user->getId(), $access->authenticate($refreshed->accessToken)->getUser()->getId());
	Assert::same('invalid_grant', oauthError(fn() => $oauth->refresh($client->getClientId(), null, (string) $issued->refreshToken)));
	Assert::count(1, $access->findForUser($user->getId()));

	$oauth->revokeToken((string) $refreshed->refreshToken);
	Assert::exception(fn() => $access->authenticate($refreshed->accessToken), AccessDeniedException::class);
	Assert::same([], $access->findForUser($user->getId()));
});

test('confidential client must authenticate, redirect URI and PKCE are enforced', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$fixtures = new Fixtures($container);
	$user = $fixtures->user($fixtures->municipality());
	$oauth = $container->getByType(OAuthFacade::class);

	$registered = $oauth->registerClient('ChatGPT', ['https://chatgpt.com/connector_platform_oauth_redirect'], 'client_secret_post');
	Assert::notNull($registered->secret);
	$clientId = $registered->client->getClientId();
	Assert::same('invalid_request', oauthError(fn() => $oauth->authorize($user->getId(), $clientId, 'https://evil.example/cb', Challenge, AccessScope::Read)));
	Assert::same('invalid_request', oauthError(fn() => $oauth->authorize($user->getId(), $clientId, 'https://chatgpt.com/connector_platform_oauth_redirect', 'plain', AccessScope::Read)));
	$code = $oauth->authorize($user->getId(), $clientId, 'https://chatgpt.com/connector_platform_oauth_redirect', Challenge, AccessScope::Read);
	Assert::same('invalid_client', oauthError(fn() => $oauth->exchangeCode($clientId, 'wrong', $code, 'https://chatgpt.com/connector_platform_oauth_redirect', Verifier)));
	$issued = $oauth->exchangeCode($clientId, $registered->secret, $code, 'https://chatgpt.com/connector_platform_oauth_redirect', Verifier);
	Assert::same(AccessScope::Read, $issued->token->getScope());
	Assert::same('read', $issued->token->getScope()->oauthScope());

	Assert::same('invalid_redirect_uri', oauthError(fn() => $oauth->registerClient('Zlý', ['javascript:alert(1)'])));
	Assert::same('invalid_redirect_uri', oauthError(fn() => $oauth->registerClient('Bez adresy', [])));
});

test('personal tokens: validity, deactivated user, module switched off, superadmin', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$fixtures = new Fixtures($container);
	$municipality = $fixtures->municipality();
	$admin = $fixtures->user($municipality, 'spravce@neplachovice.cz', Role::Admin);
	$editor = $fixtures->user($municipality);
	$access = $container->getByType(AiAccessFacade::class);

	$issued = $access->createPersonalToken($editor->getId(), 'Claude Code', AccessScope::Read, 30);
	Assert::match('ksl_%a%', $issued->accessToken);
	Assert::same(substr($issued->accessToken, 0, 8), $issued->token->getHint());
	Assert::same($editor->getId(), $access->authenticate($issued->accessToken)->getUser()->getId());
	Assert::exception(fn() => $access->createPersonalToken($editor->getId(), 'Navždy', AccessScope::Read, 1000), ValidationException::class);
	Assert::exception(fn() => $access->createPersonalToken($editor->getId(), ' ', AccessScope::Read, 30), ValidationException::class);
	Assert::exception(fn() => $access->authenticate('ksl_neexistuje-neexistuje-neexistuje'), AccessDeniedException::class);
	Assert::count(1, $access->findForMunicipality($municipality->getId()));

	$users = $container->getByType(UserFacade::class);
	$users->deactivate($editor->getId(), $admin->getId());
	Assert::exception(fn() => $access->authenticate($issued->accessToken), AccessDeniedException::class, 'Účet uživatele je deaktivovaný.');
	$users->activate($editor->getId());

	$container->getByType(MunicipalityFacade::class)->changeModules($municipality->getId(), [Module::ResolutionTasks, Module::Plans]);
	Assert::exception(fn() => $access->authenticate($issued->accessToken), AccessDeniedException::class);
	Assert::notNull($access->accountProblem($editor->getId()));

	$superadmin = $users->create(new App\Model\User\Command\CreateUser('root@konsel.test', 'Správce systému', Role::SuperAdmin, Fixtures::Password, null));
	Assert::exception(fn() => $access->createPersonalToken($superadmin->getId(), 'Root', AccessScope::Read, 30), ValidationException::class);
});

test('cleanup removes revoked connections and unused client registrations after 30 days', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$fixtures = new Fixtures($container);
	$user = $fixtures->user($fixtures->municipality());
	$access = $container->getByType(AiAccessFacade::class);
	$oauth = $container->getByType(OAuthFacade::class);
	$clock = $container->getByType(ClockInterface::class);
	assert($clock instanceof MockClock);

	$revoked = $access->createPersonalToken($user->getId(), 'Starý', AccessScope::Read, 30);
	$access->revoke($revoked->token->getId());
	$kept = $access->createPersonalToken($user->getId(), 'Nový', AccessScope::Read, 365);
	$oauth->registerClient('Nikdy nepřipojený', [Callback], 'none');
	Assert::same(0, $access->cleanup());

	$clock->modify('+31 days');
	Assert::same(2, $access->cleanup(), 'odvolaný token a nepoužitá registrace');
	Assert::same($user->getId(), $access->authenticate($kept->accessToken)->getUser()->getId());
});
