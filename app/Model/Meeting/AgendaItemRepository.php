<?php

declare(strict_types=1);

namespace App\Model\Meeting;

use App\Model\Shared\Exception\NotFoundException;

interface AgendaItemRepository
{
	/**
	 * @throws NotFoundException
	 */
	public function get(int $id): AgendaItem;
}
