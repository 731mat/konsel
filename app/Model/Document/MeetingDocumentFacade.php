<?php

declare(strict_types=1);

namespace App\Model\Document;

use App\Model\Attachment\AttachmentRepository;
use App\Model\Meeting\Meeting;
use App\Model\Meeting\MeetingRepository;
use App\Model\Meeting\MeetingStatus;
use App\Model\Meeting\ProceedingsFacade;
use App\Model\Meeting\VotingFacade;
use App\Model\People\MemberFunction;
use App\Model\People\Person;
use App\Model\Resolution\ResolutionFacade;
use App\Model\Storage\FileStorage;
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
		private DocumentTemplateEngine $engine,
		private DocumentTemplateRepository $templates,
		private PdfRenderer $pdf,
		private ProceedingsFacade $proceedings,
		private VotingFacade $voting,
		private ResolutionFacade $resolutions,
		private ClockInterface $clock,
	) {}

	/**
	 * @param bool $namedVotes u zápisu jmenovité výsledky hlasování
	 * @param array<string, string> $overrides neuložené šablony pro náhled (název souboru → zdroj)
	 */
	public function generate(int $meetingId, MeetingDocumentType $type, bool $namedVotes = false, array $overrides = []): RenderedDocument
	{
		$meeting = $this->meetings->get($meetingId);
		$html = $this->renderHtml($meeting, $type, namedVotes: $namedVotes, overrides: $overrides);
		$title = "{$type->label()} – {$meeting->getTitle()}";
		$fileName = sprintf(
			'%s-%s-%s.pdf',
			$type->fileSlug(),
			Strings::webalize($meeting->getTitle()),
			$meeting->getScheduledAt()->format('Y-m-d'),
		);

		return new RenderedDocument($fileName, $this->pdf->render($html, $title));
	}

	/**
	 * Zápis k úpravě ve Wordu: stejné HTML jako PDF (Word ho otevře jako dokument), bez loga z úložiště.
	 */
	public function generateEditable(int $meetingId, MeetingDocumentType $type, bool $namedVotes = false): RenderedDocument
	{
		$meeting = $this->meetings->get($meetingId);
		$fileName = sprintf('%s-%s-%s.doc', $type->fileSlug(), Strings::webalize($meeting->getTitle()), $meeting->getScheduledAt()->format('Y-m-d'));

		return new RenderedDocument($fileName, $this->renderHtml($meeting, $type, withLogo: false, namedVotes: $namedVotes), 'application/msword');
	}

	/**
	 * HTML podoba dokumentu (pro PDF, Word i testy). Použije vlastní šablony obce (pro orgán, jinak pro celou obec),
	 * $overrides má přednost (náhled neuložené šablony).
	 *
	 * @param array<string, string> $overrides název souboru šablony → zdroj
	 */
	public function renderHtml(Meeting $meeting, MeetingDocumentType $type, bool $withLogo = true, bool $namedVotes = false, array $overrides = []): string
	{
		$municipality = $meeting->getMunicipality();
		$logo = $municipality->getLogo();
		$params = [
			'meeting' => $meeting,
			'municipality' => $municipality,
			'profile' => $municipality->getProfile(),
			'logoPath' => $withLogo && $logo !== null && $this->storage->exists($logo) ? $this->storage->getLocalPath($logo) : null,
			'mayor' => $this->findMayor($meeting),
			'attachments' => $type === MeetingDocumentType::Materials ? $this->groupAttachments($meeting) : [],
			'generatedAt' => $this->clock->now(),
		];

		if ($type === MeetingDocumentType::Resolutions) {
			$params += [
				'resolutions' => $this->resolutions->findByMeeting($meeting->getId()),
				'deputy' => $this->findMember($meeting, MemberFunction::DeputyMayor),
				'draft' => $meeting->getStatus() === MeetingStatus::InProgress,
			];
		}

		if ($type === MeetingDocumentType::AttendanceSheet) {
			$params += [
				'attendance' => $this->proceedings->attendance($meeting->getId()),
			];
		}

		if ($type === MeetingDocumentType::Minutes) {
			$params += [
				'attendance' => $this->proceedings->attendance($meeting->getId()),
				'rounds' => $this->voting->findByMeeting($meeting->getId()),
				'resolutions' => $this->resolutions->mapByVotingRound($meeting->getId()),
				'namedVotes' => $namedVotes,
				'draft' => $meeting->getStatus() === MeetingStatus::InProgress,
			];
		}

		return $this->engine->render(DocumentTemplateType::fromMeetingDocument($type), $params, $overrides + $this->customTemplates($meeting));
	}

	/**
	 * Výpis z jednoho usnesení (pro úřady, smluvní strany, dotace) s ověřením správnosti.
	 */
	/**
	 * @param array<string, string> $overrides
	 */
	public function generateResolutionExtract(int $resolutionId, bool $editable = false, array $overrides = []): RenderedDocument
	{
		$resolution = $this->resolutions->get($resolutionId);
		$meeting = $resolution->getMeeting();
		$municipality = $meeting->getMunicipality();
		$logo = $municipality->getLogo();
		$html = $this->engine->render(DocumentTemplateType::ResolutionExtract, [
			'meeting' => $meeting,
			'municipality' => $municipality,
			'profile' => $municipality->getProfile(),
			'logoPath' => !$editable && $logo !== null && $this->storage->exists($logo) ? $this->storage->getLocalPath($logo) : null,
			'mayor' => $this->findMayor($meeting),
			'attachments' => [],
			'generatedAt' => $this->clock->now(),
			'resolution' => $resolution,
		], $overrides + $this->customTemplates($meeting));
		$fileName = sprintf('vypis-usneseni-%s', Strings::webalize($resolution->getNumber()));

		return $editable
			? new RenderedDocument("{$fileName}.doc", $html, 'application/msword')
			: new RenderedDocument("{$fileName}.pdf", $this->pdf->render($html, "Výpis z usnesení č. {$resolution->getNumber()}"));
	}

	/**
	 * Vlastní šablony obce platné pro orgán zasedání: šablona orgánu má přednost před šablonou celé obce.
	 *
	 * @return array<string, string> název souboru → zdroj
	 */
	private function customTemplates(Meeting $meeting): array
	{
		$sources = [];
		$templates = $this->templates->findByMunicipality($meeting->getMunicipality());
		usort($templates, static fn(DocumentTemplate $a, DocumentTemplate $b): int => ($a->getBody() === null ? 0 : 1) <=> ($b->getBody() === null ? 0 : 1));
		foreach ($templates as $template) {
			if ($template->getBody() === null || $template->getBody() === $meeting->getBody()) {
				$sources[$template->getType()->fileName()] = $template->getSource();
			}
		}

		return $sources;
	}

	/**
	 * Starosta k datu zasedání (podepisuje pozvánku).
	 */
	private function findMayor(Meeting $meeting): ?Person
	{
		return $this->findMember($meeting, MemberFunction::Mayor);
	}

	private function findMember(Meeting $meeting, MemberFunction $function): ?Person
	{
		foreach ($meeting->getBody()->getMembersAt($meeting->getScheduledAt()) as $membership) {
			if ($membership->getFunction() === $function) {
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
