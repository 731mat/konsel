<?php

declare(strict_types=1);

namespace App\Model\Audit;

use App\Model\Attachment\Attachment;
use App\Model\Document\ArchivedDocument;
use App\Model\Meeting\AgendaItem;
use App\Model\Meeting\Attendance;
use App\Model\Meeting\Meeting;
use App\Model\Meeting\MeetingReopening;
use App\Model\Meeting\Vote;
use App\Model\Meeting\VotingRound;
use App\Model\Municipality\Municipality;
use App\Model\People\Body;
use App\Model\People\Membership;
use App\Model\People\Person;
use App\Model\Recording\Recording;
use App\Model\Resolution\Resolution;
use App\Model\Resolution\ResolutionTask;
use App\Model\Shared\RichText\RichText;
use App\Model\Shared\ValueObject\StoredFile;
use App\Model\User\User;

/**
 * Co se v historii sleduje a jak se to zobrazí: druh, čitelný popis, zasedání, obec a hodnoty polí.
 */
final class AuditDescriber
{
	/**
	 * Pole, která se nezapisují: technická, šumová (přečíslování) a citlivá.
	 */
	private const array IgnoredFields = [
		'version', 'createdAt', 'updatedAt', 'position', 'lastLoginAt', 'municipality', 'meeting', 'votes', 'agendaItems', 'reopenings', 'attachments',
		// kontext je už v popisu záznamu
		'votingRound', 'agendaItem', 'body', 'resolution', 'scopeKey', 'meetingStatus', 'uploadedBy', 'createdBy', 'reopenedBy', 'reopenedByName', 'comment',
	];

	private const string SensitivePattern = '~password|token|secret|hash|encrypted|apikey~i';

	private const int MaxValueLength = 300;

	public static function type(object $entity): ?AuditEntityType
	{
		return match (true) {
			$entity instanceof Meeting => AuditEntityType::Meeting,
			$entity instanceof AgendaItem => AuditEntityType::AgendaItem,
			$entity instanceof VotingRound => AuditEntityType::VotingRound,
			$entity instanceof Vote => AuditEntityType::Vote,
			$entity instanceof Resolution => AuditEntityType::Resolution,
			$entity instanceof Attendance => AuditEntityType::Attendance,
			$entity instanceof Membership => AuditEntityType::Membership,
			$entity instanceof User => AuditEntityType::User,
			$entity instanceof ResolutionTask => AuditEntityType::ResolutionTask,
			$entity instanceof Attachment => AuditEntityType::Attachment,
			$entity instanceof Recording => AuditEntityType::Recording,
			$entity instanceof ArchivedDocument => AuditEntityType::ArchivedDocument,
			$entity instanceof MeetingReopening => AuditEntityType::MeetingReopening,
			default => null,
		};
	}

	public static function municipality(object $entity): ?Municipality
	{
		return match (true) {
			$entity instanceof User => $entity->getMunicipality(),
			$entity instanceof Vote => $entity->getRound()->getMunicipality(),
			$entity instanceof Membership => $entity->getMunicipality(),
			method_exists($entity, 'getMunicipality') => ($m = $entity->getMunicipality()) instanceof Municipality ? $m : null,
			default => null,
		};
	}

	public static function meetingId(object $entity): ?int
	{
		$meeting = match (true) {
			$entity instanceof Meeting => $entity,
			$entity instanceof AgendaItem, $entity instanceof VotingRound, $entity instanceof Resolution, $entity instanceof Attendance,
			$entity instanceof Recording, $entity instanceof ArchivedDocument, $entity instanceof MeetingReopening => $entity->getMeeting(),
			$entity instanceof Vote => $entity->getRound()->getMeeting(),
			$entity instanceof ResolutionTask => $entity->getResolution()->getMeeting(),
			$entity instanceof Attachment => $entity->getMeeting(),
			default => null,
		};

		return $meeting?->isPersisted() === true ? $meeting->getId() : null;
	}

	/**
	 * Nadřazený záznam pro dohledání: hlas → hlasování, úkol → usnesení.
	 */
	public static function relatedId(object $entity): ?int
	{
		$related = match (true) {
			$entity instanceof Vote => $entity->getRound(),
			$entity instanceof ResolutionTask => $entity->getResolution(),
			default => null,
		};

		return $related?->isPersisted() === true ? $related->getId() : null;
	}

	/**
	 * Čitelný popis, např. „Hlas: Petr Novotný (hlasování č. 1, bod „Rozpočet“)“.
	 */
	public static function label(object $entity): string
	{
		$label = match (true) {
			$entity instanceof Meeting => "{$entity->getTitle()} – {$entity->getBody()->getName()}",
			$entity instanceof AgendaItem => "Bod „{$entity->getTitle()}“",
			$entity instanceof VotingRound => "Hlasování č. {$entity->getSequence()} k bodu „{$entity->getAgendaItem()->getTitle()}“",
			$entity instanceof Vote => "Hlas: {$entity->getPerson()->getFullName()} (hlasování č. {$entity->getRound()->getSequence()}, bod „{$entity->getRound()->getAgendaItem()->getTitle()}“)",
			$entity instanceof Resolution => "Usnesení č. {$entity->getNumber()}",
			$entity instanceof Attendance => "Docházka: {$entity->getPerson()->getFullName()}",
			$entity instanceof Membership => "Členství: {$entity->getPerson()->getFullName()} – {$entity->getBody()->getName()}",
			$entity instanceof User => "Uživatel {$entity->getName()}",
			$entity instanceof ResolutionTask => "Úkol k usnesení č. {$entity->getResolution()->getNumber()}: {$entity->getDescription()}",
			$entity instanceof Attachment => "Příloha „{$entity->getTitle()}“",
			$entity instanceof Recording => "Záznam „{$entity->getTitle()}“",
			$entity instanceof ArchivedDocument => "Archiv: {$entity->getType()->label()} ({$entity->getOrigin()->label()})",
			$entity instanceof MeetingReopening => "Znovuotevření: {$entity->getFromStatus()->label()} → {$entity->getToStatus()->label()}",
			default => $entity::class,
		};

		return mb_substr($label, 0, 255);
	}

	/**
	 * @param array<string, mixed> $changeSet změny z UnitOfWork (u kolekcí PersistentCollection – ty se přeskočí)
	 * @param array<string, class-string<\BackedEnum>> $enumTypes pole s číselníkem (UnitOfWork může vrátit surovou hodnotu)
	 * @return array<string, array{?string, ?string}>
	 */
	public static function changes(array $changeSet, array $enumTypes = []): array
	{
		$changes = [];
		foreach ($changeSet as $field => $pair) {
			if (!is_array($pair) || !array_key_exists(0, $pair) || !array_key_exists(1, $pair) || in_array($field, self::IgnoredFields, true) || preg_match(self::SensitivePattern, $field) === 1) {
				continue;
			}

			[$old, $new] = $pair;

			if (isset($enumTypes[$field])) {
				$old = is_int($old) || is_string($old) ? $enumTypes[$field]::tryFrom($old) : $old;
				$new = is_int($new) || is_string($new) ? $enumTypes[$field]::tryFrom($new) : $new;
			}

			$before = self::format($old);
			$after = self::format($new);
			if ($before !== $after) {
				$changes[$field] = [$before, $after];
			}
		}

		return $changes;
	}

	/**
	 * Hodnota pro člověka: osoba jménem, číselník popiskem, datum česky.
	 */
	public static function format(mixed $value): ?string
	{
		$text = match (true) {
			$value === null => null,
			is_bool($value) => $value ? 'ano' : 'ne',
			is_int($value), is_float($value) => (string) $value,
			is_string($value) => trim($value) === '' ? null : trim($value),
			$value instanceof \DateTimeInterface => $value->format('j. n. Y H:i'),
			$value instanceof \BackedEnum => method_exists($value, 'label') && is_string($label = $value->label()) ? $label : (string) $value->value,
			$value instanceof Person => $value->getFullName(),
			$value instanceof User => $value->getName(),
			$value instanceof Body, $value instanceof Municipality => $value->getName(),
			$value instanceof AgendaItem => "bod „{$value->getTitle()}“",
			$value instanceof StoredFile => $value->originalName,
			$value instanceof RichText => $value->toPlainText(),
			is_array($value) => (string) json_encode($value, JSON_UNESCAPED_UNICODE),
			is_object($value) && method_exists($value, 'getTitle') && is_string($title = $value->getTitle()) => $title,
			is_object($value) && method_exists($value, 'isPersisted') && $value->isPersisted() === true && method_exists($value, 'getId') && is_int($id = $value->getId()) => "#{$id}",
			default => null,
		};

		return $text === null || mb_strlen($text) <= self::MaxValueLength ? $text : mb_substr($text, 0, self::MaxValueLength) . '…';
	}

	/**
	 * Popisek pole v historii.
	 */
	public static function fieldLabel(string $field): string
	{
		return match ($field) {
			'title' => 'Název',
			'description' => 'Důvodová zpráva / popis',
			'proposedResolution' => 'Návrh usnesení',
			'discussion' => 'Diskuse',
			'rapporteur' => 'Předkladatel',
			'parent' => 'Nadřazený bod',
			'project' => 'Plán',
			'addedDuringMeeting' => 'Doplněno na zasedání',
			'choice' => 'Hlas',
			'person' => 'Osoba',
			'status' => 'Stav',
			'text' => 'Znění',
			'statusNote' => 'Poznámka ke stavu',
			'number' => 'Číslo',
			'subNumber' => 'Podbod',
			'sequence' => 'Pořadí',
			'type' => 'Typ',
			'scheduledAt' => 'Termín',
			'place' => 'Místo',
			'chairperson' => 'Předsedající',
			'recorder' => 'Zapisovatel',
			'firstVerifier', 'secondVerifier' => 'Ověřovatel',
			'sharedAt' => 'Sdíleno',
			'startedAt' => 'Zahájeno',
			'endedAt' => 'Ukončeno',
			'guestCount' => 'Počet hostů',
			'minutesNote' => 'Poznámka do zápisu',
			'arrivedAt' => 'Příchod',
			'leftAt' => 'Odchod',
			'note' => 'Poznámka',
			'function' => 'Funkce',
			'validFrom' => 'Od',
			'validTo' => 'Do',
			'role' => 'Role',
			'active' => 'Aktivní',
			'mustChangePassword' => 'Musí změnit heslo',
			'email' => 'E-mail',
			'name' => 'Jméno',
			'assignee' => 'Kdo',
			'dueDate' => 'Termín',
			'completedAt' => 'Splněno',
			'publicForMembers' => 'Pro zastupitele',
			'file' => 'Soubor',
			'proposalText' => 'Znění návrhu',
			'candidate' => 'Kandidát',
			'result' => 'Výsledek',
			'openedAt' => 'Zahájeno',
			'closedAt' => 'Ukončeno',
			'reason' => 'Důvod',
			'fromStatus' => 'Z',
			'toStatus' => 'Na',
			'origin' => 'Původ',
			'forCount' => 'Pro',
			'againstCount' => 'Proti',
			'abstainCount' => 'Zdrželo se',
			'notVotingCount' => 'Nehlasovalo',
			'membersCount' => 'Počet členů',
			'adoptedAt' => 'Přijato',
			'seatCount' => 'Počet míst',
			default => $field,
		};
	}
}
