<?php

declare(strict_types=1);

namespace App\AdminModule\Task;

use App\AdminModule\SecuredPresenter;
use App\Core\Security\Allowed;
use App\Core\Security\Privilege;
use App\Core\Security\RequiresModule;
use App\Core\Security\Resource;
use App\Model\Municipality\Module;
use App\Model\Resolution\ResolutionTask;
use App\Model\Resolution\ResolutionTaskFacade;
use App\Model\Resolution\TaskFilter;
use App\Model\Shared\Exception\DomainException;
use App\Model\Shared\Exception\NotFoundException;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\ValueObject\Email;
use Nette\Application\Attributes\Requires;

/**
 * Úkoly z usnesení napříč zasedáními: otevřené, moje, po termínu.
 */
#[Allowed(Resource::Resolution)]
#[RequiresModule(Module::ResolutionTasks)]
final class TaskPresenter extends SecuredPresenter
{
	public function __construct(
		private readonly ResolutionTaskFacade $taskFacade,
	) {
		parent::__construct();
	}

	public function renderDefault(string $filter = 'open'): void
	{
		$selected = TaskFilter::tryFrom($filter) ?? TaskFilter::Open;
		$this->template->filter = $selected;
		$this->template->filters = TaskFilter::cases();
		$this->template->tasks = $this->taskFacade->find($this->requireMunicipality()->getId(), $selected, $this->userEmail());
		$this->template->overdueCount = count($this->taskFacade->find($this->requireMunicipality()->getId(), TaskFilter::Overdue));
		$this->template->isOverdue = fn(ResolutionTask $task): bool => $this->taskFacade->isOverdue($task);
		$this->template->canEdit = $this->isAllowed(Resource::Resolution, Privilege::Edit);
		$this->template->hasEmail = $this->userEmail() !== null;
	}

	#[Allowed(Resource::Resolution, Privilege::Edit)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleComplete(int $taskId): void
	{
		try {
			$task = $this->assertOwned($this->taskFacade->get($taskId));
			$this->taskFacade->complete($task->getId());
			$this->flashMessage("Úkol „{$task->getDescription()}“ je splněný.", 'success');
		} catch (NotFoundException) {
			$this->error('Úkol neexistuje.');
		} catch (DomainException $e) {
			$this->flashMessage($e->getMessage(), 'danger');
		}

		$this->redirect('this');
	}

	private function userEmail(): ?Email
	{
		$email = $this->getUser()->getIdentity()?->getData()['email'] ?? null;
		try {
			return is_string($email) ? new Email($email) : null;
		} catch (ValidationException) {
			return null;
		}
	}
}
