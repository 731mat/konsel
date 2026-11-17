<?php

declare(strict_types=1);

use App\Model\Ai\AgendaDraft;
use App\Model\Ai\AgendaWritingFacade;
use App\Model\Ai\AiClient;
use App\Model\Ai\AiFacade;
use App\Model\Ai\AiPurpose;
use App\Model\Ai\Command\SaveAiSettings;
use App\Model\Shared\Exception\InvalidStateException;
use App\Model\Shared\Exception\ValidationException;
use App\Model\User\Role;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\FakeAiClient;
use Tests\Support\Fixtures;
use Tests\Support\PresenterTester;
use Tests\Support\ResolutionScenario;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../../bootstrap.php';

/**
 * @return array{AgendaWritingFacade, FakeAiClient, ResolutionScenario, Nette\DI\Container}
 */
function bootWriting(bool $enableAi = true): array
{
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = ResolutionScenario::create($container, ['Schvaluje: rozpočtové opatření č. 9/2026 v předloženém znění']);
	if ($enableAi) {
		$ai = $container->getByType(AiFacade::class);
		$ai->saveSettings($scenario->meeting->municipality->getId(), new SaveAiSettings('gpt-5-mini', true, 'sk-proj-TESTKEY1234567890abcdefXYZ9'));
		$ai->saveWritingGuide($scenario->meeting->municipality->getId(), 'Usnesení začínej slovesem s dvojtečkou. Nepiš „Zastupitelstvo obce … schvaluje“.', 'Bere na vědomí: zápis kontrolního výboru', true);
	}

	$client = $container->getByType(AiClient::class);
	assert($client instanceof FakeAiClient);

	return [$container->getByType(AgendaWritingFacade::class), $client, $scenario, $container];
}

test('proposal follows the municipality guide and examples; usage is logged', function (): void {
	[$writing, $client, $scenario, $container] = bootWriting();
	$meetingId = $scenario->meeting->meeting->getId();
	$client->reply = "```\nNávrh usnesení:\nSchvaluje: **příkazní smlouvu** s RRAVM\n```";

	$text = $writing->suggest($meetingId, AiPurpose::ProposedResolution, new AgendaDraft('Příkazní smlouva s RRAVM', 'Poradenství k žádosti o dotaci.', null, $scenario->meeting->item->getId()), null);
	Assert::same('Schvaluje: příkazní smlouvu s RRAVM', $text, 'bez Markdownu a úvodního popisku');

	[$system, $user] = $client->lastMessages;
	Assert::contains('Nevymýšlej fakta', $system->content);
	Assert::contains('Obec: Obec Neplachovice. Orgán: Zastupitelstvo obce Neplachovice.', $system->content);
	Assert::contains('Pokyny obce (dodrž je přednostně):', $system->content);
	Assert::contains('Bere na vědomí: zápis kontrolního výboru', $system->content, 'vlastní vzory');
	Assert::contains('Schvaluje: rozpočtové opatření č. 9/2026', $system->content, 'poslední usnesení obce jako vzor');
	Assert::contains('Název bodu: Příkazní smlouva s RRAVM', $user->content);
	Assert::contains('Bod je podbodem bodu: Rozpočet na rok 2027', $user->content);
	Assert::contains('Navrhni návrh usnesení.', $user->content);
	Assert::notContains('Člen Dvořák', $system->content . $user->content, 'osobní údaje členů se neposílají');

	Assert::same(1, $container->getByType(AiFacade::class)->monthlyUsage($scenario->meeting->municipality->getId())['count']);
	Assert::same(17, $container->getByType(AiFacade::class)->monthlyUsage($scenario->meeting->municipality->getId())['tokens']);
});

test('description uses only own examples; missing title and disabled AI are refused', function (): void {
	[$writing, $client, $scenario] = bootWriting();
	$meetingId = $scenario->meeting->meeting->getId();
	$writing->suggest($meetingId, AiPurpose::AgendaDescription, new AgendaDraft('Oprava chodníku', 'Chodník je rozbitý.'), null);
	[$system, $user] = $client->lastMessages;
	Assert::contains('důvodovou zprávu', $system->content);
	Assert::notContains('rozpočtové opatření č. 9/2026', $system->content, 'usnesení se jako vzor důvodové zprávy neposílají');
	Assert::contains("Současný text důvodové zprávy:\nChodník je rozbitý.", $user->content);

	Assert::exception(fn() => $writing->suggest($meetingId, AiPurpose::AgendaDescription, new AgendaDraft('  '), null), ValidationException::class, 'Nejdřív napište název bodu – AI z něj vychází.');

	[$disabled, , $other] = bootWriting(enableAi: false);
	Assert::exception(fn() => $disabled->suggest($other->meeting->meeting->getId(), AiPurpose::AgendaDescription, new AgendaDraft('Bod'), null), InvalidStateException::class);
});

test('agenda form offers the AI button and the signal returns JSON', function (): void {
	[, , $scenario, $container] = bootWriting();
	$tester = new PresenterTester($container);
	$tester->loginAs((new Fixtures($container))->user($scenario->meeting->municipality, role: Role::Editor));
	$planned = $scenario->meeting->another($container)->meeting->getId();

	$html = $tester->render('Admin:Agenda', 'add', [
		'meeting' => $planned,
	]);
	Assert::contains('data-ai-suggest="/admin/agenda/add?meeting=', $html);
	Assert::contains('purpose=proposed-resolution', $html);

	$response = $tester->run('Admin:Agenda', 'add', [
		'meeting' => $planned,
		'do' => 'suggest',
		'purpose' => 'proposed-resolution',
	], post: [
		'title' => 'Oprava chodníku',
	]);
	Assert::type(Nette\Application\Responses\JsonResponse::class, $response);
	assert($response instanceof Nette\Application\Responses\JsonResponse);
	Assert::same([
		'text' => 'Návrh textu od AI.',
	], (array) $response->getPayload());
});
