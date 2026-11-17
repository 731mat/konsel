<?php

declare(strict_types=1);

namespace App\Model\Document;

use App\Model\Meeting\Meeting;

/**
 * Zasedání v archivu a jeho uložené dokumenty.
 */
final readonly class ArchiveEntry
{
	/**
	 * @param list<ArchivedDocument> $documents od nejnovější
	 */
	public function __construct(
		public Meeting $meeting,
		public array $documents,
	) {}

	/**
	 * Platná podoba dokumentu: podepsaný originál má přednost před vygenerovanou konečnou verzí,
	 * ta před pracovní uloženou verzí (pracovní verze se ukáže jen u neověřeného zasedání).
	 */
	public function document(MeetingDocumentType $type): ?ArchivedDocument
	{
		$candidates = array_filter($this->documents, fn(ArchivedDocument $document): bool => $document->getType() === $type
			&& ($document->getOrigin() !== ArchivedDocumentOrigin::Generated || !$this->meeting->isArchived())
			&& !$document->isDraft());
		usort($candidates, static fn(ArchivedDocument $a, ArchivedDocument $b): int => self::priority($a) <=> self::priority($b));

		return $candidates[0] ?? null;
	}

	/**
	 * Ověřené zasedání bez konečného zápisu v archivu (automatické uložení selhalo nebo starší data).
	 */
	public function isMissingFinal(): bool
	{
		return $this->meeting->isArchived() && $this->document(MeetingDocumentType::Minutes) === null;
	}

	private static function priority(ArchivedDocument $document): int
	{
		return match ($document->getOrigin()) {
			ArchivedDocumentOrigin::Uploaded => 0,
			ArchivedDocumentOrigin::Final => 1,
			ArchivedDocumentOrigin::Generated => 2,
		};
	}
}
