<?php

declare(strict_types=1);

namespace App\Model\Template;

use App\Model\Meeting\AgendaItemContent;
use App\Model\Meeting\MeetingRepository;
use App\Model\Municipality\MunicipalityRepository;
use App\Model\Shared\Exception\InvalidStateException;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\TransactionManager;
use App\Model\Template\Command\SaveResolutionTemplate;

/**
 * Knihovna šablon usnesení a šablon programu zasedání.
 */
final readonly class TemplateFacade
{
	public function __construct(
		private ResolutionTemplateRepository $resolutionTemplates,
		private MeetingTemplateRepository $meetingTemplates,
		private MunicipalityRepository $municipalities,
		private MeetingRepository $meetings,
		private TransactionManager $transaction,
	) {}

	// --- Šablony usnesení ---

	/**
	 * @return list<ResolutionTemplate>
	 */
	public function findResolutionTemplates(int $municipalityId): array
	{
		return $this->resolutionTemplates->findByMunicipality($this->municipalities->get($municipalityId));
	}

	public function getResolutionTemplate(int $templateId): ResolutionTemplate
	{
		return $this->resolutionTemplates->get($templateId);
	}

	public function createResolutionTemplate(int $municipalityId, SaveResolutionTemplate $command): ResolutionTemplate
	{
		return $this->transaction->run(function () use ($municipalityId, $command): ResolutionTemplate {
			$template = new ResolutionTemplate($this->municipalities->get($municipalityId), $command->title, $command->category, $command->text);
			$this->resolutionTemplates->add($template);

			return $template;
		});
	}

	public function updateResolutionTemplate(int $templateId, SaveResolutionTemplate $command): void
	{
		$this->transaction->run(function () use ($templateId, $command): void {
			$this->resolutionTemplates->get($templateId)->change($command->title, $command->category, $command->text);
		});
	}

	public function deleteResolutionTemplate(int $templateId): void
	{
		$this->transaction->run(function () use ($templateId): void {
			$this->resolutionTemplates->remove($this->resolutionTemplates->get($templateId));
		});
	}

	/**
	 * Doplní výchozí šablony usnesení, které obec ještě nemá (podle názvu).
	 */
	public function installDefaultResolutionTemplates(int $municipalityId): int
	{
		return $this->transaction->run(function () use ($municipalityId): int {
			$municipality = $this->municipalities->get($municipalityId);
			$existing = array_map(static fn(ResolutionTemplate $t): string => mb_strtolower($t->getTitle()), $this->resolutionTemplates->findByMunicipality($municipality));
			$added = 0;
			foreach (DefaultResolutionTemplates::all() as [$category, $title, $text]) {
				if (!in_array(mb_strtolower($title), $existing, true)) {
					$this->resolutionTemplates->add(new ResolutionTemplate($municipality, $title, $category, $text));
					$added++;
				}
			}

			return $added;
		});
	}

	/**
	 * Šablony usnesení s dosazenými proměnnými pro konkrétní zasedání (pro vložení do bodu).
	 *
	 * @return list<array{id: int, category: string, title: string, text: string}>
	 */
	public function renderResolutionTemplates(int $meetingId): array
	{
		$meeting = $this->meetings->get($meetingId);

		return array_map(static fn(ResolutionTemplate $template): array => [
			'id' => $template->getId(),
			'category' => $template->getCategory(),
			'title' => $template->getTitle(),
			'text' => TemplateVariables::apply($template->getText(), $meeting),
		], $this->resolutionTemplates->findByMunicipality($meeting->getMunicipality()));
	}

	// --- Šablony programu ---

	/**
	 * @return list<MeetingTemplate>
	 */
	public function findMeetingTemplates(int $municipalityId): array
	{
		return $this->meetingTemplates->findByMunicipality($this->municipalities->get($municipalityId));
	}

	public function getMeetingTemplate(int $templateId): MeetingTemplate
	{
		return $this->meetingTemplates->get($templateId);
	}

	public function deleteMeetingTemplate(int $templateId): void
	{
		$this->transaction->run(function () use ($templateId): void {
			$this->meetingTemplates->remove($this->meetingTemplates->get($templateId));
		});
	}

	public function installDefaultMeetingTemplates(int $municipalityId): int
	{
		return $this->transaction->run(function () use ($municipalityId): int {
			$municipality = $this->municipalities->get($municipalityId);
			$existing = array_map(static fn(MeetingTemplate $t): string => mb_strtolower($t->getName()), $this->meetingTemplates->findByMunicipality($municipality));
			$added = 0;
			foreach (DefaultMeetingTemplates::all() as [$name, $type, $items, $note]) {
				if (!in_array(mb_strtolower($name), $existing, true)) {
					$this->meetingTemplates->add(new MeetingTemplate($municipality, $name, $type, $items, $note));
					$added++;
				}
			}

			return $added;
		});
	}

	/**
	 * Uloží program zasedání jako novou šablonu (texty bez dosazování – jak jsou v bodech).
	 */
	public function saveProgramAsTemplate(int $meetingId, string $name): MeetingTemplate
	{
		return $this->transaction->run(function () use ($meetingId, $name): MeetingTemplate {
			$meeting = $this->meetings->get($meetingId);
			$items = [];
			foreach ($meeting->getAgendaItems() as $item) {
				$items[] = new MeetingTemplateItem($item->getTitle(), $item->getDescription(), $item->getProposedResolution());
			}

			if ($items === []) {
				throw new ValidationException('Program je prázdný – není co uložit.', 'name');
			}

			$template = new MeetingTemplate($meeting->getMunicipality(), $name, $meeting->getType(), $items);
			$this->meetingTemplates->add($template);

			return $template;
		});
	}

	/**
	 * Přidá body ze šablony na konec programu (s dosazenými proměnnými).
	 *
	 * @throws InvalidStateException program už nelze měnit
	 */
	public function applyToMeeting(int $templateId, int $meetingId): int
	{
		return $this->transaction->run(function () use ($templateId, $meetingId): int {
			$meeting = $this->meetings->get($meetingId);
			$template = $this->meetingTemplates->get($templateId);
			if ($template->getMunicipality() !== $meeting->getMunicipality()) {
				throw new InvalidStateException('Šablona patří k jiné obci.');
			}

			$items = $template->getItems();
			foreach ($items as $item) {
				$meeting->addAgendaItem(new AgendaItemContent(
					TemplateVariables::apply($item->title, $meeting),
					$item->description === null ? null : TemplateVariables::apply($item->description, $meeting),
					$item->proposedResolution === null ? null : TemplateVariables::apply($item->proposedResolution, $meeting),
				));
			}

			return count($items);
		});
	}
}
