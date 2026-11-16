<?php

declare(strict_types=1);

namespace App\Console\Demo;

use App\Application\Municipality\Command\CreateMunicipality;
use App\Application\Municipality\Command\UpdateMunicipalitySettings;
use App\Application\Municipality\MunicipalityFacade;
use App\Application\People\Command\AddMember;
use App\Application\People\Command\SaveBody;
use App\Application\People\Command\SavePerson;
use App\Application\People\PeopleFacade;
use App\Application\User\Command\CreateUser;
use App\Application\User\UserFacade;
use App\Domain\People\BodyType;
use App\Domain\People\MemberFunction;
use App\Domain\User\Role;

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

		return [
			'Obec Neplachovice (650 obyvatel)',
			'Uživatelé (heslo ' . self::Password . '): spravce@, editor@, ctenar@, superadmin@demo.konsel.test',
			'Volební období 2026–2030, zastupitelstvo 9 členů, finanční a kontrolní výbor',
		];
	}
}
