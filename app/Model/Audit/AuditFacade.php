<?php

declare(strict_types=1);

namespace App\Model\Audit;

use App\Model\Municipality\MunicipalityRepository;
use App\Model\Resolution\ResolutionRepository;

/**
 * Historie změn: přehled obce s filtry, historie zasedání a usnesení.
 */
final readonly class AuditFacade
{
	public const int PageSize = 50;

	public function __construct(
		private AuditEntryRepository $entries,
		private MunicipalityRepository $municipalities,
		private ResolutionRepository $resolutions,
	) {}

	/**
	 * @return array{list<AuditEntry>, int} [stránka, celkem]
	 */
	public function find(int $municipalityId, AuditFilter $filter, int $page = 1): array
	{
		return $this->entries->find($this->municipalities->get($municipalityId), $filter, self::PageSize, (max(1, $page) - 1) * self::PageSize);
	}

	/**
	 * @return list<AuditEntry>
	 */
	public function findByMeeting(int $municipalityId, int $meetingId): array
	{
		return $this->entries->findByMeeting($this->municipalities->get($municipalityId), $meetingId);
	}

	/**
	 * Historie usnesení: usnesení, jeho hlasování, hlasy a úkoly.
	 *
	 * @return list<AuditEntry>
	 */
	public function findForResolution(int $resolutionId, bool $includeTasks = true): array
	{
		$resolution = $this->resolutions->get($resolutionId);
		$entities = [[AuditEntityType::Resolution, $resolution->getId()]];
		$children = $includeTasks ? [[AuditEntityType::ResolutionTask, $resolution->getId()]] : [];
		$round = $resolution->getVotingRound();
		if ($round !== null) {
			$entities[] = [AuditEntityType::VotingRound, $round->getId()];
			$children[] = [AuditEntityType::Vote, $round->getId()];
		}

		return $this->entries->findFor($resolution->getMunicipality(), $entities, $children);
	}

	/**
	 * @return array<int, string>
	 */
	public function findActors(int $municipalityId): array
	{
		return $this->entries->findActors($this->municipalities->get($municipalityId));
	}
}
