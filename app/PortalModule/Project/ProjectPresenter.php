<?php

declare(strict_types=1);

namespace App\PortalModule\Project;

use App\Model\Attachment\Attachment;
use App\Model\Attachment\AttachmentFacade;
use App\Model\Attachment\AttachmentPolicy;
use App\Model\Comment\Comment;
use App\Model\Comment\CommentAuthorRef;
use App\Model\Comment\CommentFacade;
use App\Model\Municipality\Module;
use App\Model\Project\Project;
use App\Model\Project\ProjectFacade;
use App\Model\Project\ProjectStatus;
use App\Model\Shared\Exception\DomainException;
use App\Model\Shared\Exception\NotFoundException;
use App\Model\Shared\Exception\ValidationException;
use App\PortalModule\PortalPresenter;
use App\UI\Form\FormErrors;
use App\UI\Form\FormFactory;
use App\UI\Form\FormUploads;
use Nette\Application\Attributes\Requires;
use Nette\Application\Responses\FileResponse;
use Nette\Application\UI\Form;

/**
 * Plány obce pro zastupitele: rozpracované plány a diskuse k nim.
 */
final class ProjectPresenter extends PortalPresenter
{
	private ?Project $project = null;

	public function __construct(
		private readonly ProjectFacade $projectFacade,
		private readonly CommentFacade $commentFacade,
		private readonly AttachmentFacade $attachmentFacade,
		private readonly AttachmentPolicy $attachmentPolicy,
		private readonly FormFactory $formFactory,
	) {
		parent::__construct();
	}

	protected function startup(): void
	{
		parent::startup();
		$this->requireModule($this->getMeeting()->getMunicipality(), Module::Plans);
	}

	public function renderDefault(): void
	{
		$this->template->projects = $this->projectFacade->find($this->getMeeting()->getMunicipality()->getId(), onlyActive: true);
	}

	public function actionDetail(int $id): void
	{
		try {
			$project = $this->projectFacade->get($id);
		} catch (NotFoundException $e) {
			$this->error($e->getMessage());
		}

		if ($project->getMunicipality() !== $this->getMeeting()->getMunicipality() || $project->getStatus() === ProjectStatus::Submitted) {
			$this->error('Plán neexistuje.');
		}

		$this->project = $project;
	}

	public function renderDetail(): void
	{
		$project = $this->project ?? $this->error();
		$memberId = $this->getMember()->getId();
		$this->template->project = $project;
		$this->template->timeline = $this->projectFacade->timeline($project->getId());
		$this->template->path = ProjectStatus::path();
		$this->template->canComment = true;
		$this->template->canDelete = static fn(Comment $c): bool => $c->getAuthorPersonId() === $memberId;
		$this->template->deleteLink = fn(Comment $c): string => $this->link('deleteComment!', [
			'commentId' => $c->getId(),
		]);
		$this->template->attachmentLink = fn(Attachment $a): string => $this->link('file', [
			'id' => $a->getId(),
		]);
		$this->template->visibilityLink = null;
	}

	public function actionFile(int $id): void
	{
		try {
			$attachment = $this->attachmentFacade->get($id);
		} catch (NotFoundException $e) {
			$this->error($e->getMessage());
		}

		if ($attachment->getProject()?->getMunicipality() !== $this->getMeeting()->getMunicipality()) {
			$this->error('Soubor neexistuje.');
		}

		$this->getHttpResponse()->setHeader('X-Content-Type-Options', 'nosniff');
		$this->sendResponse(new FileResponse(
			$this->attachmentFacade->getLocalPath($attachment),
			$attachment->getFile()->originalName,
			$attachment->getFile()->mimeType,
			!$attachment->canPreview(),
		));
	}

	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleDeleteComment(int $commentId): void
	{
		$project = $this->project ?? $this->error();
		try {
			$comment = $this->commentFacade->get($commentId);
			if ($comment->getProject() !== $project) {
				$this->error('Komentář nepatří k tomuto plánu.');
			}

			$this->commentFacade->deleteByPerson($comment->getId(), $this->getMember()->getId());
		} catch (DomainException $e) {
			$this->flashMessage($e->getMessage(), 'danger');
		}

		$this->redrawOrRedirect();
	}

	protected function createComponentCommentForm(): Form
	{
		$project = $this->project ?? $this->error();
		$form = $this->formFactory->create();
		$form->getElementPrototype()->addClass('ajax');
		$form->addHidden('parentId')->setNullable();
		$form->addTextArea('body', 'Komentář', null, 3)
			->setMaxLength(Comment::MaxHtmlLength)
			->setHtmlAttribute('placeholder', 'Názor, dotaz nebo informace k plánu…');
		$form->addMultiUpload('files', 'Soubory')
			->addRule(Form::MaxFileSize, 'Soubor je příliš velký.', $this->attachmentPolicy->maxSize);
		$form->addSubmit('send', 'Odeslat');
		$form->onSuccess[] = function (Form $form) use ($project): void {
			$data = $form->getValues(CommentFormData::class);
			try {
				$this->commentFacade->addToProject(
					$project->getId(),
					CommentAuthorRef::person($this->getMember()->getId()),
					(string) $data->body,
					is_numeric($data->parentId) ? (int) $data->parentId : null,
					FormUploads::toUploadedFiles($data->files),
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
}
