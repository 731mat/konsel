<?php

declare(strict_types=1);

namespace App\AdminModule\Archive;

use App\AdminModule\SecuredPresenter;
use App\Core\Security\Allowed;
use App\Core\Security\Resource;
use App\Model\Document\ArchiveEntry;
use App\Model\Document\DocumentArchiveFacade;
use App\Model\Meeting\MeetingFacade;
use App\Model\People\PeopleFacade;
use Psr\Clock\ClockInterface;

/**
 * Archiv podle let a orgánů: proběhlá zasedání s konečným zápisem, usnesením a podepsanými originály.
 */
#[Allowed(Resource::Meeting)]
final class ArchivePresenter extends SecuredPresenter
{
	public function __construct(
		private readonly MeetingFacade $meetingFacade,
		private readonly DocumentArchiveFacade $archiveFacade,
		private readonly PeopleFacade $peopleFacade,
		private readonly ClockInterface $clock,
	) {
		parent::__construct();
	}

	/**
	 * Bez roku = letošní rok (nebo poslední už proběhlý rok se zasedáními). Bez orgánu platí přepínač v horní liště, prázdný = všechny.
	 */
	public function renderDefault(?string $year = null, ?string $body = null): void
	{
		$municipalityId = $this->requireMunicipality()->getId();
		$years = $this->meetingFacade->findYears($municipalityId);
		$currentYear = (int) $this->clock->now()->format('Y');
		$pastYears = array_values(array_filter($years, static fn(int $y): bool => $y <= $currentYear));
		$selectedYear = ctype_digit((string) $year) && in_array((int) $year, $years, true) ? (int) $year : ($pastYears[0] ?? $years[0] ?? null);

		$bodies = [];
		foreach ($this->peopleFacade->findTerms($municipalityId) as $term) {
			foreach ($this->peopleFacade->findBodies($term->getId()) as $termBody) {
				$bodies[$termBody->getId()] = $termBody->getName() . " ({$term->getName()})";
			}
		}

		$body ??= (string) $this->selectedBodyId();
		$selectedBody = ctype_digit($body) && isset($bodies[(int) $body]) ? (int) $body : null;

		$entries = $selectedYear === null
			? []
			: $this->archiveFacade->overview($this->meetingFacade->find($municipalityId, $selectedBody, $selectedYear));

		// orgán → zasedání od nejstaršího (archiv se čte chronologicky)
		$grouped = [];
		foreach (array_reverse($entries) as $entry) {
			$grouped[$entry->meeting->getBody()->getName()][] = $entry;
		}

		ksort($grouped);

		$this->template->years = $years;
		$this->template->selectedYear = $selectedYear;
		$this->template->bodies = $bodies;
		$this->template->selectedBody = $selectedBody;
		$this->template->grouped = $grouped;
		$this->template->approvedCount = count(array_filter($entries, static fn(ArchiveEntry $entry): bool => $entry->meeting->isArchived()));
		$this->template->entryCount = count($entries);
	}
}
