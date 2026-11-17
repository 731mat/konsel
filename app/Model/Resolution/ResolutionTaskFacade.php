<?php

declare(strict_types=1);

namespace App\Model\Resolution;

use App\Model\Municipality\MunicipalityRepository;
use App\Model\People\PersonRepository;
use App\Model\Resolution\Command\SaveResolutionTask;
use App\Model\Shared\Exception\InvalidStateException;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\TransactionManager;
use App\Model\Shared\ValueObject\Email;
use Psr\Clock\ClockInterface;

/**
 * Úkoly z usnesení: zadání, termíny, splnění.
 */
final readonly class ResolutionTaskFacade
{
	public function __construct(
		private ResolutionTaskRepository $tasks,
		private ResolutionRepository $resolutions,
		private PersonRepository $persons,
		private MunicipalityRepository $municipalities,
		private TransactionManager $transaction,
		private ClockInterface $clock,
	) {}

	/**
	 * @throws ValidationException
	 */
	public function add(int $resolutionId, SaveResolutionTask $command): ResolutionTask
	{
		return $this->transaction->run(function () use ($resolutionId, $command): ResolutionTask {
			$task = new ResolutionTask(
				$this->resolutions->get($resolutionId),
				$command->description,
				$command->assigneeId === null ? null : $this->persons->get($command->assigneeId),
				$command->dueDate,
				$this->clock->now(),
			);
			$this->tasks->add($task);

			return $task;
		});
	}

	/**
	 * @throws InvalidStateException
	 */
	public function complete(int $taskId, ?string $note = null): void
	{
		$this->transaction->run(fn() => $this->tasks->get($taskId)->complete($this->clock->now(), $note));
	}

	/**
	 * @throws InvalidStateException
	 */
	public function cancel(int $taskId, ?string $note = null): void
	{
		$this->transaction->run(fn() => $this->tasks->get($taskId)->cancel($note));
	}

	public function reopen(int $taskId): void
	{
		$this->transaction->run(fn() => $this->tasks->get($taskId)->reopen());
	}

	public function delete(int $taskId): void
	{
		$this->transaction->run(fn() => $this->tasks->remove($this->tasks->get($taskId)));
	}

	public function get(int $taskId): ResolutionTask
	{
		return $this->tasks->get($taskId);
	}

	/**
	 * @return list<ResolutionTask>
	 */
	public function findByResolution(int $resolutionId): array
	{
		return $this->tasks->findByResolution($this->resolutions->get($resolutionId));
	}

	/**
	 * @param ?Email $userEmail pro filtr Moje: úkoly osoby se stejným e-mailem jako přihlášený uživatel
	 * @return list<ResolutionTask>
	 */
	public function find(int $municipalityId, TaskFilter $filter, ?Email $userEmail = null): array
	{
		return $this->tasks->find($this->municipalities->get($municipalityId), $filter, $this->clock->now(), $userEmail);
	}

	public function isOverdue(ResolutionTask $task): bool
	{
		return $task->isOverdue($this->clock->now());
	}
}
