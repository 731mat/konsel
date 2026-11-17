<?php

declare(strict_types=1);

namespace App\Model\Recording;

use App\Model\Meeting\Meeting;
use App\Model\Shared\Exception\NotFoundException;

interface RecordingRepository
{
	/**
	 * @throws NotFoundException
	 */
	public function get(int $id): Recording;

	/**
	 * @return list<Recording> v pořadí nahrání
	 */
	public function findByMeeting(Meeting $meeting): array;

	public function add(Recording $recording): void;

	public function remove(Recording $recording): void;
}
