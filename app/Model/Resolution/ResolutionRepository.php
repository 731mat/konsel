<?php

declare(strict_types=1);

namespace App\Model\Resolution;

use App\Model\Meeting\AgendaItem;
use App\Model\Meeting\Meeting;
use App\Model\Meeting\VotingRound;
use App\Model\Municipality\Municipality;
use App\Model\Shared\Exception\NotFoundException;

interface ResolutionRepository
{
	/**
	 * @throws NotFoundException
	 */
	public function get(int $id): Resolution;

	public function findByVotingRound(VotingRound $round): ?Resolution;

	/**
	 * @return list<Resolution>
	 */
	public function findByAgendaItem(AgendaItem $item): array;

	/**
	 * @return list<Resolution> v pořadí bodů a hlasování
	 */
	public function findByMeeting(Meeting $meeting): array;

	/**
	 * @return list<Resolution> nejnovější první
	 */
	public function search(Municipality $municipality, ResolutionSearch $search): array;

	/**
	 * @return list<int> roky, ve kterých obec má usnesení (nejnovější první)
	 */
	public function findYears(Municipality $municipality): array;

	public function add(Resolution $resolution): void;

	public function remove(Resolution $resolution): void;
}
