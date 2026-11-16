<?php

declare(strict_types=1);

namespace App\Model\Meeting;

use App\Model\Project\Project;
use App\Model\Shared\Exception\NotFoundException;

interface AgendaItemRepository
{
	/**
	 * @throws NotFoundException
	 */
	public function get(int $id): AgendaItem;

	/**
	 * @return list<AgendaItem> body propojené s úkolem, podle data zasedání
	 */
	public function findByProject(Project $project): array;

	/**
	 * @return list<AgendaItem> body zasedání obce od data (pro výběr při propojování)
	 */
	public function findRecent(\App\Model\Municipality\Municipality $municipality, \DateTimeImmutable $since): array;
}
