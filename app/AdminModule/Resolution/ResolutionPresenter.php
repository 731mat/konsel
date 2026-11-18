<?php

declare(strict_types=1);

namespace App\AdminModule\Resolution;

use App\AdminModule\SecuredPresenter;
use App\Core\Security\Allowed;
use App\Core\Security\Privilege;
use App\Core\Security\RequiresModule;
use App\Core\Security\Resource;
use App\Model\Audit\AuditFacade;
use App\Model\Document\MeetingDocumentFacade;
use App\Model\Municipality\Module;
use App\Model\People\PeopleFacade;
use App\Model\Resolution\Command\SaveResolutionTask;
use App\Model\Resolution\Resolution;
use App\Model\Resolution\ResolutionFacade;
use App\Model\Resolution\ResolutionSearch;
use App\Model\Resolution\ResolutionStatus;
use App\Model\Resolution\ResolutionTask;
use App\Model\Resolution\ResolutionTaskFacade;
use App\Model\Shared\Exception\DomainException;
use App\Model\Shared\Exception\NotFoundException;
use App\Model\Shared\Exception\ValidationException;
use App\UI\Form\FormErrors;
use App\UI\Form\FormFactory;
use Nette\Application\Attributes\Requires;
use Nette\Application\Responses\CallbackResponse;
use Nette\Application\UI\Form;

/**
 * Přehled usnesení obce (orgán, rok, stav, hledání v textu), detail s hlasováním a úkoly.
 */
#[Allowed(Resource::Resolution)]
final class ResolutionPresenter extends SecuredPresenter
{
	private ?Resolution $resolution = null;

	public function __construct(
		private readonly ResolutionFacade $resolutionFacade,
		private readonly ResolutionTaskFacade $taskFacade,
		private readonly AuditFacade $auditFacade,
		private readonly PeopleFacade $peopleFacade,
		private readonly FormFactory $formFactory,
		private readonly MeetingDocumentFacade $documentFacade,
	) {
		parent::__construct();
	}

	/**
	 * Parametry filtru přicházejí z GET formuláře jako řetězce (prázdný = bez omezení).
	 */
	public function renderDefault(?string $body = null, ?string $year = null, ?string $status = null, ?string $q = null): void
	{
		$municipalityId = $this->requireMunicipality()->getId();
		$body ??= (string) $this->selectedBodyId();
		$search = new ResolutionSearch(
			ctype_digit($body) ? (int) $body : null,
			ctype_digit((string) $year) ? (int) $year : null,
			ResolutionStatus::tryFrom((string) $status),
			$q === null || trim($q) === '' ? null : mb_substr(trim($q), 0, 100),
		);

		$this->template->resolutions = $this->resolutionFacade->search($municipalityId, $search);
		$this->template->search = $search;
		$this->template->years = $this->resolutionFacade->findYears($municipalityId);
		$this->template->statuses = ResolutionStatus::cases();
		$bodies = [];
		foreach ($this->peopleFacade->findTerms($municipalityId) as $term) {
			foreach ($this->peopleFacade->findBodies($term->getId()) as $item) {
				$bodies[$item->getId()] = "{$item->getName()} ({$term->getName()})";
			}
		}

		$this->template->bodies = $bodies;
	}

	public function actionDetail(int $id): void
	{
		try {
			$this->resolution = $this->assertOwned($this->resolutionFacade->get($id));
		} catch (NotFoundException $e) {
			$this->error($e->getMessage());
		}
	}

	public function renderDetail(): void
	{
		$resolution = $this->requireResolution();
		$this->template->resolution = $resolution;
		$this->template->tasksEnabled = $tasksEnabled = $resolution->getMunicipality()->isModuleEnabled(Module::ResolutionTasks);
		$this->template->tasks = $tasksEnabled ? $this->taskFacade->findByResolution($resolution->getId()) : [];
		$this->template->isOverdue = fn(ResolutionTask $task): bool => $this->taskFacade->isOverdue($task);
		$this->template->canEdit = $this->isAllowed(Resource::Resolution, Privilege::Edit);
		$this->template->canViewDocuments = $this->isAllowed(Resource::Document);
		// úkoly v historii jen se zapnutým modulem úkolů z usnesení
		$this->template->history = $this->isAllowed(Resource::Audit) ? $this->auditFacade->findForResolution($resolution->getId(), $tasksEnabled) : null;
		$this->template->canRevoke = $this->isAllowed(Resource::Resolution, Privilege::Manage) && $resolution->getStatus() === ResolutionStatus::Adopted;
	}

	/**
	 * Výpis z usnesení v PDF (format=doc pro úpravy ve Wordu).
	 */
	#[Allowed(Resource::Document)]
	public function actionExtract(int $id, string $format = 'pdf'): void
	{
		try {
			$resolution = $this->assertOwned($this->resolutionFacade->get($id));
		} catch (NotFoundException $e) {
			$this->error($e->getMessage());
		}

		$document = $this->documentFacade->generateResolutionExtract($resolution->getId(), editable: $format === 'doc');
		$response = $this->getHttpResponse();
		$response->setContentType($document->mimeType);
		$response->setHeader('Content-Disposition', sprintf(
			'%s; filename="%s"',
			$format === 'doc' ? 'attachment' : 'inline',
			$document->fileName,
		));
		$this->sendResponse(new CallbackResponse(static function () use ($document): void {
			echo $document->content;
		}));
	}

	#[Allowed(Resource::Resolution, Privilege::Edit)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	#[RequiresModule(Module::ResolutionTasks)]
	public function handleCompleteTask(int $taskId): void
	{
		$this->runTaskChange($taskId, fn() => $this->taskFacade->complete($taskId), 'Úkol je splněný.');
	}

	#[Allowed(Resource::Resolution, Privilege::Edit)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	#[RequiresModule(Module::ResolutionTasks)]
	public function handleCancelTask(int $taskId): void
	{
		$this->runTaskChange($taskId, fn() => $this->taskFacade->cancel($taskId), 'Úkol byl zrušen.');
	}

	#[Allowed(Resource::Resolution, Privilege::Edit)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	#[RequiresModule(Module::ResolutionTasks)]
	public function handleReopenTask(int $taskId): void
	{
		$this->runTaskChange($taskId, fn() => $this->taskFacade->reopen($taskId), 'Úkol je znovu otevřený.');
	}

	#[Allowed(Resource::Resolution, Privilege::Delete)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	#[RequiresModule(Module::ResolutionTasks)]
	public function handleDeleteTask(int $taskId): void
	{
		$this->runTaskChange($taskId, fn() => $this->taskFacade->delete($taskId), 'Úkol byl smazán.');
	}

	protected function createComponentTaskForm(): Form
	{
		$resolution = $this->requireResolution();
		$persons = [];
		foreach ($this->peopleFacade->findPersons($resolution->getMunicipality()->getId()) as $person) {
			$persons[$person->getId()] = $person->getFullName();
		}

		$form = $this->formFactory->create();
		$form->addText('description', 'Úkol')
			->setRequired('Napište, co je potřeba udělat.')
			->setMaxLength(ResolutionTask::MaxDescriptionLength)
			->setHtmlAttribute('placeholder', 'Např. podepsat smlouvu o dílo');
		$form->addSelect('assigneeId', 'Kdo', $persons)
			->setPrompt('obecní úřad');
		$form->addDate('dueDate', 'Termín');
		$form->addSubmit('add', 'Přidat úkol');
		$form->onSuccess[] = function (Form $form) use ($resolution): void {
			$this->requirePermission(Resource::Resolution, Privilege::Edit);
			$this->requireModule($resolution->getMunicipality(), Module::ResolutionTasks);
			$data = $form->getValues(TaskFormData::class);
			try {
				$this->taskFacade->add($resolution->getId(), new SaveResolutionTask($data->description, $data->assigneeId, $data->dueDate));
			} catch (ValidationException $e) {
				FormErrors::apply($form, $e);

				return;
			}

			$this->flashMessage('Úkol byl přidán.', 'success');
			$this->redirect('this');
		};

		return $form;
	}

	protected function createComponentRevokeForm(): Form
	{
		$form = $this->formFactory->create();
		$form->addText('note', 'Zrušeno usnesením')
			->setRequired('Uveďte, kterým usnesením bylo zrušeno.')
			->setMaxLength(500)
			->setHtmlAttribute('placeholder', 'Např. revokováno usnesením č. 12/2027');
		$form->addSubmit('revoke', 'Revokovat');
		$form->onSuccess[] = function (Form $form): void {
			$this->requirePermission(Resource::Resolution, Privilege::Manage);
			$data = $form->getValues(RevokeFormData::class);
			try {
				$this->resolutionFacade->revoke($this->requireResolution()->getId(), $data->note);
			} catch (ValidationException $e) {
				FormErrors::apply($form, $e);

				return;
			} catch (DomainException $e) {
				$form->addError($e->getMessage());

				return;
			}

			$this->flashMessage('Usnesení je označené jako revokované.', 'success');
			$this->redirect('this');
		};

		return $form;
	}

	/**
	 * @param callable(): void $change
	 */
	private function runTaskChange(int $taskId, callable $change, string $message): never
	{
		try {
			$task = $this->taskFacade->get($taskId);
		} catch (NotFoundException) {
			$this->error('Úkol neexistuje.');
		}

		if ($task->getResolution() !== $this->requireResolution()) {
			$this->error('Úkol neexistuje.');
		}

		try {
			$change();
			$this->flashMessage($message, 'success');
		} catch (DomainException $e) {
			$this->flashMessage($e->getMessage(), 'danger');
		}

		$this->redirect('this');
	}

	private function requireResolution(): Resolution
	{
		return $this->resolution ?? $this->error();
	}
}
