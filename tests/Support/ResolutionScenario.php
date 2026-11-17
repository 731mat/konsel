<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Model\Meeting\AgendaFacade;
use App\Model\Meeting\Command\SaveAgendaItem;
use App\Model\Meeting\MeetingFacade;
use App\Model\Meeting\MeetingTransition;
use App\Model\Meeting\ProceedingsFacade;
use App\Model\Meeting\VotingFacade;
use App\Model\Meeting\VotingType;
use App\Model\People\Command\AddMember;
use App\Model\People\Command\SavePerson;
use App\Model\People\MemberFunction;
use App\Model\People\PeopleFacade;
use App\Model\People\Person;
use App\Model\Resolution\Resolution;
use Nette\DI\Container;

/**
 * Proběhlé zasedání s pěti přítomnými členy a usneseními vzniklými skutečným hlasováním.
 */
final readonly class ResolutionScenario
{
	/**
	 * @param list<Resolution> $resolutions
	 * @param list<Person> $members
	 */
	public function __construct(
		public MeetingScenario $meeting,
		public array $resolutions,
		public array $members,
	) {}

	/**
	 * @param list<string> $proposals znění návrhů (každý jako samostatný bod, všichni pro)
	 * @param (\Closure(MeetingScenario): void)|null $beforeStart úpravy programu před sdílením a zahájením
	 */
	public static function create(Container $container, array $proposals = ['Zastupitelstvo schvaluje rozpočet na rok 2027.'], ?\Closure $beforeStart = null): self
	{
		$scenario = MeetingScenario::create($container);
		$people = $container->getByType(PeopleFacade::class);
		$members = [];
		foreach (['Dvořák', 'Svobodová', 'Novotný', 'Černá', 'Veselý'] as $lastName) {
			$person = $people->createPerson($scenario->municipality->getId(), new SavePerson('Člen', $lastName, email: mb_strtolower(str_replace(['ř', 'á', 'ý', 'č'], ['r', 'a', 'y', 'c'], $lastName)) . '@obec.cz'));
			$people->addMember($scenario->meeting->getBody()->getId(), new AddMember($person->getId(), MemberFunction::Member, new \DateTimeImmutable('2026-10-21')));
			$members[] = $person;
		}

		$agenda = $container->getByType(AgendaFacade::class);
		$items = [$scenario->item];
		foreach (array_slice($proposals, 1) as $i => $text) {
			$items[] = $agenda->addItem($scenario->meeting->getId(), new SaveAgendaItem('Bod ' . ($i + 2)));
		}

		if ($beforeStart !== null) {
			$beforeStart($scenario);
		}

		$meetings = $container->getByType(MeetingFacade::class);
		$meetings->transition($scenario->meeting->getId(), MeetingTransition::Share);
		$container->getByType(ProceedingsFacade::class)->markAllPresent($scenario->meeting->getId());
		$meetings->transition($scenario->meeting->getId(), MeetingTransition::Start);

		$voting = $container->getByType(VotingFacade::class);
		$resolutions = [];
		foreach ($proposals as $i => $text) {
			$round = $voting->open($items[$i]->getId(), VotingType::Proposal, $text);
			$voting->allFor($round->getId());
			$resolution = $voting->close($round->getId())->resolution;
			assert($resolution !== null);
			$resolutions[] = $resolution;
		}

		return new self($scenario, $resolutions, $members);
	}
}
