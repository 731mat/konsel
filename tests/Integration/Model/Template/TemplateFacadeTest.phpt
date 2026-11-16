<?php

declare(strict_types=1);

use App\Model\Meeting\AgendaFacade;
use App\Model\Meeting\AgendaItem;
use App\Model\Meeting\MeetingFacade;
use App\Model\Meeting\MeetingTransition;
use App\Model\Shared\Exception\InvalidStateException;
use App\Model\Template\TemplateFacade;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\MeetingScenario;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../../bootstrap.php';

test('defaults are installed once and rendered for a meeting', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = MeetingScenario::create($container);
	$facade = $container->getByType(TemplateFacade::class);
	$municipalityId = $scenario->municipality->getId();

	$installed = $facade->installDefaultResolutionTemplates($municipalityId);
	Assert::true($installed >= 15);
	Assert::same(0, $facade->installDefaultResolutionTemplates($municipalityId));
	Assert::same(3, $facade->installDefaultMeetingTemplates($municipalityId));

	$rendered = array_column($facade->renderResolutionTemplates($scenario->meeting->getId()), 'text', 'title');
	Assert::same('Zastupitelstvo obce Neplachovice schvaluje program 1. zasedání konaného dne 9. 12. 2026.', $rendered['Schválení programu']);
});

test('meeting template is applied to program and program can be saved as template', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = MeetingScenario::create($container);
	$facade = $container->getByType(TemplateFacade::class);
	$facade->installDefaultMeetingTemplates($scenario->municipality->getId());
	$templates = $facade->findMeetingTemplates($scenario->municipality->getId());
	$constituent = array_values(array_filter($templates, static fn($t) => str_contains($t->getName(), 's radou')))[0];

	$added = $facade->applyToMeeting($constituent->getId(), $scenario->meeting->getId());
	$program = $container->getByType(MeetingFacade::class)->get($scenario->meeting->getId())->getAgendaItems();
	Assert::same($added + 1, count($program));
	$titles = array_map(static fn(AgendaItem $i): string => $i->getTitle(), $program);
	Assert::contains('Složení slibu členů zastupitelstva', $titles);
	Assert::contains('Volba dalších členů rady', $titles);
	Assert::contains('Zastupitelstvo obce Neplachovice volí starostou obce', implode(PHP_EOL, array_map(static fn(AgendaItem $i): string => (string) $i->getProposedResolution(), $program)));

	$saved = $facade->saveProgramAsTemplate($scenario->meeting->getId(), 'Náš program');
	Assert::same(count($program), count($saved->getItems()));

	$meetings = $container->getByType(MeetingFacade::class);
	$meetings->transition($scenario->meeting->getId(), MeetingTransition::Share);
	$meetings->transition($scenario->meeting->getId(), MeetingTransition::Start);
	Assert::exception(fn() => $facade->applyToMeeting($saved->getId(), $scenario->meeting->getId()), InvalidStateException::class);
	Assert::count(count($program), $container->getByType(AgendaFacade::class)->getItem($scenario->item->getId())->getMeeting()->getAgendaItems());
});
