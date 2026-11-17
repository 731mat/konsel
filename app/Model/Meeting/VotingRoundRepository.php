<?php

declare(strict_types=1);

namespace App\Model\Meeting;

use App\Model\Shared\Exception\NotFoundException;

interface VotingRoundRepository
{
	/**
	 * @throws NotFoundException
	 */
	public function get(int $id): VotingRound;

	/**
	 * @return list<VotingRound> v pořadí hlasování
	 */
	public function findByItem(AgendaItem $item): array;

	/**
	 * @return list<VotingRound> podle pořadí bodů a hlasování
	 */
	public function findByMeeting(Meeting $meeting): array;

	public function add(VotingRound $round): void;

	public function remove(VotingRound $round): void;
}
