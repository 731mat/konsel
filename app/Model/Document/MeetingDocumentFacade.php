<?php

declare(strict_types=1);

namespace App\Model\Document;

use App\Model\Attachment\AttachmentRepository;
use App\Model\Meeting\Meeting;
use App\Model\Meeting\MeetingRepository;
use App\Model\People\MemberFunction;
use App\Model\People\Person;
use App\Model\Storage\FileStorage;
use Nette\Bridges\ApplicationLatte\LatteFactory;
use Nette\Utils\Strings;
use Psr\Clock\ClockInterface;

/**
 * Dokumenty zasedání v PDF z Latte šablon v resources/documents.
 */
final readonly class MeetingDocumentFacade
{
	public function __construct(
		private MeetingRepository $meetings,
		private AttachmentRepository $attachments,
		private FileStorage $storage,
		private LatteFactory $latteFactory,
		private PdfRenderer $pdf,
		private ClockInterface $clock,
		private string $templateDir = __DIR__ . '/../../../resources/documents',
	) {}

	public function generate(int $meetingId, MeetingDocumentType $type): GeneratedDocument
	{
		$meeting = $this->meetings->get($meetingId);
		$html = $this->renderHtml($meeting, $type);
		$title = "{$type->label()} – {$meeting->getTitle()}";
		$fileName = sprintf(
			'%s-%s-%s.pdf',
			$type->fileSlug(),
			Strings::webalize($meeting->getTitle()),
			$meeting->getScheduledAt()->format('Y-m-d'),
		);

		return new GeneratedDocument($fileName, $this->pdf->render($html, $title));
	}

	/**
	 * HTML podoba dokumentu (pro PDF i testy).
	 */
	public function renderHtml(Meeting $meeting, MeetingDocumentType $type): string
	{
		$municipality = $meeting->getMunicipality();
		$logo = $municipality->getLogo();

		return $this->latteFactory->create()->renderToString($this->templateDir . "/{$type->value}.latte", [
			'meeting' => $meeting,
			'municipality' => $municipality,
			'profile' => $municipality->getProfile(),
			'logoPath' => $logo !== null && $this->storage->exists($logo) ? $this->storage->getLocalPath($logo) : null,
			'mayor' => $this->findMayor($meeting),
			'attachments' => $type === MeetingDocumentType::Materials ? $this->groupAttachments($meeting) : [],
			'generatedAt' => $this->clock->now(),
		]);
	}

	/**
	 * Starosta k datu zasedání (podepisuje pozvánku).
	 */
	private function findMayor(Meeting $meeting): ?Person
	{
		foreach ($meeting->getBody()->getMembersAt($meeting->getScheduledAt()) as $membership) {
			if ($membership->getFunction() === MemberFunction::Mayor) {
				return $membership->getPerson();
			}
		}

		return null;
	}

	/**
	 * @return array<int, list<string>> ID bodu → názvy příloh pro zastupitele
	 */
	private function groupAttachments(Meeting $meeting): array
	{
		$grouped = [];
		foreach ($this->attachments->findByMeeting($meeting) as $attachment) {
			$item = $attachment->getAgendaItem();
			if ($item !== null && $attachment->isPublicForMembers()) {
				$grouped[$item->getId()][] = $attachment->getTitle();
			}
		}

		return $grouped;
	}
}
