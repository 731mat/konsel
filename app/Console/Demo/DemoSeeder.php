<?php

declare(strict_types=1);

namespace App\Console\Demo;

use App\Application\Meeting\Command\AssignOfficials;
use App\Application\Meeting\Command\SaveMeeting;
use App\Application\Meeting\MeetingFacade;
use App\Application\Municipality\Command\CreateMunicipality;
use App\Application\Municipality\Command\UpdateMunicipalitySettings;
use App\Application\Municipality\MunicipalityFacade;
use App\Application\People\Command\AddMember;
use App\Application\People\Command\SaveBody;
use App\Application\People\Command\SavePerson;
use App\Application\People\PeopleFacade;
use App\Application\User\Command\CreateUser;
use App\Application\User\UserFacade;
use App\Domain\Meeting\MeetingTransition;
use App\Domain\Meeting\MeetingType;
use App\Domain\People\BodyType;
use App\Domain\People\MemberFunction;
use App\Domain\User\Role;
use Psr\Clock\ClockInterface;

/**
 * Realistická demo data pro vývoj a ukázky. Zakládá vše přes fasády (stejné cesty jako aplikace).
 * Každý task, který přidává funkci, sem doplní její data.
 */
final readonly class DemoSeeder
{
	public const string Password = 'demo-heslo-123';

	private const array Councillors = [
		['Jan', 'Dvořák', 'Ing.', null, MemberFunction::Mayor],
		['Marie', 'Svobodová', null, null, MemberFunction::DeputyMayor],
		['Petr', 'Novotný', null, null, MemberFunction::Member],
		['Lenka', 'Černá', 'Mgr.', null, MemberFunction::Member],
		['Tomáš', 'Procházka', null, null, MemberFunction::Member],
		['Hana', 'Kučerová', null, null, MemberFunction::Member],
		['Josef', 'Veselý', null, null, MemberFunction::Member],
		['Eva', 'Horáková', 'Bc.', null, MemberFunction::Member],
		['Martin', 'Němec', null, 'Ph.D.', MemberFunction::Member],
	];

	public function __construct(
		private MunicipalityFacade $municipalities,
		private UserFacade $users,
		private PeopleFacade $people,
		private MeetingFacade $meetings,
		private ClockInterface $clock,
	) {}

	/**
	 * @return list<string> přehled založených dat pro výpis
	 */
	public function seed(): array
	{
		$municipality = $this->municipalities->create(new CreateMunicipality('Neplachovice'));
		$municipalityId = $municipality->getId();
		$this->municipalities->updateSettings($municipalityId, new UpdateMunicipalitySettings(
			name: 'Neplachovice',
			officialName: 'Obec Neplachovice',
			street: 'Hlavní 1',
			city: 'Neplachovice',
			postalCode: '747 74',
			email: 'obec@demo.konsel.test',
			phone: '553 000 000',
			population: 650,
		));

		$this->users->create(new CreateUser('superadmin@demo.konsel.test', 'Správce systému', Role::SuperAdmin, self::Password));
		$this->users->create(new CreateUser('spravce@demo.konsel.test', 'Jana Správcová', Role::Admin, self::Password, $municipalityId));
		$this->users->create(new CreateUser('editor@demo.konsel.test', 'Pavla Úřednice', Role::Editor, self::Password, $municipalityId));
		$this->users->create(new CreateUser('ctenar@demo.konsel.test', 'Karel Čtenář', Role::Reader, self::Password, $municipalityId));

		$term = $this->people->createTerm($municipalityId, '2026–2030', new \DateTimeImmutable('2026-10-03'), new \DateTimeImmutable('2030-10-02'));
		$council = $this->people->createBody($term->getId(), new SaveBody(BodyType::Council, 'Zastupitelstvo obce Neplachovice', 9));
		$finance = $this->people->createBody($term->getId(), new SaveBody(BodyType::Committee, 'Finanční výbor', 3));
		$control = $this->people->createBody($term->getId(), new SaveBody(BodyType::Committee, 'Kontrolní výbor', 3));

		$constituentDay = new \DateTimeImmutable('2026-10-21');
		$persons = [];
		foreach (self::Councillors as $i => [$first, $last, $before, $after, $function]) {
			$person = $this->people->createPerson($municipalityId, new SavePerson(
				firstName: $first,
				lastName: $last,
				titleBefore: $before,
				titleAfter: $after,
				email: sprintf('zastupitel%d@demo.konsel.test', $i + 1),
			));
			$persons[] = $person;
			$this->people->addMember($council->getId(), new AddMember($person->getId(), $function, $constituentDay));
		}

		$this->people->addMember($finance->getId(), new AddMember($persons[2]->getId(), MemberFunction::Chair, $constituentDay));
		$this->people->addMember($finance->getId(), new AddMember($persons[5]->getId(), MemberFunction::Member, $constituentDay));
		$this->people->addMember($finance->getId(), new AddMember($persons[6]->getId(), MemberFunction::Member, $constituentDay));
		$this->people->addMember($control->getId(), new AddMember($persons[3]->getId(), MemberFunction::Chair, $constituentDay));
		$this->people->addMember($control->getId(), new AddMember($persons[7]->getId(), MemberFunction::Member, $constituentDay));
		$this->people->addMember($control->getId(), new AddMember($persons[8]->getId(), MemberFunction::Member, $constituentDay));

		$officeRoom = 'Zasedací místnost obecního úřadu, Hlavní 1';
		$constituent = $this->meetings->create($council->getId(), new SaveMeeting(MeetingType::Constituent, $constituentDay->setTime(18, 0), 'Kulturní dům Neplachovice'));
		$this->meetings->assignOfficials($constituent->getId(), new AssignOfficials($persons[0]->getId(), $persons[7]->getId(), $persons[2]->getId(), $persons[3]->getId()));
		$this->runMeeting($constituent->getId(), $constituentDay->setTime(18, 0), $constituentDay->setTime(19, 40), approveMinutes: true);

		// Další zasedání se vztahují k dnešku, aby demo vždy ukazovalo proběhlé, sdílené i připravované zasedání.
		$today = $this->clock->now()->setTime(18, 0);
		$pastDay = max($constituentDay->modify('+14 days'), $today->modify('-5 days'))->setTime(18, 0);
		$past = $this->meetings->create($council->getId(), new SaveMeeting(MeetingType::Regular, $pastDay, $officeRoom));
		$this->meetings->assignOfficials($past->getId(), new AssignOfficials($persons[0]->getId(), $persons[7]->getId(), $persons[4]->getId(), $persons[5]->getId()));
		$this->runMeeting($past->getId(), $pastDay, $pastDay->setTime(20, 10), approveMinutes: false);

		$shared = $this->meetings->create($council->getId(), new SaveMeeting(MeetingType::Regular, $today->modify('+3 weeks'), $officeRoom));
		$this->meetings->assignOfficials($shared->getId(), new AssignOfficials(chairpersonId: $persons[0]->getId(), recorderId: $persons[7]->getId()));
		$this->meetings->transition($shared->getId(), MeetingTransition::Share);

		$this->meetings->create($council->getId(), new SaveMeeting(MeetingType::Regular, $today->modify('+9 weeks'), $officeRoom));

		return [
			'Obec Neplachovice (650 obyvatel)',
			'Uživatelé (heslo ' . self::Password . '): spravce@, editor@, ctenar@, superadmin@demo.konsel.test',
			'Volební období 2026–2030, zastupitelstvo 9 členů, finanční a kontrolní výbor',
			'Zasedání zastupitelstva: ustavující (zápis ověřen), proběhlé, sdílené a v přípravě',
		];
	}

	/**
	 * Proběhlé zasedání: sdílení → zahájení → ukončení se skutečnými časy (případně ověření zápisu).
	 */
	private function runMeeting(int $meetingId, \DateTimeImmutable $startedAt, \DateTimeImmutable $endedAt, bool $approveMinutes): void
	{
		foreach ([MeetingTransition::Share, MeetingTransition::Start, MeetingTransition::Close] as $transition) {
			$this->meetings->transition($meetingId, $transition);
		}

		$this->meetings->correctTimes($meetingId, $startedAt, $endedAt);
		if ($approveMinutes) {
			$this->meetings->transition($meetingId, MeetingTransition::ApproveMinutes);
		}
	}
}
