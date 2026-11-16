<?php

declare(strict_types=1);

namespace App\Console\Demo;

use App\Model\Attachment\AttachmentFacade;
use App\Model\Meeting\AgendaFacade;
use App\Model\Meeting\Command\AssignOfficials;
use App\Model\Meeting\Command\SaveAgendaItem;
use App\Model\Meeting\Command\SaveMeeting;
use App\Model\Meeting\MeetingFacade;
use App\Model\Meeting\MeetingTransition;
use App\Model\Meeting\MeetingType;
use App\Model\Municipality\Command\CreateMunicipality;
use App\Model\Municipality\Command\UpdateMunicipalitySettings;
use App\Model\Municipality\MunicipalityFacade;
use App\Model\People\BodyType;
use App\Model\People\Command\AddMember;
use App\Model\People\Command\SaveBody;
use App\Model\People\Command\SavePerson;
use App\Model\People\MemberFunction;
use App\Model\People\PeopleFacade;
use App\Model\Storage\UploadedFile;
use App\Model\User\Command\CreateUser;
use App\Model\User\Role;
use App\Model\User\UserFacade;
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
		private AgendaFacade $agenda,
		private AttachmentFacade $attachments,
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
		$this->program($constituent->getId(), [
			['Složení slibu členů zastupitelstva', 'Předsedající vyzve přítomné členy zastupitelstva ke složení slibu podle § 69 odst. 2 zákona o obcích.', null],
			['Volba starosty a místostarosty', 'Určení funkcí, pro které budou členové zastupitelstva uvolněni, způsobu volby a samotná volba.', 'Zastupitelstvo obce Neplachovice volí starostou obce Ing. Jana Dvořáka a místostarostkou Marii Svobodovou.'],
			['Zřízení finančního a kontrolního výboru', 'Zastupitelstvo zřizuje finanční a kontrolní výbor (§ 117 zákona o obcích) a volí jejich předsedy a členy.', 'Zastupitelstvo obce Neplachovice zřizuje finanční výbor a kontrolní výbor, oba tříčlenné.'],
			['Odměny neuvolněným členům zastupitelstva', null, 'Zastupitelstvo obce Neplachovice stanoví měsíční odměny neuvolněným členům zastupitelstva podle nařízení vlády č. 318/2017 Sb.'],
		]);
		$this->runMeeting($constituent->getId(), $constituentDay->setTime(18, 0), $constituentDay->setTime(19, 40), approveMinutes: true);

		// Další zasedání se vztahují k dnešku, aby demo vždy ukazovalo proběhlé, sdílené i připravované zasedání.
		$today = $this->clock->now()->setTime(18, 0);
		$pastDay = max($constituentDay->modify('+14 days'), $today->modify('-5 days'))->setTime(18, 0);
		$past = $this->meetings->create($council->getId(), new SaveMeeting(MeetingType::Regular, $pastDay, $officeRoom));
		$this->meetings->assignOfficials($past->getId(), new AssignOfficials($persons[0]->getId(), $persons[7]->getId(), $persons[4]->getId(), $persons[5]->getId()));
		$this->program($past->getId(), [
			['Rozpočtové opatření č. 4/2026', 'Navýšení výdajů na opravu chodníku o 120 000 Kč, kryto vyššími daňovými příjmy.', 'Zastupitelstvo obce Neplachovice schvaluje rozpočtové opatření č. 4/2026 dle přílohy.'],
			['Smlouva o dílo – oprava místní komunikace', 'Výběrové řízení vyhrála firma Silnice Morava s.r.o. s nabídkou 1 840 000 Kč bez DPH.', 'Zastupitelstvo obce Neplachovice schvaluje uzavření smlouvy o dílo na opravu místní komunikace se společností Silnice Morava s.r.o. a pověřuje starostu jejím podpisem.'],
			['Plán inventarizace majetku obce za rok 2026', null, 'Zastupitelstvo obce Neplachovice schvaluje plán inventarizace a jmenuje inventarizační komisi.'],
		]);
		$this->runMeeting($past->getId(), $pastDay, $pastDay->setTime(20, 10), approveMinutes: false);

		$shared = $this->meetings->create($council->getId(), new SaveMeeting(MeetingType::Regular, $today->modify('+3 weeks'), $officeRoom));
		$this->meetings->assignOfficials($shared->getId(), new AssignOfficials(chairpersonId: $persons[0]->getId(), recorderId: $persons[7]->getId()));
		$this->program($shared->getId(), [
			['Rozpočet obce na rok 2027', "Návrh rozpočtu byl zveřejněn na úřední desce a webu obce 15 dní předem.\n\nRozpočet je navržen jako vyrovnaný: příjmy i výdaje 14,2 mil. Kč.", 'Zastupitelstvo obce Neplachovice schvaluje rozpočet obce na rok 2027 jako vyrovnaný ve výši 14 200 000 Kč.'],
			['Střednědobý výhled rozpočtu na roky 2028–2029', null, 'Zastupitelstvo obce Neplachovice schvaluje střednědobý výhled rozpočtu na roky 2028–2029.'],
			['Obecně závazná vyhláška o místním poplatku za obecní systém odpadového hospodářství', 'Poplatek se zvyšuje z 750 Kč na 850 Kč na osobu a rok kvůli vyšším nákladům na svoz.', 'Zastupitelstvo obce Neplachovice vydává obecně závaznou vyhlášku č. 1/2026 o místním poplatku za obecní systém odpadového hospodářství.'],
			['Prodej pozemku p. č. 456/7 v k. ú. Neplachovice', 'Záměr prodeje byl zveřejněn od 2. do 18. listopadu. Přihlásil se jeden zájemce.', 'Zastupitelstvo obce Neplachovice schvaluje prodej pozemku p. č. 456/7 o výměře 312 m² za cenu 450 Kč/m².'],
		]);
		$this->meetings->transition($shared->getId(), MeetingTransition::Share);
		$sharedItems = $this->meetings->get($shared->getId())->getAgendaItems();
		$this->attachments->upload($sharedItems[3]->getId(), [
			self::file('Návrh rozpočtu 2027.pdf', self::pdf(['Navrh rozpoctu obce Neplachovice na rok 2027', 'Prijmy: 14 200 000 Kc', 'Vydaje: 14 200 000 Kc'])),
			self::file('Rozpočet 2027 – položky.csv', "Paragraf;Položka;Částka\n2212;Silnice;2 400 000\n3113;Základní škola;1 850 000\n3722;Odpady;1 100 000\n"),
		], null);
		$this->attachments->upload($sharedItems[6]->getId(), [
			self::file('Záměr prodeje pozemku 456-7.pdf', self::pdf(['Zamer prodeje pozemku p. c. 456/7', 'Vymera 312 m2, k. u. Neplachovice'])),
		], null);

		$draft = $this->meetings->create($council->getId(), new SaveMeeting(MeetingType::Regular, $today->modify('+9 weeks'), $officeRoom));
		$this->agenda->addItem($draft->getId(), new SaveAgendaItem('Zpráva o činnosti finančního výboru', rapporteurId: $persons[2]->getId()));

		return [
			'Obec Neplachovice (650 obyvatel)',
			'Uživatelé (heslo ' . self::Password . '): spravce@, editor@, ctenar@, superadmin@demo.konsel.test',
			'Volební období 2026–2030, zastupitelstvo 9 členů, finanční a kontrolní výbor',
			'Zasedání zastupitelstva s programem: ustavující (zápis ověřen), proběhlé, sdílené a v přípravě',
		];
	}

	/**
	 * Program: zadané body + standardní úvod (zahájení, ověřovatelé, schválení programu) a závěr.
	 *
	 * @param list<array{string, ?string, ?string}> $items [název, popis, návrh usnesení]
	 */
	private function program(int $meetingId, array $items): void
	{
		foreach ($items as [$title, $description, $resolution]) {
			$this->agenda->addItem($meetingId, new SaveAgendaItem($title, $description, $resolution));
		}

		$this->agenda->insertStandardItems($meetingId);
	}

	private static function file(string $name, string $content): UploadedFile
	{
		$stream = fopen('php://temp', 'w+b');
		assert($stream !== false);
		fwrite($stream, $content);
		rewind($stream);

		return new UploadedFile($stream, $name);
	}

	/**
	 * Minimální platné PDF s řádky textu (ASCII) – demo podklady bez externích souborů.
	 *
	 * @param list<string> $lines
	 */
	private static function pdf(array $lines): string
	{
		$text = 'BT /F1 16 Tf 72 770 Td 22 TL';
		foreach ($lines as $line) {
			$text .= ' (' . addcslashes($line, '()\\') . ') Tj T*';
		}

		$objects = [
			'<< /Type /Catalog /Pages 2 0 R >>',
			'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
			'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
			'<< /Length ' . (strlen($text) + 3) . " >>\nstream\n{$text} ET\nendstream",
			'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
		];
		$pdf = "%PDF-1.4\n";
		$offsets = [];
		foreach ($objects as $i => $object) {
			$offsets[] = strlen($pdf);
			$pdf .= ($i + 1) . " 0 obj\n{$object}\nendobj\n";
		}

		$xref = strlen($pdf);
		$pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
		foreach ($offsets as $offset) {
			$pdf .= sprintf("%010d 00000 n \n", $offset);
		}

		return $pdf . "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
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
