<?php

declare(strict_types=1);

use App\Model\AiAccess\AccessScope;
use App\Model\AiAccess\AiAccessFacade;
use App\Model\AiAccess\OAuthFacade;
use App\Model\Municipality\Module;
use App\Model\Municipality\MunicipalityFacade;
use App\Model\User\Role;
use Nette\Application\BadRequestException;
use Nette\Application\Responses\JsonResponse;
use Nette\Application\Responses\TextResponse;
use Nette\Bridges\ApplicationLatte\Template;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\Fixtures;
use Tests\Support\PresenterTester;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../bootstrap.php';

const Verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
const Challenge = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

test('user sees MCP address, creates personal token once and revokes it', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$fixtures = new Fixtures($container);
	$municipality = $fixtures->municipality();
	$editor = $fixtures->user($municipality);
	$tester = new PresenterTester($container);
	$tester->loginAs($editor);

	$html = $tester->render('Admin:AiConnection');
	Assert::contains('http://konsel.test/mcp', $html);
	Assert::contains('Zatím nemáte připojeného žádného AI asistenta', $html);
	Assert::notContains('Připojení ostatních uživatelů obce', $html, 'jen správce');

	$response = $tester->submitForm('Admin:AiConnection', 'tokenForm', [
		'name' => 'Claude Code',
		'scope' => 'write',
		'validDays' => '90',
	]);
	Assert::type(TextResponse::class, $response);
	assert($response instanceof TextResponse);
	$source = $response->getSource();
	$html = $source instanceof Template ? $source->renderToString() : '';
	Assert::match('%A%value="ksl_%a%"%A%', $html);
	Assert::contains('čtení i změny', $html);

	$access = $container->getByType(AiAccessFacade::class);
	[$token] = $access->findForUser($editor->getId());
	Assert::same('Claude Code', $token->getName());
	PresenterTester::assertRedirect($tester->run('Admin:AiConnection', 'default', [
		'do' => 'revoke',
		'tokenId' => $token->getId(),
	], post: [
		'_' => '1',
	]));
	Assert::same([], $access->findForUser($editor->getId()));
});

test('admin sees connections of other users; editor cannot revoke them', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$fixtures = new Fixtures($container);
	$municipality = $fixtures->municipality();
	$admin = $fixtures->user($municipality, 'spravce@neplachovice.cz', Role::Admin, 'Marie Správcová');
	$editor = $fixtures->user($municipality);
	$colleague = $fixtures->user($municipality, 'karel@neplachovice.cz', Role::Editor, 'Karel Kolega');
	$access = $container->getByType(AiAccessFacade::class);
	$token = $access->createPersonalToken($colleague->getId(), 'ChatGPT', AccessScope::Read, 30)->token;

	$tester = new PresenterTester($container);
	$tester->loginAs($admin);
	$html = $tester->render('Admin:AiConnection');
	Assert::contains('Připojení ostatních uživatelů obce', $html);
	Assert::contains('Karel Kolega', $html);

	$tester->loginAs($editor);
	Assert::exception(fn() => $tester->run('Admin:AiConnection', 'default', [
		'do' => 'revoke',
		'tokenId' => $token->getId(),
	], post: [
		'_' => '1',
	]), BadRequestException::class);
	Assert::count(1, $access->findForUser($colleague->getId()));
});

test('consent page: approve returns code to the app, deny returns access_denied, bad client shows error', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$fixtures = new Fixtures($container);
	$municipality = $fixtures->municipality();
	$editor = $fixtures->user($municipality);
	$oauth = $container->getByType(OAuthFacade::class);
	$client = $oauth->registerClient('Claude', ['https://claude.ai/api/mcp/auth_callback'], 'none')->client;
	$params = [
		'response_type' => 'code',
		'client_id' => $client->getClientId(),
		'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
		'state' => 'xyz',
		'code_challenge' => Challenge,
		'code_challenge_method' => 'S256',
		'scope' => 'read write',
		'resource' => 'http://konsel.test/mcp',
	];
	$tester = new PresenterTester($container);
	$tester->loginAs($editor);

	$html = $tester->render('Admin:AiConnection', 'authorize', $params);
	Assert::contains('Claude', $html);
	Assert::contains('claude.ai', $html);
	Assert::match('%A%name="write"%A%checked%A%', $html);

	$redirect = PresenterTester::assertRedirect($tester->submitForm('Admin:AiConnection', 'consentForm', [
		'write' => 'on',
		'allow' => 'Povolit připojení',
	], 'authorize', params: $params));
	$url = $redirect->getUrl();
	Assert::match('https://claude.ai/api/mcp/auth_callback?code=%a%&state=xyz&iss=http%3A%2F%2Fkonsel.test', $url);
	parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
	$code = $query['code'] ?? '';
	assert(is_string($code));

	$token = $tester->request('Api:OAuth', 'token', post: [
		'grant_type' => 'authorization_code',
		'client_id' => $client->getClientId(),
		'code' => $code,
		'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
		'code_verifier' => Verifier,
	]);
	Assert::type(JsonResponse::class, $token);
	assert($token instanceof JsonResponse);
	$payload = (array) $token->getPayload();
	Assert::same('Bearer', $payload['token_type']);
	Assert::same('read write', $payload['scope']);
	Assert::true(is_string($payload['access_token']) && strncmp($payload['access_token'], 'ksla_', 5) === 0);

	$denied = PresenterTester::assertRedirect($tester->submitForm('Admin:AiConnection', 'consentForm', [
		'deny' => 'Zamítnout',
	], 'authorize', params: $params));
	Assert::same('https://claude.ai/api/mcp/auth_callback?error=access_denied&state=xyz&iss=http%3A%2F%2Fkonsel.test', $denied->getUrl());

	Assert::contains('neznámé client_id', $tester->render('Admin:AiConnection', 'authorize', [
		'client_id' => 'neexistuje',
	] + $params));
	Assert::contains('Návratová adresa', $tester->render('Admin:AiConnection', 'authorize', [
		'redirect_uri' => 'https://evil.example/cb',
	] + $params));
	Assert::match('https://claude.ai/api/mcp/auth_callback?error=invalid_request%A%', PresenterTester::assertRedirect($tester->run('Admin:AiConnection', 'authorize', [
		'code_challenge_method' => 'plain',
	] + $params))->getUrl());

	$container->getByType(MunicipalityFacade::class)->changeModules($municipality->getId(), [Module::Plans]);
	Assert::contains('vypnuté', $tester->render('Admin:AiConnection', 'authorize', $params));
	Assert::exception(fn() => $tester->render('Admin:AiConnection'), BadRequestException::class);
});

test('dynamic client registration endpoint', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$tester = new PresenterTester($container);

	$response = $tester->request('Api:OAuth', 'register', body: (string) json_encode([
		'client_name' => 'Claude',
		'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
		'grant_types' => ['authorization_code', 'refresh_token'],
		'response_types' => ['code'],
		'token_endpoint_auth_method' => 'none',
	]));
	Assert::type(JsonResponse::class, $response);
	assert($response instanceof JsonResponse);
	$client = (array) $response->getPayload();
	Assert::same('Claude', $client['client_name']);
	Assert::same('none', $client['token_endpoint_auth_method']);
	Assert::false(isset($client['client_secret']));

	$confidential = $tester->request('Api:OAuth', 'register', body: (string) json_encode([
		'redirect_uris' => ['https://chatgpt.com/connector_platform_oauth_redirect'],
	]));
	assert($confidential instanceof JsonResponse);
	$payload = (array) $confidential->getPayload();
	Assert::same('client_secret_basic', $payload['token_endpoint_auth_method'], 'výchozí podle RFC 7591');
	Assert::true(is_string($payload['client_secret'] ?? null));

	$invalid = $tester->request('Api:OAuth', 'register', body: '{"redirect_uris":["javascript:alert(1)"]}');
	assert($invalid instanceof JsonResponse);
	Assert::same('invalid_redirect_uri', ((array) $invalid->getPayload())['error']);
});
