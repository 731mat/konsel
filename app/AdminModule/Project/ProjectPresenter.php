<?php

declare(strict_types=1);

namespace App\AdminModule\Project;

use App\AdminModule\SecuredPresenter;
use App\Core\Security\Allowed;
use App\Core\Security\Privilege;
use App\Core\Security\Resource;
use App\Model\Attachment\Attachment;
use App\Model\Attachment\AttachmentPolicy;
use App\Model\Comment\Comment;
use App\Model\Comment\CommentAuthorRef;
use App\Model\Comment\CommentFacade;
use App\Model\People\PeopleFacade;
use App\Model\Project\Command\SaveProject;
use App\Model\Project\Project;
use App\Model\Project\ProjectFacade;
use App\Model\Project\ProjectPriority;
use App\Model\Project\ProjectStatus;
use App\Model\Shared\Exception\DomainException;
use App\Model\Shared\Exception\NotFoundException;
use App\Model\Shared\Exception\ValidationException;
use App\UI\Form\FormErrors;
use App\UI\Form\FormFactory;
use App\UI\Form\FormUploads;
use Nette\Application\Attributes\Requires;
use Nette\Application\UI\Form;

/**
 * Úkolníček – dlouhodobé úkoly a záměry obce v jednom vlákně přes všechna zasedání.
 */
#[Allowed(Resource::Project)]
final class ProjectPresenter extends SecuredPresenter
{
	private ?Project $project = null;

	public function __construct(
		private readonly ProjectFacade $projectFacade,
		private readonly CommentFacade $commentFacade,
		private readonly PeopleFacade $peopleFacade,
		private readonly AttachmentPolicy $attachmentPolicy,
		private readonly FormFactory $formFactory,
	) {
		parent::__construct();
	}

	public function renderDefault(?string $status = null): void
	{
		$municipalityId = $this->requireMunicipality()->getId();
		$selected = ProjectStatus::tryFrom((string) $status);
		$this->template->projects = $status === 'all' ? $this->projectFacade->find($municipalityId)
			: ($selected !== null ? $this->projectFacade->find($municipalityId, $selected) : $this->projectFacade->find($municipalityId, onlyActive: true));
		$this->template->counts = $this->projectFacade->countByStatus($municipalityId);
		$this->template->statuses = ProjectStatus::cases();
		$this->template->selectedStatus = $status;
		$this->template->canEdit = $this->isAllowed(Resource::Project, Privilege::Edit);
	}

	#[Allowed(Resource::Project, Privilege::Edit)]
	public function actionAdd(): void
	{
		$this->requireMunicipality();
	}

	#[Allowed(Resource::Project, Privilege::Edit)]
	public function actionEdit(int $id): void
	{
		$project = $this->project = $this->loadProject($id);
		$this->getComponent('projectForm')->setDefaults([
			'title' => $project->getTitle(),
			'description' => $project->getDescription(),
			'priority' => $project->getPriority()->value,
			'responsible' => $project->getResponsible()?->getId(),
			'estimatedCost' => $project->getEstimatedCost(),
		]);
	}

	public function renderEdit(): void
	{
		$this->template->project = $this->project;
	}

	public function actionDetail(int $id): void
	{
		$this->project = $this->loadProject($id);
	}

	public function renderDetail(): void
	{
		$project = $this->project ?? $this->error();
		$canEdit = $this->isAllowed(Resource::Project, Privilege::Edit);
		$this->template->project = $project;
		$this->template->timeline = $this->projectFacade->timeline($project->getId());
		$this->template->linkableItems = $canEdit ? $this->projectFacade->findLinkableItems($project->getId()) : [];
		$this->template->canEdit = $canEdit;
		$this->template->canModerate = $this->isAllowed(Resource::Project, Privilege::Manage);
		$this->template->currentUserId = $this->currentUserId();
		$this->template->path = ProjectStatus::path();

		// parametry sdíleného bloku komentáře (app/UI/Comment/@discussion.latte)
		$moderator = $this->isAllowed(Resource::Project, Privilege::Manage);
		$userId = $this->currentUserId();
		$this->template->canComment = true;
		$this->template->canDelete = static fn(Comment $c): bool => $moderator || $c->getAuthorUserId() === $userId;
		$this->template->deleteLink = fn(Comment $c): string => $this->link('deleteComment!', [
			'commentId' => $c->getId(),
		]);
		$this->template->attachmentLink = fn(Attachment $a): string => $this->link('Attachment:preview', [
			'id' => $a->getId(),
		]);
		$this->template->visibilityLink = $canEdit ? fn(Comment $c, bool $public): string => $this->link('commentVisibility!', [
			'commentId' => $c->getId(),
			'public' => $public,
		]) : null;
		$this->template->statuses = ProjectStatus::cases();
	}

	#[Allowed(Resource::Project, Privilege::Edit)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleTogglePublic(): void
	{
		$project = $this->project ?? $this->error();
		try {
			$this->projectFacade->setPublic($project->getId(), !$project->isPublic());
			$this->flashMessage($project->isPublic() ? 'Úkol je skrytý před občany.' : 'Úkol je zveřejněný na stránce pro občany.', 'success');
		} catch (DomainException $e) {
			$this->flashMessage($e->getMessage(), 'danger');
		}

		$this->redirect('this');
	}

	#[Allowed(Resource::Project, Privilege::Edit)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleCommentVisibility(int $commentId, bool $public): void
	{
		$project = $this->project ?? $this->error();
		$comment = $this->assertOwned($this->commentFacade->get($commentId));
		if ($comment->getProject() !== $project) {
			$this->error('Komentář nepatří k tomuto úkolu.');
		}

		$this->commentFacade->changeVisibility($comment->getId(), $public);
		$this->flashMessage($public ? 'Komentář je vidět i občanům.' : 'Komentář je jen pro úřad a zastupitele.', 'success');
		$this->redrawOrRedirect();
	}

	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleDeleteComment(int $commentId): void
	{
		$project = $this->project ?? $this->error();
		try {
			$comment = $this->assertOwned($this->commentFacade->get($commentId));
			if ($comment->getProject() !== $project) {
				$this->error('Komentář nepatří k tomuto úkolu.');
			}

			$this->commentFacade->deleteByUser($comment->getId(), $this->currentUserId(), $this->isAllowed(Resource::Project, Privilege::Manage));
			$this->flashMessage('Komentář byl smazán.', 'success');
		} catch (DomainException $e) {
			$this->flashMessage($e->getMessage(), 'danger');
		}

		$this->redrawOrRedirect();
	}

	#[Allowed(Resource::Project, Privilege::Edit)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleUnlinkItem(int $itemId): void
	{
		$project = $this->project ?? $this->error();
		foreach ($this->projectFacade->findLinkedItems($project->getId()) as $item) {
			if ($item->getId() === $itemId) {
				$this->projectFacade->linkAgendaItem($itemId, null);
				$this->flashMessage("Bod „{$item->getTitle()}“ už není propojený s úkolem.", 'success');
			}
		}

		$this->redirect('this');
	}

	protected function createComponentProjectForm(): Form
	{
		$form = $this->formFactory->create();
		$priorities = [];
		foreach (ProjectPriority::cases() as $priority) {
			$priorities[$priority->value] = $priority->label();
		}

		$persons = [];
		foreach ($this->peopleFacade->findPersons($this->requireMunicipality()->getId()) as $person) {
			$persons[$person->getId()] = $person->getName()->sortable();
		}

		$form->addText('title', 'Název')
			->setRequired('Zadejte název úkolu.')
			->setMaxLength(200)
			->setHtmlAttribute('placeholder', 'Např. Výstavba nové mateřské školy');
		$form->addTextArea('description', 'Popis', null, 6)
			->setNullable()
			->setOption('description', 'Co je cílem, proč a v jakém rozsahu.');
		$form->addSelect('priority', 'Priorita', $priorities)
			->setDefaultValue(ProjectPriority::Normal->value);
		$form->addSelect('responsible', 'Odpovědná osoba', $persons)
			->setPrompt('— neurčeno —');
		$form->addInteger('estimatedCost', 'Odhad nákladů (Kč)')
			->setNullable()
			->addRule(Form::Min, 'Náklady nemohou být záporné.', 0);
		$form->addSubmit('save', $this->project === null ? 'Založit úkol' : 'Uložit');
		$form->onSuccess[] = function (Form $form): void {
			$data = $form->getValues(ProjectFormData::class);
			$command = new SaveProject($data->title, $data->description, ProjectPriority::from($data->priority), $data->responsible, $data->estimatedCost);
			try {
				if ($this->project === null) {
					$project = $this->projectFacade->create($this->requireMunicipality()->getId(), $command, $this->currentUserName());
					$this->flashMessage('Úkol byl založen.', 'success');
				} else {
					$this->projectFacade->update($this->project->getId(), $command);
					$project = $this->project;
					$this->flashMessage('Změny byly uloženy.', 'success');
				}
			} catch (ValidationException $e) {
				FormErrors::apply($form, $e);

				return;
			}

			$this->redirect('detail', [
				'id' => $project->getId(),
			]);
		};

		return $form;
	}

	protected function createComponentStatusForm(): Form
	{
		$this->requirePermission(Resource::Project, Privilege::Edit);
		$project = $this->project ?? $this->error();
		$statuses = [];
		foreach (ProjectStatus::cases() as $status) {
			if ($status !== $project->getStatus()) {
				$statuses[$status->value] = $status->label();
			}
		}

		$form = $this->formFactory->create();
		$path = ProjectStatus::path();
		$step = $project->getStatus()->step();
		$next = $step === null ? ProjectStatus::Idea : ($path[$step] ?? null);
		$form->addSelect('status', 'Nová fáze', $statuses)
			->setRequired()
			->setDefaultValue($next !== null && $next !== $project->getStatus() ? $next->value : null);
		$form->addText('note', 'Poznámka')
			->setNullable()
			->setMaxLength(500)
			->setHtmlAttribute('placeholder', 'Např. schváleno usnesením č. 12/2026');
		$form->addSubmit('save', 'Změnit fázi');
		$form->onSuccess[] = function (Form $form) use ($project): void {
			$data = $form->getValues(StatusFormData::class);
			try {
				$this->projectFacade->changeStatus($project->getId(), ProjectStatus::from($data->status), $this->currentUserName(), $data->note);
			} catch (DomainException $e) {
				$form->addError($e->getMessage());

				return;
			}

			$this->flashMessage('Fáze úkolu byla změněna.', 'success');
			$this->redirect('this');
		};

		return $form;
	}

	protected function createComponentLinkForm(): Form
	{
		$this->requirePermission(Resource::Project, Privilege::Edit);
		$project = $this->project ?? $this->error();
		$items = [];
		foreach ($this->projectFacade->findLinkableItems($project->getId()) as $item) {
			$meeting = $item->getMeeting();
			$items[$meeting->getTitle() . ' · ' . $meeting->getScheduledAt()->format('j. n. Y')][$item->getId()] = "{$item->getPosition()}. {$item->getTitle()}";
		}

		$form = $this->formFactory->create();
		$form->addSelect('item', 'Bod zasedání', $items)
			->setPrompt('— vyberte bod —')
			->setRequired('Vyberte bod programu.');
		$form->addSubmit('link', 'Propojit');
		$form->onSuccess[] = function (Form $form) use ($project): void {
			$itemId = $form->getValues(LinkFormData::class)->item;
			if ($itemId === null) {
				$form->addError('Vyberte bod programu.');

				return;
			}

			try {
				$this->projectFacade->linkAgendaItem($itemId, $project->getId());
			} catch (DomainException $e) {
				$form->addError($e->getMessage());

				return;
			}

			$this->flashMessage('Bod zasedání byl propojen s úkolem.', 'success');
			$this->redirect('this');
		};

		return $form;
	}

	protected function createComponentCommentForm(): Form
	{
		$project = $this->project ?? $this->error();
		$form = $this->formFactory->create();
		$form->getElementPrototype()->addClass('ajax');
		$form->addHidden('parentId')->setNullable();
		$form->addTextArea('body', 'Komentář', null, 3)
			->setMaxLength(Comment::MaxHtmlLength)
			->setHtmlAttribute('placeholder', 'Novinka, dotaz nebo informace k úkolu…');
		$form->addMultiUpload('files', 'Soubory')
			->addRule(Form::MaxFileSize, 'Soubor je příliš velký.', $this->attachmentPolicy->maxSize);
		$form->addCheckbox('publicForCitizens', 'Zveřejnit i občanům');
		$form->addSubmit('send', 'Odeslat');
		$form->onSuccess[] = function (Form $form) use ($project): void {
			$data = $form->getValues(CommentFormData::class);
			try {
				$this->commentFacade->addToProject(
					$project->getId(),
					CommentAuthorRef::user($this->currentUserId()),
					(string) $data->body,
					is_numeric($data->parentId) ? (int) $data->parentId : null,
					FormUploads::toUploadedFiles($data->files),
					$data->publicForCitizens && $this->isAllowed(Resource::Project, Privilege::Edit),
				);
			} catch (ValidationException $e) {
				FormErrors::apply($form, $e);

				return;
			} catch (DomainException $e) {
				$form->addError($e->getMessage());

				return;
			}

			$form->reset();
			$this->redrawOrRedirect();
		};
		$form->onError[] = function (): void {
			if ($this->isAjax()) {
				$this->redrawControl('thread');
			}
		};

		return $form;
	}

	protected function createComponentFileForm(): Form
	{
		$this->requirePermission(Resource::Project, Privilege::Edit);
		$project = $this->project ?? $this->error();
		$form = $this->formFactory->create();
		$form->addMultiUpload('files', 'Soubory')
			->setRequired('Vyberte soubor.')
			->addRule(Form::MaxFileSize, 'Soubor je příliš velký.', $this->attachmentPolicy->maxSize);
		$form->addSubmit('upload', 'Nahrát');
		$form->onSuccess[] = function (Form $form) use ($project): void {
			$files = FormUploads::toUploadedFiles($form->getValues(FileFormData::class)->files);
			if ($files === []) {
				$form->addError('Soubor se nepodařilo nahrát.');

				return;
			}

			try {
				$this->projectFacade->uploadFiles($project->getId(), $files, $this->currentUserId());
			} catch (ValidationException $e) {
				FormErrors::apply($form, $e);

				return;
			}

			$this->flashMessage('Soubory byly nahrány.', 'success');
			$this->redirect('this');
		};

		return $form;
	}

	private function redrawOrRedirect(): void
	{
		if ($this->isAjax()) {
			$this->redrawControl('thread');
			$this->redrawControl('flashes');

			return;
		}

		$this->redirect('this');
	}

	private function currentUserId(): int
	{
		$id = $this->getUser()->getId();

		return is_int($id) ? $id : $this->error('Nepřihlášený uživatel.', 403);
	}

	private function currentUserName(): string
	{
		$name = $this->getUser()->getIdentity()?->getData()['name'] ?? null;

		return is_string($name) ? $name : 'Úřad';
	}

	private function loadProject(int $id): Project
	{
		try {
			return $this->assertOwned($this->projectFacade->get($id));
		} catch (NotFoundException $e) {
			$this->error($e->getMessage());
		}
	}
}
