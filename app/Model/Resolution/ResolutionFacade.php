<?php

declare(strict_types=1);

namespace App\Model\Resolution;

use App\Model\Meeting\MeetingRepository;
use App\Model\Municipality\MunicipalityRepository;
use App\Model\Shared\Exception\InvalidStateException;
use App\Model\Shared\Exception\NotFoundException;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\TransactionManager;

/**
 * Usnesení – čtení (vznikají při hlasování, viz VotingFacade::close()).
 */
final readonly class ResolutionFacade
{
	public function __construct(
		private ResolutionRepository $resolutions,
		private MeetingRepository $meetings,
		private MunicipalityRepository $municipalities,
		private TransactionManager $transaction,
	) {}

	/**
	 * @return list<Resolution>
	 */
	public function search(int $municipalityId, ResolutionSearch $search): array
	{
		return $this->resolutions->search($this->municipalities->get($municipalityId), $search);
	}

	/**
	 * @return list<int>
	 */
	public function findYears(int $municipalityId): array
	{
		return $this->resolutions->findYears($this->municipalities->get($municipalityId));
	}

	/**
	 * @throws InvalidStateException
	 * @throws ValidationException
	 */
	public function revoke(int $resolutionId, string $note): void
	{
		$this->transaction->run(function () use ($resolutionId, $note): void {
			$this->resolutions->get($resolutionId)->revoke($note);
		});
	}

	/**
	 * @throws NotFoundException
	 */
	public function get(int $id): Resolution
	{
		return $this->resolutions->get($id);
	}

	/**
	 * @return list<Resolution>
	 */
	public function findByMeeting(int $meetingId): array
	{
		return $this->resolutions->findByMeeting($this->meetings->get($meetingId));
	}

	/**
	 * @return array<int, Resolution> ID hlasování → usnesení
	 */
	public function mapByVotingRound(int $meetingId): array
	{
		$map = [];
		foreach ($this->findByMeeting($meetingId) as $resolution) {
			$round = $resolution->getVotingRound();
			if ($round !== null) {
				$map[$round->getId()] = $resolution;
			}
		}

		return $map;
	}
}
