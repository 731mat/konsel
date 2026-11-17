<?php

declare(strict_types=1);

namespace App\Model\Resolution;

use App\Model\Meeting\MeetingRepository;
use App\Model\Shared\Exception\NotFoundException;

/**
 * Usnesení – čtení (vznikají při hlasování, viz VotingFacade::close()).
 */
final readonly class ResolutionFacade
{
	public function __construct(
		private ResolutionRepository $resolutions,
		private MeetingRepository $meetings,
	) {}

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
