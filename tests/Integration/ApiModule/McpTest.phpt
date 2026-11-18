<?php

declare(strict_types=1);

use App\ApiModule\ApiResponses;
use App\Model\AiAccess\AccessScope;
use App\Model\AiAccess\AiAccessFacade;
use App\Model\Audit\AuditFacade;
use App\Model\Audit\AuditFilter;
use App\Model\Meeting\AgendaFacade;
use App\Model\Municipality\Module;
use App\Model\Municipality\MunicipalityFacade;
use App\Model\User\Role;
use Nette\Application\Response;
use Nette\Application\Responses\JsonResponse;
use Nette\DI\Container;
use Nette\Http\IRequest;
use Nette\Http\IResponse;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\Fixtures;
use Tests\Support\MeetingScenario;
use Tests\Support\PresenterTester;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../bootstrap.php';

/**
 * Hodnota z vnořeného pole odpovědi (null, když chybí).
 */
function dig(mixed $data, string|int ...$path): mixed
{
	foreach ($path as $key) {
		if (!is_array($data) || !array_key_exists($key, $data)) {
			return null;
		}

		$data = $data[$key];
	}

	return $data;
}

function digText(mixed $data, string|int ...$path): string
{
	$value = dig($data, ...$path);

	return is_scalar($value) ? (string) $value : (string) json_encode($value);
}

/**
 * @return array<mixed>
 */
function json(Response $response): array
{
	Assert::type(JsonResponse::class, $response);
	assert($response instanceof JsonResponse);
	$data = json_decode((string) json_encode($response->getPayload()), true);
	assert(is_array($data));

	return $data;
}

/**
 * @param array<string, mixed> $params
 * @return array<mixed> výsledek JSON-RPC
 */
function rpc(Container $container, ?string $token, string $method, array $params = []): array
{
	$response = json((new PresenterTester($container))->request('Api:Mcp', 'default', body: (string) json_encode([
		'jsonrpc' => '2.0',
		'id' => 1,
		'method' => $method,
		'params' => $params === [] ? new stdClass() : $params,
	]), headers: $token === null ? [] : [
		'authorization' => "Bearer {$token}",
	]));
	Assert::same(1, dig($response, 'id'), (string) json_encode($response));

	return $response;
}

/**
 * @param array<string, mixed> $arguments
 * @return array{error: bool, text: string, data: array<mixed>|null}
 */
function tool(Container $container, string $token, string $name, array $arguments = []): array
{
	$result = dig(rpc($container, $token, 'tools/call', [
		'name' => $name,
		'arguments' => $arguments === [] ? new stdClass() : $arguments,
	]), 'result');
	$text = digText($result, 'content', 0, 'text');
	$data = json_decode($text, true);

	return [
		'error' => dig($result, 'isError') === true,
		'text' => $text,
		'data' => is_array($data) ? $data : null,
	];
}

/**
 * @param array<mixed> $tools
 * @return list<string>
 */
function toolNames(array $tools): array
{
	$list = dig($tools, 'result', 'tools');

	return array_map(static fn(mixed $tool): string => digText($tool, 'name'), is_array($list) ? array_values($list) : []);
}

test('without token the server answers 401 with resource metadata', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$response = json((new PresenterTester($container))->request('Api:Mcp', 'default', body: '{"jsonrpc":"2.0","id":1,"method":"initialize"}'));
	Assert::same('unauthorized', dig($response, 'error'));
	$http = $container->getByType(IResponse::class);
	Assert::same(401, $http->getCode());
	$request = $container->getByType(IRequest::class);
	Assert::same('Bearer resource_metadata="http://konsel.test/.well-known/oauth-protected-resource/mcp"', ApiResponses::bearerChallenge($request, false));
	Assert::contains('error="invalid_token"', ApiResponses::bearerChallenge($request, true));

	Assert::same('unauthorized', dig(json((new PresenterTester($container))->request('Api:Mcp', 'default', body: '{}', headers: [
		'authorization' => 'Bearer ksl_neplatny-token-neplatny-token',
	])), 'error'));

	$metadata = json((new PresenterTester($container))->request('Api:OAuth', 'metadata', 'GET'));
	Assert::same('http://konsel.test', $metadata['issuer']);
	Assert::same('http://konsel.test/oauth/register', $metadata['registration_endpoint']);
	Assert::same(['S256'], $metadata['code_challenge_methods_supported']);
});

test('initialize, tools by role, scope and modules', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = MeetingScenario::create($container);
	$fixtures = new Fixtures($container);
	$access = $container->getByType(AiAccessFacade::class);
	$reader = $fixtures->user($scenario->municipality, 'ctenar@neplachovice.cz', Role::Reader, 'Petr Čtenář');
	$editor = $fixtures->user($scenario->municipality);
	$readerToken = $access->createPersonalToken($reader->getId(), 'Claude', AccessScope::Write, 30)->accessToken;
	$editorRead = $access->createPersonalToken($editor->getId(), 'Jen čtení', AccessScope::Read, 30)->accessToken;
	$editorWrite = $access->createPersonalToken($editor->getId(), 'Claude', AccessScope::Write, 30)->accessToken;

	$init = rpc($container, $editorWrite, 'initialize', [
		'protocolVersion' => '2025-06-18',
		'capabilities' => new stdClass(),
		'clientInfo' => [
			'name' => 'test',
			'version' => '1',
		],
	]);
	Assert::same('2025-06-18', dig($init, 'result', 'protocolVersion'));
	Assert::same('konsel', dig($init, 'result', 'serverInfo', 'name'));
	Assert::contains('Neplachovice', digText($init, 'result', 'instructions'));
	Assert::contains('Jana Nováková', digText($init, 'result', 'instructions'));
	Assert::same('2025-11-25', dig(rpc($container, $editorWrite, 'initialize', [
		'protocolVersion' => '1999-01-01',
	]), 'result', 'protocolVersion'), 'neznámá verze → nejnovější');
	Assert::same(-32601, dig(rpc($container, $editorWrite, 'resources/read'), 'error', 'code'));
	Assert::same([], dig(rpc($container, $editorWrite, 'ping'), 'result'));

	$writeTools = ['create_agenda_item', 'update_agenda_item', 'create_meeting'];
	$editorTools = toolNames(rpc($container, $editorWrite, 'tools/list'));
	Assert::contains('get_meeting', $editorTools);
	Assert::contains('list_projects', $editorTools);
	foreach ($writeTools as $name) {
		Assert::contains($name, $editorTools);
		Assert::notContains($name, toolNames(rpc($container, $editorRead, 'tools/list')), 'připojení jen ke čtení');
		Assert::notContains($name, toolNames(rpc($container, $readerToken, 'tools/list')), 'čtenář nemá právo upravovat');
	}

	Assert::contains('add_comment', toolNames(rpc($container, $readerToken, 'tools/list')), 'připomínku smí každý');
	$denied = tool($container, $editorRead, 'create_agenda_item', [
		'meeting_id' => $scenario->meeting->getId(),
		'title' => 'Nový bod',
	]);
	Assert::true($denied['error']);
	Assert::contains('jen oprávnění ke čtení', $denied['text']);

	$container->getByType(MunicipalityFacade::class)->changeModules($scenario->municipality->getId(), [Module::ResolutionTasks, Module::AiConnections]);
	Assert::notContains('list_projects', toolNames(rpc($container, $editorWrite, 'tools/list')), 'vypnuté plány');

	// notifikace i dávka
	$tester = new PresenterTester($container);
	$tester->request('Api:Mcp', 'default', body: '{"jsonrpc":"2.0","method":"notifications/initialized"}', headers: [
		'authorization' => "Bearer {$editorWrite}",
	]);
	Assert::same(202, $container->getByType(IResponse::class)->getCode());
	$batch = json($tester->request('Api:Mcp', 'default', body: '[{"jsonrpc":"2.0","id":1,"method":"ping"},{"jsonrpc":"2.0","method":"notifications/initialized"},{"jsonrpc":"2.0","id":2,"method":"nope"}]', headers: [
		'authorization' => "Bearer {$editorWrite}",
	]));
	Assert::count(2, $batch);
	Assert::same(-32601, dig($batch, 1, 'error', 'code'));
	Assert::same(-32700, dig(json($tester->request('Api:Mcp', 'default', body: '{nejson', headers: [
		'authorization' => "Bearer {$editorWrite}",
	])), 'error', 'code'));
});

test('read tools return meeting, item, overview and search results; other municipality is invisible', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = MeetingScenario::create($container);
	$fixtures = new Fixtures($container);
	$editor = $fixtures->user($scenario->municipality);
	$token = $container->getByType(AiAccessFacade::class)->createPersonalToken($editor->getId(), 'Claude', AccessScope::Read, 30)->accessToken;

	$overview = tool($container, $token, 'get_overview');
	Assert::false($overview['error'], $overview['text']);
	Assert::same('Neplachovice', dig($overview, 'data', 'municipality'));
	Assert::same('Zastupitelstvo obce Neplachovice', dig($overview, 'data', 'bodies', 0, 'name'));
	Assert::same($scenario->meeting->getId(), dig($overview, 'data', 'upcoming_meetings', 0, 'id'));

	$meeting = tool($container, $token, 'get_meeting', [
		'meeting_id' => $scenario->meeting->getId(),
	]);
	Assert::false($meeting['error'], $meeting['text']);
	Assert::same('Rozpočet na rok 2027', dig($meeting, 'data', 'agenda', 0, 'title'));
	Assert::true(dig($meeting, 'data', 'program_editable'));

	$item = tool($container, $token, 'get_agenda_item', [
		'item_id' => (string) $scenario->item->getId(),
	]);
	Assert::false($item['error'], $item['text']);
	Assert::same('1', dig($item, 'data', 'number'));

	$search = tool($container, $token, 'search', [
		'query' => 'rozpocet',
	]);
	Assert::false($search['error'], $search['text']);
	Assert::true((dig($search, 'data', 'total')) >= 1);
	Assert::contains('get_agenda_item', (string) json_encode(dig($search, 'data', 'hits')));

	Assert::false(tool($container, $token, 'list_meetings')['error']);
	Assert::false(tool($container, $token, 'list_people')['error']);
	Assert::false(tool($container, $token, 'list_resolutions')['error']);
	Assert::false(tool($container, $token, 'list_projects')['error']);
	$document = tool($container, $token, 'get_document', [
		'meeting_id' => $scenario->meeting->getId(),
		'type' => 'invitation',
	]);
	Assert::false($document['error'], $document['text']);
	Assert::contains('Rozpočet na rok 2027', digText($document, 'data', 'text'));
	Assert::notContains('<', digText($document, 'data', 'text'));

	Assert::contains('celé číslo', tool($container, $token, 'get_meeting', [
		'meeting_id' => 'abc',
	])['text']);

	$other = MeetingScenario::create($container, $fixtures->municipality('Slavkov'));
	$foreign = tool($container, $token, 'get_meeting', [
		'meeting_id' => $other->meeting->getId(),
	]);
	Assert::true($foreign['error']);
	Assert::notContains('Slavkov', $foreign['text']);
});

test('write tools change data through facades and audit records the AI', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = MeetingScenario::create($container);
	$fixtures = new Fixtures($container);
	$editor = $fixtures->user($scenario->municipality);
	$token = $container->getByType(AiAccessFacade::class)->createPersonalToken($editor->getId(), 'Claude', AccessScope::Write, 30)->accessToken;

	$created = tool($container, $token, 'create_agenda_item', [
		'meeting_id' => $scenario->meeting->getId(),
		'title' => 'Rozpočtové opatření č. 5/2026',
		'description' => "Důvod opatření.\n\nDruhý odstavec.",
		'proposed_resolution' => 'Zastupitelstvo obce Neplachovice schvaluje rozpočtové opatření č. 5/2026.',
		'parent_id' => $scenario->item->getId(),
	]);
	Assert::false($created['error'], $created['text']);
	Assert::same('1.1', dig($created, 'data', 'created', 'number'));
	$itemId = dig($created, 'data', 'created', 'id');
	assert(is_int($itemId));

	$updated = tool($container, $token, 'update_agenda_item', [
		'item_id' => $itemId,
		'proposed_resolution' => 'Zastupitelstvo obce Neplachovice schvaluje rozpočtové opatření č. 5/2026 dle přílohy.',
	]);
	Assert::false($updated['error'], $updated['text']);
	$item = $container->getByType(AgendaFacade::class)->getItem($itemId);
	Assert::same('Rozpočtové opatření č. 5/2026', $item->getTitle(), 'nezadaná pole zůstanou');
	Assert::same("Důvod opatření.\n\nDruhý odstavec.", $item->getDescription());
	Assert::contains('dle přílohy', (string) $item->getProposedResolution());

	$comment = tool($container, $token, 'add_comment', [
		'item_id' => $itemId,
		'text' => "Navrhuji doplnit <b>tabulku</b>.\n\nDíky.",
	]);
	Assert::false($comment['error'], $comment['text']);
	Assert::contains('Navrhuji doplnit <b>tabulku</b>.', digText($comment, 'data', 'created', 'text'), 'HTML od AI se escapuje');

	$project = tool($container, $token, 'create_project', [
		'title' => 'Nová školka',
		'estimated_cost' => 25000000,
	]);
	Assert::false($project['error'], $project['text']);
	$status = tool($container, $token, 'change_project_status', [
		'project_id' => dig($project, 'data', 'created', 'id'),
		'status' => 'preparation',
		'note' => 'Zadána studie.',
	]);
	Assert::same(App\Model\Project\ProjectStatus::Preparation->label(), dig($status, 'data', 'updated', 'status'));

	$meeting = tool($container, $token, 'create_meeting', [
		'body_id' => $scenario->meeting->getBody()->getId(),
		'scheduled_at' => '2027-02-17 18:00',
		'insert_standard_items' => true,
	]);
	Assert::false($meeting['error'], $meeting['text']);
	Assert::same('Obecní dům', dig($meeting, 'data', 'created', 'place'), 'místo z posledního zasedání');
	Assert::true((dig($meeting, 'data', 'standard_items_added')) > 0);

	[$entries] = $container->getByType(AuditFacade::class)->find($scenario->municipality->getId(), new AuditFilter());
	$actors = array_unique(array_map(static fn(App\Model\Audit\AuditEntry $entry): string => $entry->getActorName(), $entries));
	Assert::contains('Jana Nováková (AI: Claude)', $actors);
});
