<?php

declare(strict_types=1);

namespace App\Model\Resolution;

use App\Model\Municipality\Municipality;
use App\Model\Shared\Exception\NotFoundException;
use App\Model\Shared\ValueObject\Email;

interface ResolutionTaskRepository
{
	/**
	 * @throws NotFoundException
	 */
	public function get(int $id): ResolutionTask;

	/**
	 * @return list<ResolutionTask>
	 */
	public function findByResolution(Resolution $resolution): array;

	/**
	 * @param ?Email $assigneeEmail jen úkoly osoby s tímto e-mailem
	 * @return list<ResolutionTask> podle termínu (bez termínu na konci)
	 */
	public function find(Municipality $municipality, TaskFilter $filter, \DateTimeImmutable $today, ?Email $assigneeEmail = null): array;

	public function add(ResolutionTask $task): void;

	public function remove(ResolutionTask $task): void;
}
