<?php

declare(strict_types=1);

use App\Model\Meeting\MeetingFacade;
use App\Model\Meeting\MeetingTransition;
use App\Model\Meeting\ProceedingsFacade;
use App\Model\Meeting\VoteChoice;
use App\Model\Meeting\VotingFacade;
use App\Model\Meeting\VotingType;
use App\Model\People\Command\AddMember;
use App\Model\People\Command\SaveBody;
use App\Model\People\Command\SavePerson;
use App\Model\People\MemberFunction;
use App\Model\People\NumberingScope;
use App\Model\People\PeopleFacade;
use App\Model\Resolution\ResolutionFacade;
use App\Model\Shared\Exception\InvalidStateException;
use Nette\DI\Container;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\MeetingScenario;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../../bootstrap.php';

/**
 * Zasedání s 5 přítomnými členy, zahájené – připravené k hlasování.
 */
function startedMeeting(Container $container, MeetingScenario $scenario): void
{
	$meetings = $container->getByType(MeetingFacade::class);
	$meetings->transition($scenario->meeting->getId(), MeetingTransition::Share);
	$container->getByType(ProceedingsFacade::class)->markAllPresent($scenario->meeting->getId());
	$meetings->transition($scenario->meeting->getId(), MeetingTransition::Start);
}

/**
 * @return array{Container, MeetingScenario, VotingFacade, list<int>}
 */
function bootNumbering(): array
{
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = MeetingScenario::create($container);
	$people = $container->getByType(PeopleFacade::class);
	$ids = [];
	foreach (['Dvořák', 'Svobodová', 'Novotný', 'Černá', 'Veselý'] as $lastName) {
		$person = $people->createPerson($scenario->municipality->getId(), new SavePerson('Člen', $lastName));
		$people->addMember($scenario->meeting->getBody()->getId(), new AddMember($person->getId(), MemberFunction::Member, new DateTimeImmutable('2026-10-21')));
		$ids[] = $person->getId();
	}

	return [$container, $scenario, $container->getByType(VotingFacade::class), $ids];
}

test('adopted proposals get numbers by the body mask, continuing across meetings of the year', function (): void {
	[$container, $scenario, $voting] = bootNumbering();
	$people = $container->getByType(PeopleFacade::class);
	$people->updateBody($scenario->meeting->getBody()->getId(), new SaveBody($scenario->meeting->getBody()->getType(), $scenario->meeting->getBody()->getName(), 5, 'ZO/{meeting}/{n:2}/{yyyy}', NumberingScope::Year));
	startedMeeting($container, $scenario);

	$first = $voting->open($scenario->item->getId(), VotingType::Proposal, 'Zastupitelstvo schvaluje rozpočet.');
	$voting->allFor($first->getId());
	$outcome = $voting->close($first->getId());
	Assert::same('ZO/1/01/2026', $outcome->resolution?->getNumber());
	Assert::same('Zastupitelstvo schvaluje rozpočet.', $outcome->resolution?->getText());

	$amendment = $voting->open($scenario->item->getId(), VotingType::Amendment, 'Doplnit rezervu.');
	$voting->allFor($amendment->getId());
	Assert::null($voting->close($amendment->getId())->resolution, 'pozměňovací návrh usnesení nezakládá');

	$election = $voting->open($scenario->item->getId(), VotingType::Election, null, $container->getByType(PeopleFacade::class)->findPersons($scenario->municipality->getId())[0]->getId());
	$voting->allFor($election->getId());
	Assert::same('ZO/1/02/2026', $voting->close($election->getId())->resolution?->getNumber());

	$next = $scenario->another($container, '2026-12-16 18:00');
	startedMeeting($container, $next);
	$round = $voting->open($next->item->getId(), VotingType::Proposal, 'Různé.');
	$voting->allFor($round->getId());
	Assert::same('ZO/2/03/2026', $voting->close($round->getId())->resolution?->getNumber(), 'řada pokračuje přes zasedání v témže roce');

	$numbers = array_map(static fn($r) => $r->getNumber(), $container->getByType(ResolutionFacade::class)->findByMeeting($scenario->meeting->getId()));
	Assert::same(['ZO/1/01/2026', 'ZO/1/02/2026'], $numbers);
});

test('rejected vote has no resolution; correcting an adopted vote to rejected releases the last number', function (): void {
	[$container, $scenario, $voting, $ids] = bootNumbering();
	startedMeeting($container, $scenario);

	$round = $voting->open($scenario->item->getId(), VotingType::Proposal, 'Návrh A.');
	$voting->cast($round->getId(), $ids[0], VoteChoice::For);
	$voting->cast($round->getId(), $ids[1], VoteChoice::For);
	Assert::null($voting->close($round->getId())->resolution, '2 pro z 5 – nepřijato, bez čísla');

	$proposal = $voting->open($scenario->item->getId(), VotingType::Proposal, 'Návrh B.');
	$voting->allFor($proposal->getId());
	$resolution = $voting->close($proposal->getId())->resolution;
	Assert::same('1/2026', $resolution?->getNumber());
	$voting->reopen($proposal->getId());
	Assert::exception(fn() => $voting->delete($proposal->getId()), InvalidStateException::class, '%a%usnesení č. 1/2026%a%');

	foreach ([$ids[0], $ids[1], $ids[2]] as $personId) {
		$voting->cast($proposal->getId(), $personId, VoteChoice::Against);
	}
	Assert::null($voting->close($proposal->getId())->resolution, 'po opravě 2 pro – usnesení zaniklo');
	Assert::same([], $container->getByType(ResolutionFacade::class)->findByMeeting($scenario->meeting->getId()));

	$voting->reopen($proposal->getId());
	$voting->allFor($proposal->getId());
	Assert::same('1/2026', $voting->close($proposal->getId())->resolution?->getNumber(), 'uvolněné číslo se použije znovu');
});

test('sub-items: resolutions of one item share the number and differ by the sub-item (26/2/1, 26/2/2, 22/2)', function (): void {
	[$container, $scenario, $voting] = bootNumbering();
	$people = $container->getByType(PeopleFacade::class);
	$body = $scenario->meeting->getBody();
	$people->updateBody($body->getId(), new SaveBody($body->getType(), $body->getName(), 5, '{n}/{meeting}[/{sub}]', NumberingScope::Term));
	$agenda = $container->getByType(App\Model\Meeting\AgendaFacade::class);
	$meetingId = $scenario->meeting->getId();
	$housing = $agenda->addItem($meetingId, new App\Model\Meeting\Command\SaveAgendaItem('Dostupné nájemní bydlení'));
	$project = $agenda->addItem($meetingId, new App\Model\Meeting\Command\SaveAgendaItem('Žádost o dotaci'), $housing->getId());
	$contract = $agenda->addItem($meetingId, new App\Model\Meeting\Command\SaveAgendaItem('Příkazní smlouva'), $housing->getId());
	startedMeeting($container, $scenario);

	$adopt = static function (int $itemId, string $text) use ($voting): ?string {
		$round = $voting->open($itemId, VotingType::Proposal, $text);
		$voting->allFor($round->getId());

		return $voting->close($round->getId())->resolution?->getNumber();
	};

	Assert::same('1/1', $adopt($scenario->item->getId(), 'Schvaluje: rozpočet.'), 'bod bez podbodů');
	Assert::same('2/1/2', $adopt($contract->getId(), 'Schvaluje: příkazní smlouvu.'), 'podbody sdílejí číslo bodu, liší se podbodem');
	Assert::same('2/1/1', $adopt($project->getId(), 'Schvaluje: požádat o dotaci.'));
	Assert::exception(fn() => $voting->open($housing->getId(), VotingType::Proposal, 'Celý bod'), InvalidStateException::class, 'Bod má podbody – hlasuje se o každém podbodu zvlášť.');

	// opravené hlasování (nepřijato → přijato) dostane stejné číslo, sdílené pořadí se neuvolní
	$resolutions = $container->getByType(ResolutionFacade::class);
	$byNumber = [];
	foreach ($resolutions->findByMeeting($meetingId) as $resolution) {
		$byNumber[$resolution->getNumber()] = $resolution;
	}

	Assert::same(['1/1', '2/1/1', '2/1/2'], array_keys($byNumber), 'řazení podle čísla a podbodu');
	$contractRound = $byNumber['2/1/2']->getVotingRound();
	assert($contractRound !== null);
	$voting->reopen($contractRound->getId());
	foreach ($voting->voters($contractRound) as $voter) {
		$voting->cast($contractRound->getId(), $voter->getId(), VoteChoice::Against);
	}
	Assert::null($voting->close($contractRound->getId())->resolution);
	Assert::same(['1/1', '2/1/1'], array_map(static fn($r) => $r->getNumber(), $resolutions->findByMeeting($meetingId)));
	$voting->reopen($contractRound->getId());
	$voting->allFor($contractRound->getId());
	Assert::same('2/1/2', $voting->close($contractRound->getId())->resolution?->getNumber());

	Assert::same('Schvaluje', $resolutions->findByMeeting($meetingId)[1]->getLeadVerb());
});
