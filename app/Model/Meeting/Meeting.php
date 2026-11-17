<?php

declare(strict_types=1);

namespace App\Model\Meeting;

use App\Model\Municipality\Municipality;
use App\Model\People\Body;
use App\Model\People\BodyType;
use App\Model\People\Person;
use App\Model\Shared\Exception\InvalidStateException;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\IntegerId;
use App\Model\Shared\TenantOwned;
use App\Model\Shared\Timestampable;
use App\Model\Shared\TimestampableTrait;
use App\Model\User\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Psr\Clock\ClockInterface;

/**
 * Zasedání zastupitelstva / schůze rady / jednání výboru.
 * Stavový automat (MeetingTransition) je v entitě – stav se mění jen přes share/start/close/approveMinutes/reopen.
 */
#[ORM\Entity]
#[ORM\Table(name: 'meeting')]
#[ORM\UniqueConstraint(columns: ['body_id', 'number'])]
#[ORM\Index(columns: ['scheduled_at'])]
final class Meeting implements TenantOwned, Timestampable
{
	use IntegerId;
	use TimestampableTrait;

	/**
	 * Informace o zasedání zastupitelstva musí být na úřední desce alespoň 7 dní předem (§ 93 odst. 1 zákona o obcích).
	 */
	public const int NoticePeriodDays = 7;

	/**
	 * Zápis musí být pořízen do 10 dnů po skončení zasedání (§ 95 odst. 1 zákona o obcích).
	 */
	public const int MinutesDeadlineDays = 10;

	public const int MaxDiscussionLength = 20_000;

	public const int MaxMinutesNoteLength = 5000;

	#[ORM\ManyToOne(targetEntity: Municipality::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private Municipality $municipality;

	#[ORM\ManyToOne(targetEntity: Body::class)]
	#[ORM\JoinColumn(nullable: false)]
	private Body $body;

	/**
	 * Pořadí zasedání orgánu ve volebním období.
	 */
	#[ORM\Column]
	private int $number;

	#[ORM\Column(length: 20, enumType: MeetingType::class)]
	private MeetingType $type;

	#[ORM\Column]
	private \DateTimeImmutable $scheduledAt;

	#[ORM\Column(length: 200)]
	private string $place;

	#[ORM\Column(length: 20, enumType: MeetingStatus::class)]
	private MeetingStatus $status = MeetingStatus::Draft;

	/**
	 * Optimistický zámek proti přepsání souběžné úpravy (ADR A14).
	 */
	#[ORM\Version]
	#[ORM\Column(type: 'integer')]
	private int $version = 1;

	#[ORM\ManyToOne(targetEntity: Person::class)]
	private ?Person $chairperson = null;

	#[ORM\ManyToOne(targetEntity: Person::class)]
	private ?Person $recorder = null;

	#[ORM\ManyToOne(targetEntity: Person::class)]
	private ?Person $firstVerifier = null;

	#[ORM\ManyToOne(targetEntity: Person::class)]
	private ?Person $secondVerifier = null;

	#[ORM\Column(nullable: true)]
	private ?\DateTimeImmutable $sharedAt = null;

	#[ORM\Column(nullable: true)]
	private ?\DateTimeImmutable $startedAt = null;

	#[ORM\Column(nullable: true)]
	private ?\DateTimeImmutable $endedAt = null;

	/**
	 * Počet hostů (veřejnosti) do zápisu.
	 */
	#[ORM\Column(nullable: true)]
	private ?int $guestCount = null;

	/**
	 * Další údaje do zápisu (např. kdo se dostavil později, technické poznámky).
	 */
	#[ORM\Column(type: 'text', nullable: true)]
	private ?string $minutesNote = null;

	/**
	 * @var Collection<int, AgendaItem>
	 */
	#[ORM\OneToMany(targetEntity: AgendaItem::class, mappedBy: 'meeting', cascade: ['persist', 'remove'], orphanRemoval: true)]
	#[ORM\OrderBy([
		'position' => 'ASC',
	])]
	private Collection $agendaItems;

	/**
	 * Historie znovuotevření (kdo, kdy, proč) – vzniká jen přes reopen().
	 *
	 * @var Collection<int, MeetingReopening>
	 */
	#[ORM\OneToMany(targetEntity: MeetingReopening::class, mappedBy: 'meeting', cascade: ['persist'])]
	#[ORM\OrderBy([
		'reopenedAt' => 'ASC',
	])]
	private Collection $reopenings;

	public function __construct(Body $body, int $number, MeetingType $type, \DateTimeImmutable $scheduledAt, string $place)
	{
		$this->municipality = $body->getMunicipality();
		$this->body = $body;
		$this->agendaItems = new ArrayCollection();
		$this->reopenings = new ArrayCollection();
		$this->renumber($number);
		$this->reschedule($type, $scheduledAt, $place);
	}

	/**
	 * Typ, datum a místo lze měnit jen před zahájením zasedání.
	 *
	 * @throws InvalidStateException
	 * @throws ValidationException
	 */
	public function reschedule(MeetingType $type, \DateTimeImmutable $scheduledAt, string $place): void
	{
		$this->assertPlanning();

		if ($type === MeetingType::Constituent && $this->body->getType() !== BodyType::Council) {
			throw new ValidationException('Ustavující zasedání má jen zastupitelstvo.', 'type');
		}

		$term = $this->body->getTerm();
		if (!$term->contains($scheduledAt)) {
			throw new ValidationException("Datum zasedání musí spadat do volebního období {$term->getName()}.", 'scheduledAt');
		}

		$place = trim($place);
		if ($place === '' || mb_strlen($place) > 200) {
			throw new ValidationException('Místo konání musí mít 1 až 200 znaků.', 'place');
		}

		$this->type = $type;
		$this->scheduledAt = $scheduledAt->setTime((int) $scheduledAt->format('G'), (int) $scheduledAt->format('i'));
		$this->place = $place;
	}

	/**
	 * Jedinečnost čísla v rámci orgánu hlídá fasáda (vyžaduje dotaz do repozitáře).
	 *
	 * @throws InvalidStateException
	 * @throws ValidationException
	 */
	public function renumber(int $number): void
	{
		$this->assertPlanning();
		if ($number < 1 || $number > 999) {
			throw new ValidationException('Pořadové číslo zasedání musí být 1 až 999.', 'number');
		}

		$this->number = $number;
	}

	/**
	 * Předsedající a ověřovatelé musí být k datu zasedání členy orgánu, zapisovatel může být kdokoli z obce
	 * (často pracovník úřadu). Po ověření zápisu už se nemění.
	 *
	 * @throws InvalidStateException
	 * @throws ValidationException
	 */
	public function assignOfficials(?Person $chairperson, ?Person $recorder, ?Person $firstVerifier, ?Person $secondVerifier): void
	{
		if (!$this->canAssignOfficials()) {
			throw new InvalidStateException('Po ověření zápisu nelze měnit předsedajícího, zapisovatele ani ověřovatele.');
		}

		$this->assertMember($chairperson, 'chairperson', 'Předsedající');
		$this->assertMember($firstVerifier, 'firstVerifier', 'Ověřovatel');
		$this->assertMember($secondVerifier, 'secondVerifier', 'Ověřovatel');
		if ($recorder !== null && $recorder->getMunicipality() !== $this->municipality) {
			throw new ValidationException('Zapisovatel patří k jiné obci.', 'recorder');
		}

		if ($firstVerifier !== null && $firstVerifier === $secondVerifier) {
			throw new ValidationException('Ověřovatelé musí být dvě různé osoby.', 'secondVerifier');
		}

		foreach ([
			'firstVerifier' => $firstVerifier,
			'secondVerifier' => $secondVerifier,
		] as $field => $verifier) {
			if ($verifier !== null && $verifier === $chairperson) {
				throw new ValidationException('Ověřovatelem nemůže být předsedající.', $field);
			}
		}

		$this->chairperson = $chairperson;
		$this->recorder = $recorder;
		$this->firstVerifier = $firstVerifier;
		$this->secondVerifier = $secondVerifier;
	}

	// --- Program ---

	/**
	 * Program lze sestavovat a měnit jen před zahájením zasedání.
	 */
	public function canEditProgram(): bool
	{
		return $this->status->isPlanning();
	}

	/**
	 * @param ?int $position pozice nového bodu (1 = první); null = na konec
	 * @throws InvalidStateException
	 * @throws ValidationException
	 */
	public function addAgendaItem(AgendaItemContent $content, ?int $position = null, ?AgendaItem $parent = null): AgendaItem
	{
		$this->assertProgramEditable();

		return $this->insertAgendaItem($content, $position, $parent);
	}

	/**
	 * Doplnění bodu na zasedání (zastupitelstvo o něm hlasovalo při schválení programu).
	 *
	 * @param ?int $position pozice nového bodu; null = na konec
	 * @throws InvalidStateException zasedání neprobíhá
	 * @throws ValidationException
	 */
	public function addAgendaItemDuringMeeting(AgendaItemContent $content, ?int $position = null, ?AgendaItem $parent = null): AgendaItem
	{
		if ($this->status !== MeetingStatus::InProgress) {
			throw new InvalidStateException('Bod lze doplnit do programu jen během zasedání.');
		}

		$item = $this->insertAgendaItem($content, $position, $parent);
		$item->markAddedDuringMeeting();

		return $item;
	}

	/**
	 * Průběh lze zapisovat během zasedání a opravovat po jeho ukončení až do ověření zápisu.
	 */
	public function canRecordProceedings(): bool
	{
		return $this->status === MeetingStatus::InProgress || $this->status === MeetingStatus::Closed;
	}

	/**
	 * Shrnutí diskuse k bodu do zápisu.
	 *
	 * @throws InvalidStateException
	 * @throws ValidationException
	 */
	public function recordDiscussion(AgendaItem $item, ?string $discussion): void
	{
		$this->assertProceedingsRecordable();
		$this->assertOwnsItem($item);
		$discussion = self::normalizeText($discussion);
		if ($discussion !== null && mb_strlen($discussion) > self::MaxDiscussionLength) {
			throw new ValidationException('Diskuse může mít nejvýše ' . self::MaxDiscussionLength . ' znaků.', 'discussion');
		}

		$item->changeDiscussion($discussion);
	}

	/**
	 * Údaje do zápisu: počet hostů a poznámka.
	 *
	 * @throws InvalidStateException
	 * @throws ValidationException
	 */
	public function recordMinutesDetails(?int $guestCount, ?string $note): void
	{
		$this->assertProceedingsRecordable();
		if ($guestCount !== null && ($guestCount < 0 || $guestCount > 10_000)) {
			throw new ValidationException('Počet hostů musí být 0 až 10 000.', 'guestCount');
		}

		$note = self::normalizeText($note);
		if ($note !== null && mb_strlen($note) > self::MaxMinutesNoteLength) {
			throw new ValidationException('Poznámka může mít nejvýše ' . self::MaxMinutesNoteLength . ' znaků.', 'minutesNote');
		}

		$this->guestCount = $guestCount;
		$this->minutesNote = $note;
	}

	/**
	 * Lhůta pro pořízení zápisu – jen u ukončeného zasedání, jehož zápis ještě není ověřen.
	 */
	public function getMinutesDeadline(): ?\DateTimeImmutable
	{
		if ($this->status !== MeetingStatus::Closed || $this->endedAt === null) {
			return null;
		}

		return $this->endedAt->setTime(0, 0)->modify('+' . self::MinutesDeadlineDays . ' days');
	}

	public function getGuestCount(): ?int
	{
		return $this->guestCount;
	}

	public function getMinutesNote(): ?string
	{
		return $this->minutesNote;
	}

	/**
	 * @throws InvalidStateException
	 * @throws ValidationException
	 */
	public function updateAgendaItem(AgendaItem $item, AgendaItemContent $content): void
	{
		$this->assertProgramEditable();
		$this->assertOwnsItem($item);
		$this->assertRapporteur($content);
		$item->changeContent($content);
	}

	/**
	 * @throws InvalidStateException
	 */
	public function removeAgendaItem(AgendaItem $item): void
	{
		$this->assertProgramEditable();
		$this->assertOwnsItem($item);
		foreach ($this->getSubItems($item) as $subItem) {
			$this->agendaItems->removeElement($subItem);
		}

		$this->agendaItems->removeElement($item);
		$this->renumberAgenda($this->getAgendaItems());
	}

	/**
	 * Z bodu udělá podbod jiného bodu (nebo naopak hlavní bod, $parent = null).
	 *
	 * @throws InvalidStateException
	 * @throws ValidationException
	 */
	public function changeAgendaItemParent(AgendaItem $item, ?AgendaItem $parent): void
	{
		$this->assertProgramEditable();
		$this->assertOwnsItem($item);
		if ($parent === $item->getParent()) {
			return;
		}

		if ($parent !== null) {
			$this->assertValidParent($parent, $item);
			if ($this->getSubItems($item) !== []) {
				throw new ValidationException('Bod s podbody nemůže být podbodem jiného bodu.', 'parent');
			}
		}

		$oldParent = $item->getParent();
		$item->changeParent($parent);
		// nový podbod na konec bloku svého bodu, z podbodu nový hlavní bod hned za blok původního bodu
		$items = array_values(array_filter($this->getAgendaItems(), static fn(AgendaItem $i): bool => $i !== $item));
		$index = $parent === null && $oldParent !== null ? array_search($oldParent, $items, true) : false;
		if (is_int($index)) {
			array_splice($items, $index + 1, 0, [$item]);
		} else {
			$items[] = $item;
		}

		$this->renumberAgenda($items);
	}

	/**
	 * @return list<AgendaItem> podbody bodu v pořadí
	 */
	public function getSubItems(AgendaItem $parent): array
	{
		return array_values(array_filter($this->getAgendaItems(), static fn(AgendaItem $item): bool => $item->getParent() === $parent));
	}

	/**
	 * @throws InvalidStateException
	 */
	/**
	 * Přesun mezi sourozenci: hlavní bod mezi hlavními body (i s podbody), podbod v rámci svého bodu.
	 *
	 * @param int $position nové pořadí mezi sourozenci (1 = první)
	 * @throws InvalidStateException
	 */
	public function moveAgendaItem(AgendaItem $item, int $position): void
	{
		$this->assertProgramEditable();
		$this->assertOwnsItem($item);

		$siblings = array_values(array_filter(
			$this->getAgendaItems(),
			static fn(AgendaItem $i): bool => $i !== $item && $i->getParent() === $item->getParent(),
		));
		$position = max(1, min($position, count($siblings) + 1));
		array_splice($siblings, $position - 1, 0, [$item]);
		$others = array_values(array_filter($this->getAgendaItems(), static fn(AgendaItem $i): bool => $i->getParent() !== $item->getParent()));
		$this->renumberAgenda([...$siblings, ...$others]);
	}

	/**
	 * Nové pořadí celého programu (přetažení myší). Musí obsahovat právě všechny body.
	 *
	 * @param list<int> $itemIds
	 * @throws InvalidStateException
	 * @throws ValidationException
	 */
	public function reorderAgenda(array $itemIds): void
	{
		$this->assertProgramEditable();

		$byId = [];
		foreach ($this->agendaItems as $item) {
			$byId[$item->getId()] = $item;
		}

		if (count($itemIds) !== count($byId) || count(array_unique($itemIds)) !== count($itemIds) || array_diff($itemIds, array_keys($byId)) !== []) {
			throw new ValidationException('Pořadí bodů neodpovídá programu. Načtěte stránku znovu.');
		}

		$this->renumberAgenda(array_map(static fn(int $id): AgendaItem => $byId[$id], $itemIds));
	}

	/**
	 * @return list<AgendaItem> seřazené podle pořadí
	 */
	public function getAgendaItems(): array
	{
		$items = $this->agendaItems->toArray();
		usort($items, static fn(AgendaItem $a, AgendaItem $b): int => $a->getPosition() <=> $b->getPosition());

		return $items;
	}

	public function findAgendaItem(int $itemId): ?AgendaItem
	{
		foreach ($this->agendaItems as $item) {
			if ($item->isPersisted() && $item->getId() === $itemId) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * Provede přechod stavového automatu.
	 *
	 * @throws InvalidStateException přechod není z aktuálního stavu povolený
	 */
	public function transition(MeetingTransition $transition, ClockInterface $clock): void
	{
		match ($transition) {
			MeetingTransition::Share => $this->share($clock),
			MeetingTransition::Start => $this->start($clock),
			MeetingTransition::Close => $this->close($clock),
			MeetingTransition::ApproveMinutes => $this->approveMinutes(),
			MeetingTransition::Reopen, MeetingTransition::ReopenMinutes => throw new InvalidStateException('Znovuotevření vyžaduje důvod.'),
		};
	}

	/**
	 * Draft → Shared: podklady jsou k dispozici členům orgánu.
	 */
	public function share(ClockInterface $clock): void
	{
		$this->move(MeetingTransition::Share);
		$this->sharedAt = $clock->now();
	}

	/**
	 * Shared → InProgress.
	 */
	public function start(ClockInterface $clock): void
	{
		$this->move(MeetingTransition::Start);
		$this->startedAt = $clock->now();
	}

	/**
	 * InProgress → Closed.
	 */
	public function close(ClockInterface $clock): void
	{
		$this->move(MeetingTransition::Close);
		$this->endedAt = $clock->now();
	}

	/**
	 * Closed → MinutesApproved: zápis podepsali předsedající a ověřovatelé. Zasedání je tím uzavřené.
	 */
	public function approveMinutes(): void
	{
		$this->move(MeetingTransition::ApproveMinutes);
	}

	/**
	 * Znovuotevření s důvodem, který zůstane v historii: ukončené zasedání → probíhá (oprava průběhu),
	 * ověřený zápis → ukončené (zrušení ověření, zápis jde opravit a znovu ověřit).
	 * Smí jen správce obce (kontroluje presenter přes Privilege::Manage).
	 *
	 * @throws InvalidStateException
	 * @throws ValidationException chybí důvod
	 */
	public function reopen(string $reason, ?User $by, ClockInterface $clock): MeetingReopening
	{
		$transition = match ($this->status) {
			MeetingStatus::Closed => MeetingTransition::Reopen,
			MeetingStatus::MinutesApproved => MeetingTransition::ReopenMinutes,
			default => throw new InvalidStateException("Znovu otevřít lze jen ukončené zasedání nebo zasedání s ověřeným zápisem (teď je ve stavu „{$this->status->label()}“)."),
		};

		$reopening = new MeetingReopening($this, $this->status, $transition->target(), $reason, $by, $clock->now());
		$this->move($transition);
		if ($transition === MeetingTransition::Reopen) {
			$this->endedAt = null;
		}

		$this->reopenings->add($reopening);

		return $reopening;
	}

	/**
	 * @return list<MeetingReopening> od nejstaršího
	 */
	public function getReopenings(): array
	{
		return array_values($this->reopenings->toArray());
	}

	/**
	 * Zápis je ověřen: zasedání je uzavřené a archivované, nic z jeho průběhu ani podkladů se nemění.
	 */
	public function isArchived(): bool
	{
		return $this->status === MeetingStatus::MinutesApproved;
	}

	/**
	 * Oprava skutečného času zahájení a ukončení (zpětné zadání zasedání, zapomenuté kliknutí).
	 *
	 * @throws InvalidStateException
	 * @throws ValidationException
	 */
	public function correctTimes(\DateTimeImmutable $startedAt, ?\DateTimeImmutable $endedAt, ClockInterface $clock): void
	{
		if ($this->status !== MeetingStatus::InProgress && $this->status !== MeetingStatus::Closed) {
			throw new InvalidStateException('Čas zahájení a ukončení lze opravit jen u probíhajícího nebo ukončeného zasedání.');
		}

		$now = $clock->now();
		if ($startedAt > $now) {
			throw new ValidationException('Zasedání nemohlo být zahájeno v budoucnosti.', 'startedAt');
		}

		if ($this->status === MeetingStatus::Closed && $endedAt === null) {
			throw new ValidationException('Zadejte čas ukončení zasedání.', 'endedAt');
		}

		if ($this->status === MeetingStatus::InProgress && $endedAt !== null) {
			throw new ValidationException('Probíhající zasedání nemá čas ukončení.', 'endedAt');
		}

		if ($endedAt !== null && ($endedAt < $startedAt || $endedAt > $now)) {
			throw new ValidationException('Čas ukončení musí být po zahájení a nesmí být v budoucnosti.', 'endedAt');
		}

		$this->startedAt = $startedAt;
		$this->endedAt = $endedAt;
	}

	/**
	 * Zastupitelstvo sdílí zasedání tak pozdě, že informaci na úřední desce už nelze vyvěsit 7 dní předem.
	 * U zpětně zadaných (proběhlých) zasedání a u ostatních orgánů lhůta neplatí.
	 */
	public function violatesNoticePeriod(\DateTimeImmutable $now): bool
	{
		if ($this->body->getType() !== BodyType::Council) {
			return false;
		}

		$days = $this->daysUntil($now);

		return $days >= 0 && $days < self::NoticePeriodDays;
	}

	/**
	 * Počet kalendářních dní od $now do dne zasedání (záporný = zasedání už bylo).
	 */
	public function daysUntil(\DateTimeImmutable $now): int
	{
		$today = new \DateTimeImmutable($now->format('Y-m-d'));
		$day = new \DateTimeImmutable($this->scheduledAt->format('Y-m-d'));

		return (int) $today->diff($day)->format('%r%a');
	}

	/**
	 * @return list<MeetingTransition>
	 */
	public function getAvailableTransitions(): array
	{
		return array_values(array_filter(
			MeetingTransition::cases(),
			fn(MeetingTransition $transition): bool => $transition->source() === $this->status,
		));
	}

	public function canReschedule(): bool
	{
		return $this->status->isPlanning();
	}

	/**
	 * Podklady lze doplňovat i během zasedání; po ověření zápisu je zasedání uzavřené.
	 */
	public function canEditAttachments(): bool
	{
		return $this->status !== MeetingStatus::MinutesApproved;
	}

	public function canAssignOfficials(): bool
	{
		return $this->status !== MeetingStatus::MinutesApproved;
	}

	/**
	 * Smazat lze jen zasedání v přípravě (omylem založené). Proběhlé zasedání je součást historie.
	 *
	 * @throws InvalidStateException
	 */
	public function assertDeletable(): void
	{
		if ($this->status !== MeetingStatus::Draft) {
			throw new InvalidStateException('Smazat lze jen zasedání v přípravě.');
		}
	}

	/**
	 * Např. „3. zasedání“, „1. ustavující zasedání“, „5. mimořádná schůze“.
	 */
	public function getTitle(): string
	{
		$bodyType = $this->body->getType();
		$noun = $this->getKindNoun();
		$adjective = match ($this->type) {
			MeetingType::Regular => '',
			MeetingType::Extraordinary => $bodyType === BodyType::Board ? 'mimořádná ' : 'mimořádné ',
			MeetingType::Constituent => 'ustavující ',
		};

		return "{$this->number}. {$adjective}{$noun}";
	}

	/**
	 * Jak se jednání orgánu říká: zasedání (zastupitelstvo), schůze (rada), jednání (výbor).
	 */
	public function getKindNoun(): string
	{
		return match ($this->body->getType()) {
			BodyType::Council => 'zasedání',
			BodyType::Board => 'schůze',
			BodyType::Committee => 'jednání',
		};
	}

	public function getMunicipality(): Municipality
	{
		return $this->municipality;
	}

	public function getBody(): Body
	{
		return $this->body;
	}

	public function getNumber(): int
	{
		return $this->number;
	}

	public function getType(): MeetingType
	{
		return $this->type;
	}

	public function getScheduledAt(): \DateTimeImmutable
	{
		return $this->scheduledAt;
	}

	public function getPlace(): string
	{
		return $this->place;
	}

	public function getStatus(): MeetingStatus
	{
		return $this->status;
	}

	public function getChairperson(): ?Person
	{
		return $this->chairperson;
	}

	public function getRecorder(): ?Person
	{
		return $this->recorder;
	}

	public function getFirstVerifier(): ?Person
	{
		return $this->firstVerifier;
	}

	public function getSecondVerifier(): ?Person
	{
		return $this->secondVerifier;
	}

	/**
	 * @return list<Person>
	 */
	public function getVerifiers(): array
	{
		return array_values(array_filter([$this->firstVerifier, $this->secondVerifier]));
	}

	public function getSharedAt(): ?\DateTimeImmutable
	{
		return $this->sharedAt;
	}

	public function getStartedAt(): ?\DateTimeImmutable
	{
		return $this->startedAt;
	}

	public function getEndedAt(): ?\DateTimeImmutable
	{
		return $this->endedAt;
	}

	private function insertAgendaItem(AgendaItemContent $content, ?int $position, ?AgendaItem $parent = null): AgendaItem
	{
		$this->assertRapporteur($content);
		if ($parent !== null) {
			$this->assertValidParent($parent);
		}

		$items = $this->getAgendaItems();
		if ($parent !== null) {
			// podbod na konec bloku svého bodu
			$subItems = $this->getSubItems($parent);
			$position = ($subItems === [] ? $parent : $subItems[count($subItems) - 1])->getPosition() + 1;
		}

		$position = $position === null ? count($items) + 1 : max(1, min($position, count($items) + 1));
		$item = new AgendaItem($this, $position, $content, $parent);
		array_splice($items, $position - 1, 0, [$item]);
		$this->agendaItems->add($item);
		$this->renumberAgenda($items);

		return $item;
	}

	/**
	 * @throws ValidationException
	 */
	private function assertValidParent(AgendaItem $parent, ?AgendaItem $child = null): void
	{
		$this->assertOwnsItem($parent);
		if ($parent === $child || $parent->getParent() !== null) {
			throw new ValidationException('Podbod lze vložit jen pod hlavní bod programu (podbody nemají další podbody).', 'parent');
		}
	}

	private function assertProceedingsRecordable(): void
	{
		if (!$this->canRecordProceedings()) {
			throw new InvalidStateException($this->status === MeetingStatus::MinutesApproved
				? 'Zápis je ověřen, průběh zasedání už nelze měnit.'
				: 'Průběh lze zapisovat až po zahájení zasedání.');
		}
	}

	private static function normalizeText(?string $text): ?string
	{
		$text = $text === null ? null : trim(str_replace(["\r\n", "\r"], "\n", $text));

		return $text === '' ? null : $text;
	}

	/**
	 * Očísluje program: hlavní body v pořadí, jak přišly, a za každým jeho podbody (v jejich pořadí).
	 * Podbod tak nikdy neutrhne od svého bodu, ať se seznam přeskládá jakkoli.
	 *
	 * @param list<AgendaItem> $items v požadovaném pořadí
	 */
	private function renumberAgenda(array $items): void
	{
		$ordered = [];
		foreach ($items as $top) {
			if ($top->getParent() !== null) {
				continue;
			}

			$ordered[] = $top;
			foreach ($items as $item) {
				if ($item->getParent() === $top) {
					$ordered[] = $item;
				}
			}
		}

		foreach ($ordered as $index => $item) {
			if ($item->getPosition() !== $index + 1) {
				$item->moveTo($index + 1);
			}
		}
	}

	private function assertProgramEditable(): void
	{
		if (!$this->canEditProgram()) {
			throw new InvalidStateException('Program lze měnit jen před zahájením zasedání.');
		}
	}

	private function assertOwnsItem(AgendaItem $item): void
	{
		if ($item->getMeeting() !== $this) {
			throw new InvalidStateException('Bod nepatří k tomuto zasedání.');
		}
	}

	private function assertRapporteur(AgendaItemContent $content): void
	{
		if ($content->rapporteur !== null && $content->rapporteur->getMunicipality() !== $this->municipality) {
			throw new ValidationException('Předkladatel patří k jiné obci.', 'rapporteur');
		}
	}

	private function move(MeetingTransition $transition): void
	{
		if ($this->status !== $transition->source()) {
			throw new InvalidStateException("Akci „{$transition->label()}“ nelze provést – zasedání je ve stavu „{$this->status->label()}“.");
		}

		$this->status = $transition->target();
	}

	private function assertPlanning(): void
	{
		if (!$this->status->isPlanning()) {
			throw new InvalidStateException('Typ, datum, místo a číslo lze měnit jen před zahájením zasedání.');
		}
	}

	private function assertMember(?Person $person, string $field, string $role): void
	{
		if ($person === null) {
			return;
		}

		if ($this->body->findMembershipOf($person, $this->scheduledAt) === null) {
			throw new ValidationException("{$role} musí být k datu zasedání členem orgánu ({$person->getFullName()} není).", $field);
		}
	}
}
