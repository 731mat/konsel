<?php

declare(strict_types=1);

namespace App\Model\Document;

use App\Model\Meeting\Meeting;
use App\Model\Meeting\MeetingRepository;
use App\Model\Meeting\MeetingStatus;
use App\Model\Shared\Exception\InvalidStateException;
use App\Model\Shared\Exception\NotFoundException;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\TransactionManager;
use App\Model\Shared\ValueObject\StoredFile;
use App\Model\Storage\FileStorage;
use App\Model\Storage\UploadedFile;
use App\Model\User\UserRepository;

/**
 * Archiv dokumentů zasedání: uložené verze PDF a podepsané originály. Uložený soubor se už nemění,
 * po ověření zápisu nejde ani smazat (archiv je jen pro přidávání).
 */
final readonly class DocumentArchiveFacade
{
	public const int MaxUploadSize = 64 * 1024 * 1024;

	/**
	 * Dokumenty, které se archivují automaticky při ověření zápisu.
	 */
	public const array FinalTypes = [MeetingDocumentType::Minutes, MeetingDocumentType::Resolutions];

	public function __construct(
		private ArchivedDocumentRepository $documents,
		private MeetingRepository $meetings,
		private UserRepository $users,
		private MeetingDocumentFacade $renderer,
		private FileStorage $storage,
		private TransactionManager $transaction,
	) {}

	/**
	 * @throws NotFoundException
	 */
	public function get(int $id): ArchivedDocument
	{
		return $this->documents->get($id);
	}

	/**
	 * @return list<ArchivedDocument> od nejnovější
	 */
	public function findByMeeting(int $meetingId): array
	{
		return $this->documents->findByMeeting($this->meetings->get($meetingId));
	}

	/**
	 * Archiv podle let a orgánů: proběhlá zasedání (ukončená a s ověřeným zápisem) a jejich konečné dokumenty.
	 *
	 * @param list<Meeting> $meetings
	 * @return list<ArchiveEntry>
	 */
	public function overview(array $meetings): array
	{
		$finished = array_values(array_filter($meetings, static fn(Meeting $meeting): bool => in_array($meeting->getStatus(), [MeetingStatus::Closed, MeetingStatus::MinutesApproved], true)));
		$documents = [];
		foreach ($this->documents->findByMeetings($finished) as $document) {
			$documents[$document->getMeeting()->getId()][] = $document;
		}

		return array_map(static fn(Meeting $meeting): ArchiveEntry => new ArchiveEntry($meeting, $documents[$meeting->getId()] ?? []), $finished);
	}

	/**
	 * Uloží aktuální podobu dokumentu jako PDF (např. pozvánku v okamžiku vyvěšení).
	 */
	public function archive(int $meetingId, MeetingDocumentType $type, ?int $userId, ?string $note = null, bool $namedVotes = false): ArchivedDocument
	{
		return $this->archiveRendered($meetingId, $type, ArchivedDocumentOrigin::Generated, $userId, $note, $namedVotes);
	}

	/**
	 * Konečné verze zápisu a usnesení po ověření zápisu.
	 *
	 * @return list<ArchivedDocument>
	 */
	public function archiveFinal(int $meetingId, ?int $userId): array
	{
		return array_map(
			fn(MeetingDocumentType $type): ArchivedDocument => $this->archiveRendered($meetingId, $type, ArchivedDocumentOrigin::Final, $userId, null, false),
			self::FinalTypes,
		);
	}

	/**
	 * Naskenovaný podepsaný originál (jen PDF – typ se ověří podle obsahu souboru).
	 *
	 * @throws ValidationException
	 */
	public function upload(int $meetingId, MeetingDocumentType $type, UploadedFile $file, int $size, ?int $userId, ?string $note = null): ArchivedDocument
	{
		if (strtolower(pathinfo($file->originalName, PATHINFO_EXTENSION)) !== 'pdf') {
			throw new ValidationException('Podepsaný originál nahrajte jako PDF (sken).', 'file');
		}

		if ($size <= 0 || $size > self::MaxUploadSize) {
			throw new ValidationException(sprintf('Soubor musí mít 1 B až %d MB.', intdiv(self::MaxUploadSize, 1024 * 1024)), 'file');
		}

		$meeting = $this->meetings->get($meetingId);
		$stored = $this->storage->store($file->stream, $file->originalName, $this->namespace($meeting->getMunicipality()->getId()));
		if (!$stored->isPdf()) {
			$this->storage->delete($stored);

			throw new ValidationException('Soubor není PDF.', 'file');
		}

		return $this->persist($meetingId, $type, ArchivedDocumentOrigin::Uploaded, $stored, $userId, $note);
	}

	/**
	 * @throws InvalidStateException archiv ověřeného zasedání je neměnný
	 */
	public function delete(int $id): void
	{
		$file = $this->transaction->run(function () use ($id): StoredFile {
			$document = $this->documents->get($id);
			$document->assertDeletable();
			$this->documents->remove($document);

			return $document->getFile();
		});

		$this->storage->delete($file);
	}

	/**
	 * Ověřené zasedání má v archivu konečný zápis (upozornění, když automatické uložení selhalo).
	 */
	public function hasFinalVersion(int $meetingId): bool
	{
		$meeting = $this->meetings->get($meetingId);
		if ($meeting->getStatus() !== MeetingStatus::MinutesApproved) {
			return false;
		}

		foreach ($this->documents->findByMeeting($meeting) as $document) {
			if ($document->getOrigin() === ArchivedDocumentOrigin::Final && $document->getType() === MeetingDocumentType::Minutes) {
				return true;
			}
		}

		return false;
	}

	private function archiveRendered(int $meetingId, MeetingDocumentType $type, ArchivedDocumentOrigin $origin, ?int $userId, ?string $note, bool $namedVotes): ArchivedDocument
	{
		$meeting = $this->meetings->get($meetingId);
		if (!$type->isAvailableFor($meeting->getStatus())) {
			throw new InvalidStateException("Dokument „{$type->label()}“ v tomto stavu zasedání ještě nevzniká.");
		}

		// PDF se vytvoří mimo transakci – mPDF trvá i sekundy a SQLite by po tu dobu držela zámek zápisu
		$rendered = $this->renderer->generate($meetingId, $type, $namedVotes);
		$stream = fopen('php://temp', 'w+b');
		if ($stream === false) {
			throw new \RuntimeException('Unable to open temporary stream.');
		}

		try {
			fwrite($stream, $rendered->content);
			rewind($stream);
			$stored = $this->storage->store($stream, $rendered->fileName, $this->namespace($meeting->getMunicipality()->getId()));
		} finally {
			fclose($stream);
		}

		return $this->persist($meetingId, $type, $origin, $stored, $userId, $note);
	}

	private function persist(int $meetingId, MeetingDocumentType $type, ArchivedDocumentOrigin $origin, StoredFile $stored, ?int $userId, ?string $note): ArchivedDocument
	{
		try {
			return $this->transaction->run(function () use ($meetingId, $type, $origin, $stored, $userId, $note): ArchivedDocument {
				$document = new ArchivedDocument(
					$this->meetings->get($meetingId),
					$type,
					$origin,
					$stored,
					$userId === null ? null : $this->users->find($userId),
					$note,
				);
				$this->documents->add($document);

				return $document;
			});
		} catch (\Throwable $e) {
			$this->storage->delete($stored);

			throw $e;
		}
	}

	private function namespace(int $municipalityId): string
	{
		return "m{$municipalityId}/documents";
	}
}
