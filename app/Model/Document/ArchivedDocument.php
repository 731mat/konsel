<?php

declare(strict_types=1);

namespace App\Model\Document;

use App\Model\Meeting\Meeting;
use App\Model\Meeting\MeetingStatus;
use App\Model\Municipality\Municipality;
use App\Model\Shared\Exception\InvalidStateException;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\IntegerId;
use App\Model\Shared\TenantOwned;
use App\Model\Shared\Timestampable;
use App\Model\Shared\TimestampableTrait;
use App\Model\Shared\ValueObject\StoredFile;
use App\Model\User\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * Verze dokumentu zasedání uložená v archivu: vygenerované PDF v určitém okamžiku (např. vyvěšená pozvánka,
 * zápis po ověření), nebo naskenovaný podepsaný originál. Obsah se už nemění.
 */
#[ORM\Entity]
#[ORM\Table(name: 'archived_document')]
#[ORM\Index(name: 'archived_document_meeting_idx', columns: ['meeting_id', 'type'])]
final class ArchivedDocument implements TenantOwned, Timestampable
{
	use IntegerId;
	use TimestampableTrait;

	public const int MaxNoteLength = 255;

	#[ORM\ManyToOne(targetEntity: Municipality::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private Municipality $municipality;

	#[ORM\ManyToOne(targetEntity: Meeting::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private Meeting $meeting;

	#[ORM\Column(length: 20, enumType: MeetingDocumentType::class)]
	private MeetingDocumentType $type;

	#[ORM\Column(length: 20, enumType: ArchivedDocumentOrigin::class)]
	private ArchivedDocumentOrigin $origin;

	#[ORM\Column(type: 'stored_file')]
	private StoredFile $file;

	#[ORM\Column(length: self::MaxNoteLength, nullable: true)]
	private ?string $note;

	#[ORM\ManyToOne(targetEntity: User::class)]
	#[ORM\JoinColumn(onDelete: 'SET NULL')]
	private ?User $createdBy;

	/**
	 * Stav zasedání v okamžiku uložení (koncept během zasedání × konečná podoba).
	 */
	#[ORM\Column(length: 20, enumType: MeetingStatus::class)]
	private MeetingStatus $meetingStatus;

	/**
	 * @throws ValidationException
	 * @throws InvalidStateException
	 */
	public function __construct(Meeting $meeting, MeetingDocumentType $type, ArchivedDocumentOrigin $origin, StoredFile $file, ?User $createdBy, ?string $note = null)
	{
		if (!$type->isAvailableFor($meeting->getStatus())) {
			throw new InvalidStateException("Dokument „{$type->label()}“ v tomto stavu zasedání ještě nevzniká.");
		}

		if ($origin === ArchivedDocumentOrigin::Final && $meeting->getStatus() !== MeetingStatus::MinutesApproved) {
			throw new InvalidStateException('Konečná verze vzniká až po ověření zápisu.');
		}

		$this->municipality = $meeting->getMunicipality();
		$this->meeting = $meeting;
		$this->type = $type;
		$this->origin = $origin;
		$this->file = $file;
		$this->createdBy = $createdBy;
		$this->meetingStatus = $meeting->getStatus();
		$this->note = self::normalizeNote($note);
	}

	/**
	 * Smazat lze jen pracovní verzi zasedání, které ještě není uzavřené ověřeným zápisem. Konečné verze
	 * a vše uložené po ověření zůstávají i po případném znovuotevření (archiv je jen pro přidávání).
	 *
	 * @throws InvalidStateException
	 */
	public function assertDeletable(): void
	{
		if ($this->meeting->isArchived() || $this->origin === ArchivedDocumentOrigin::Final || $this->meetingStatus === MeetingStatus::MinutesApproved) {
			throw new InvalidStateException('Zápis je ověřen, archiv zasedání už nelze měnit.');
		}
	}

	public function getMunicipality(): Municipality
	{
		return $this->municipality;
	}

	public function getMeeting(): Meeting
	{
		return $this->meeting;
	}

	public function getType(): MeetingDocumentType
	{
		return $this->type;
	}

	public function getOrigin(): ArchivedDocumentOrigin
	{
		return $this->origin;
	}

	public function getFile(): StoredFile
	{
		return $this->file;
	}

	public function getNote(): ?string
	{
		return $this->note;
	}

	public function getCreatedBy(): ?User
	{
		return $this->createdBy;
	}

	public function getMeetingStatus(): MeetingStatus
	{
		return $this->meetingStatus;
	}

	/**
	 * Uloženo během zasedání – obsah byl koncept.
	 */
	public function isDraft(): bool
	{
		return $this->meetingStatus === MeetingStatus::InProgress && in_array($this->type, [MeetingDocumentType::Minutes, MeetingDocumentType::Resolutions], true);
	}

	private static function normalizeNote(?string $note): ?string
	{
		$note = $note === null ? null : trim(preg_replace('~\s+~u', ' ', $note) ?? '');
		if ($note !== null && mb_strlen($note) > self::MaxNoteLength) {
			throw new ValidationException(sprintf('Poznámka může mít nejvýše %d znaků.', self::MaxNoteLength), 'note');
		}

		return $note === '' ? null : $note;
	}
}
