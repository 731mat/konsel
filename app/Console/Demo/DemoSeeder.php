<?php

declare(strict_types=1);

namespace App\Console\Demo;

use App\Model\Access\AccessFacade;
use App\Model\Attachment\AttachmentFacade;
use App\Model\Comment\CommentAuthorRef;
use App\Model\Comment\CommentFacade;
use App\Model\Mail\InvitationFacade;
use App\Model\Meeting\AgendaFacade;
use App\Model\Meeting\AttendanceStatus;
use App\Model\Meeting\Command\AssignOfficials;
use App\Model\Meeting\Command\SaveAgendaItem;
use App\Model\Meeting\Command\SaveMeeting;
use App\Model\Meeting\MeetingFacade;
use App\Model\Meeting\MeetingTransition;
use App\Model\Meeting\MeetingType;
use App\Model\Meeting\ProceedingsFacade;
use App\Model\Meeting\VoteChoice;
use App\Model\Meeting\VotingFacade;
use App\Model\Meeting\VotingType;
use App\Model\Municipality\Command\CreateMunicipality;
use App\Model\Municipality\Command\UpdateMunicipalitySettings;
use App\Model\Municipality\MunicipalityFacade;
use App\Model\People\BodyType;
use App\Model\People\Command\AddMember;
use App\Model\People\Command\SaveBody;
use App\Model\People\Command\SavePerson;
use App\Model\People\MemberFunction;
use App\Model\People\PeopleFacade;
use App\Model\Project\Command\SaveProject;
use App\Model\Project\ProjectFacade;
use App\Model\Project\ProjectPriority;
use App\Model\Project\ProjectStatus;
use App\Model\Storage\UploadedFile;
use App\Model\Template\TemplateFacade;
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
		private CommentFacade $comments,
		private AccessFacade $access,
		private TemplateFacade $templates,
		private ProjectFacade $projects,
		private InvitationFacade $invitations,
		private ProceedingsFacade $proceedings,
		private VotingFacade $voting,
		private ClockInterface $clock,
		private string $appUrl = 'http://localhost:8080',
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
		$admin = $this->users->create(new CreateUser('spravce@demo.konsel.test', 'Jana Správcová', Role::Admin, self::Password, $municipalityId));
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

		$this->templates->installDefaultResolutionTemplates($municipalityId);
		$this->templates->installDefaultMeetingTemplates($municipalityId);

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
		// bod 5 = smlouva o dílo (po standardním úvodu): jeden proti, jeden se zdržel
		$this->runMeeting($past->getId(), $pastDay, $pastDay->setTime(20, 10), approveMinutes: false, excused: [$persons[8]->getId()], exceptions: [
			5 => [
				$persons[6]->getId() => VoteChoice::Against,
				$persons[4]->getId() => VoteChoice::Abstain,
			],
		]);

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
		$question = $this->comments->addByPerson($sharedItems[3]->getId(), $persons[2]->getId(), 'Proč se proti letošku zvyšují výdaje na odpady o 180 tisíc? Je to jen novým ceníkem svozové firmy?');
		$this->comments->addByUser($sharedItems[3]->getId(), $admin->getId(), '<p>Ano, svozová firma zdražila od ledna o <strong>12 %</strong>. Rozpis:</p><ul><li><p>svoz směsného odpadu +140 tis. Kč</p></li><li><p>tříděný odpad +40 tis. Kč</p></li></ul><p>Ceník je v příloze rozpočtu, řádek 3722.</p>', $question->getId());
		$this->comments->addByPerson($sharedItems[3]->getId(), $persons[6]->getId(), 'Navrhuji do rozpočtu doplnit rezervu na opravu hřiště u školy.');
		$this->attachments->upload($sharedItems[6]->getId(), [
			self::file('Záměr prodeje pozemku 456-7.pdf', self::pdf(['Zamer prodeje pozemku p. c. 456/7', 'Vymera 312 m2, k. u. Neplachovice'])),
		], null);

		// pozvánky e-mailem (ve vývoji je zachytí Mailpit) – odkaz Petra Novotného se vypíše na konci
		$invitations = $this->invitations->send($shared->getId(), fn(string $token): string => rtrim($this->appUrl, '/') . "/odkaz/{$token}");
		$portalLink = null;
		foreach ([...$invitations->sent, ...$invitations->notDelivered()] as $issued) {
			if ($issued->accessToken->getPerson() === $persons[2]) {
				$portalLink = $issued;
			}
		}

		$portalLink ??= $this->access->issueFor($shared->getId(), $persons[2]->getId());

		$draft = $this->meetings->create($council->getId(), new SaveMeeting(MeetingType::Regular, $today->modify('+9 weeks'), $officeRoom));
		$this->agenda->addItem($draft->getId(), new SaveAgendaItem('Zpráva o činnosti finančního výboru', rapporteurId: $persons[2]->getId()));
		$schoolDocumentation = $this->agenda->addItem($draft->getId(), new SaveAgendaItem(
			'Schválení projektové dokumentace mateřské školy',
			'Projektant předal dokumentaci pro stavební povolení. Odhad nákladů stavby 45 mil. Kč, obec podá žádost o dotaci z IROP.',
			'Zastupitelstvo obce Neplachovice schvaluje projektovou dokumentaci stavby „Mateřská škola Neplachovice“ a pověřuje starostu podáním žádosti o dotaci.',
			$persons[0]->getId(),
		));

		$this->seedProjects($municipalityId, $admin->getId(), $persons, $past->getId(), $sharedItems[3]->getId(), $schoolDocumentation->getId());

		return [
			'Obec Neplachovice (650 obyvatel)',
			'Uživatelé (heslo ' . self::Password . '): spravce@, editor@, ctenar@, superadmin@demo.konsel.test',
			'Volební období 2026–2030, zastupitelstvo 9 členů, finanční a kontrolní výbor',
			'Knihovna šablon usnesení a programu (řádné a ustavující zasedání)',
			'Úkolníček: mateřská škola (propojená se 2 zasedáními), oprava komunikace, LED osvětlení, podnět občana',
			'Zasedání zastupitelstva s programem: ustavující (zápis ověřen), proběhlé (1 omluvený, odhlasované body), sdílené a v přípravě',
			sprintf('Pozvánky na sdílené zasedání: %d e-mailů odesláno, %d neodesláno (vývoj: http://localhost:8025)', count($invitations->sent), count($invitations->notDelivered())),
			"Portál zastupitele (Petr Novotný, sdílené zasedání): /odkaz/{$portalLink->token}",
			'Stránka pro občany: /obec/neplachovice/podnety',
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

	/**
	 * Úkolníček: úkoly v různých fázích propojené s body zasedání, diskuse a veřejné komentáře.
	 *
	 * @param list<\App\Model\People\Person> $persons
	 */
	private function seedProjects(int $municipalityId, int $adminId, array $persons, int $pastMeetingId, int $budgetItemId, int $documentationItemId): void
	{
		$school = $this->projects->create($municipalityId, new SaveProject(
			'Výstavba nové mateřské školy',
			"Stávající školka má kapacitu 28 dětí, zájem je o 50 míst. Cílem je nová budova na pozemku za obecním úřadem.\n\nFinancování: dotace IROP (až 70 %) + úvěr.",
			ProjectPriority::High,
			$persons[0]->getId(),
			45_000_000,
		), 'Jana Správcová');
		$this->projects->changeStatus($school->getId(), ProjectStatus::Preparation, 'Jana Správcová', 'Zastupitelstvo souhlasí se záměrem');
		$this->comments->addToProject($school->getId(), CommentAuthorRef::person($persons[3]->getId()), 'Prosím o porovnání s variantou rozšíření stávající budovy – může vyjít levněji.');
		$this->projects->changeStatus($school->getId(), ProjectStatus::Design, 'Jana Správcová', 'Objednána projektová dokumentace');
		$this->comments->addToProject($school->getId(), CommentAuthorRef::user($adminId), '<p>Projektant předá dokumentaci <strong>do konce roku</strong>. Na projekt je v rozpočtu 2027 vyčleněno 2 mil. Kč.</p><blockquote><p>Dotační výzva MMR se otevírá v březnu 2027.</p></blockquote>', publicForCitizens: true);
		$this->projects->linkAgendaItem($budgetItemId, $school->getId());
		$this->projects->linkAgendaItem($documentationItemId, $school->getId());
		$this->projects->setPublic($school->getId(), true);

		$road = $this->projects->create($municipalityId, new SaveProject(
			'Oprava místní komunikace Za Školou',
			'Výtluky a propadlé krajnice, v zimě nebezpečné pro autobus.',
			ProjectPriority::Normal,
			$persons[1]->getId(),
			1_840_000,
		), 'Jana Správcová');
		$this->projects->changeStatus($road->getId(), ProjectStatus::Approved, 'Jana Správcová', 'Smlouva o dílo schválena zastupitelstvem');
		$this->projects->changeStatus($road->getId(), ProjectStatus::InProgress, 'Jana Správcová');
		foreach ($this->meetings->get($pastMeetingId)->getAgendaItems() as $item) {
			if (strpos($item->getTitle(), 'Smlouva o dílo') === 0) {
				$this->projects->linkAgendaItem($item->getId(), $road->getId());
			}
		}

		$this->comments->addToProject($road->getId(), CommentAuthorRef::user($adminId), 'Stavba začne v pondělí, ulice bude 3 týdny průjezdná jen jedním pruhem.', publicForCitizens: true);
		$this->projects->setPublic($road->getId(), true);

		$lights = $this->projects->create($municipalityId, new SaveProject('Výměna veřejného osvětlení za LED', 'Úspora asi 60 % energie, možnost dotace z programu EFEKT.', ProjectPriority::Low), 'Jana Správcová');
		$this->projects->setPublic($lights->getId(), true);

		$this->projects->submitProposal($municipalityId, 'Marie Občanová', 'marie.obcanova@demo.konsel.test', 'Lavičky a odpadkové koše v parku u kostela', 'V parku chybí místo k sezení pro starší občany a koše se rychle zaplní.', null);
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
	/**
	 * Odehraje zasedání: sdílení, docházka (omluvení + ostatní přítomni), zahájení, hlasování
	 * o všech návrzích usnesení (všichni pro, kromě výjimek), ukončení.
	 *
	 * @param list<int> $excused ID omluvených členů
	 * @param array<int, array<int, VoteChoice>> $exceptions pořadí bodu → ID osoby → volba
	 */
	private function runMeeting(int $meetingId, \DateTimeImmutable $startedAt, \DateTimeImmutable $endedAt, bool $approveMinutes, array $excused = [], array $exceptions = []): void
	{
		$this->meetings->transition($meetingId, MeetingTransition::Share);
		foreach ($excused as $personId) {
			$this->proceedings->mark($meetingId, $personId, AttendanceStatus::Excused);
		}

		$this->proceedings->markAllPresent($meetingId);
		$this->meetings->transition($meetingId, MeetingTransition::Start);
		foreach ($this->meetings->get($meetingId)->getAgendaItems() as $item) {
			if ($item->getProposedResolution() === null) {
				continue;
			}

			$round = $this->voting->open($item->getId(), VotingType::Proposal);
			$this->voting->allFor($round->getId());
			foreach ($exceptions[$item->getPosition()] ?? [] as $personId => $choice) {
				$this->voting->cast($round->getId(), $personId, $choice);
			}

			$this->voting->close($round->getId());
		}

		$this->meetings->transition($meetingId, MeetingTransition::Close);

		$this->meetings->correctTimes($meetingId, $startedAt, $endedAt);
		if ($approveMinutes) {
			$this->meetings->transition($meetingId, MeetingTransition::ApproveMinutes);
		}
	}
}
