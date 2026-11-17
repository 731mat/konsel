<?php

declare(strict_types=1);

namespace App\AdminModule\Search;

use App\AdminModule\SecuredPresenter;
use App\Core\Security\Allowed;
use App\Core\Security\Privilege;
use App\Core\Security\Resource;
use App\Model\Meeting\MeetingFacade;
use App\Model\People\PeopleFacade;
use App\Model\Search\SearchDocumentType;
use App\Model\Search\SearchFacade;
use App\Model\Search\SearchHit;

/**
 * Fulltextové hledání v celé obci (bez ohledu na diakritiku).
 */
#[Allowed(Resource::Dashboard)]
final class SearchPresenter extends SecuredPresenter
{
	public function __construct(
		private readonly SearchFacade $searchFacade,
		private readonly MeetingFacade $meetingFacade,
		private readonly PeopleFacade $peopleFacade,
	) {
		parent::__construct();
	}

	/**
	 * Parametry přicházejí z GET formuláře jako řetězce (prázdný = bez omezení).
	 */
	public function renderDefault(?string $q = null, ?string $type = null, ?string $body = null, ?string $year = null, int $page = 1): void
	{
		$municipalityId = $this->requireMunicipality()->getId();
		$q = trim((string) $q);
		$selectedType = SearchDocumentType::tryFrom((string) $type);
		$bodyId = ctype_digit((string) $body) ? (int) $body : null;
		$year = ctype_digit((string) $year) ? (int) $year : null;
		$page = max(1, $page);

		$bodies = [];
		foreach ($this->peopleFacade->findTerms($municipalityId) as $term) {
			foreach ($this->peopleFacade->findBodies($term->getId()) as $termBody) {
				$bodies[$termBody->getId()] = $termBody->getName() . " ({$term->getName()})";
			}
		}

		$municipality = $this->requireMunicipality();
		$excluded = array_values(array_filter(
			SearchDocumentType::cases(),
			static fn(SearchDocumentType $t): bool => $t->module() !== null && !$municipality->isModuleEnabled($t->module()),
		));
		$result = $this->searchFacade->search($municipalityId, $q, $selectedType, isset($bodies[$bodyId ?? 0]) ? $bodyId : null, $year, $page, $excluded);

		$this->template->q = $q;
		$this->template->result = $result;
		$this->template->types = array_values(array_filter(SearchDocumentType::cases(), static fn(SearchDocumentType $t): bool => !in_array($t, $excluded, true)));
		$this->template->selectedType = $selectedType;
		$this->template->bodies = $bodies;
		$this->template->selectedBody = $bodyId;
		$this->template->years = $this->meetingFacade->findYears($municipalityId);
		$this->template->selectedYear = $year;
		$this->template->page = $page;
		$this->template->pages = max(1, (int) ceil($result->total / SearchFacade::PageSize));
		$this->template->hitLink = $this->hitLink(...);
	}

	private function hitLink(SearchHit $hit): string
	{
		$id = [
			'id' => $hit->targetId,
		];

		return match ($hit->type) {
			SearchDocumentType::Meeting => $this->link('Meeting:detail', $id),
			SearchDocumentType::AgendaItem => $this->link('Agenda:detail', $id),
			SearchDocumentType::Resolution => $this->link('Resolution:detail', $id),
			SearchDocumentType::ItemComment => $this->link('Agenda:detail', $id) . "#komentar-{$hit->entityId}",
			SearchDocumentType::Project => $this->link('Project:detail', $id),
			SearchDocumentType::ProjectComment => $this->link('Project:detail', $id) . "#komentar-{$hit->entityId}",
			SearchDocumentType::ResolutionTemplate => $this->isAllowed(Resource::Template, Privilege::Edit) ? $this->link('Template:edit', $id) : $this->link('Template:default'),
			SearchDocumentType::MeetingTemplate => $this->link('Template:meeting', $id),
		};
	}
}
