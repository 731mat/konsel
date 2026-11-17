<?php

declare(strict_types=1);

namespace App\PortalModule\Meeting;

use App\Model\Attachment\Attachment;
use App\Model\Attachment\AttachmentFacade;
use App\Model\Attachment\AttachmentPolicy;
use App\Model\Comment\Comment;
use App\Model\Comment\CommentFacade;
use App\Model\Document\MeetingDocumentFacade;
use App\Model\Document\MeetingDocumentType;
use App\Model\Meeting\AgendaItem;
use App\Model\Shared\Exception\DomainException;
use App\Model\Shared\Exception\NotFoundException;
use App\Model\Shared\Exception\ValidationException;
use App\PortalModule\PortalPresenter;
use App\UI\Form\FormErrors;
use App\UI\Form\FormFactory;
use App\UI\Form\FormUploads;
use Nette\Application\Attributes\Requires;
use Nette\Application\Responses\CallbackResponse;
use Nette\Application\Responses\FileResponse;
use Nette\Application\UI\Form;

/**
 * Pohled zastupitele: program, podklady, přílohy, dokumenty a diskuse k bodům jednoho zasedání.
 */
final class MeetingPresenter extends PortalPresenter
{
	private ?AgendaItem $item = null;

	public function __construct(
		private readonly AttachmentFacade $attachmentFacade,
		private readonly CommentFacade $commentFacade,
		private readonly MeetingDocumentFacade $documentFacade,
		private readonly AttachmentPolicy $attachmentPolicy,
		private readonly FormFactory $formFactory,
	) {
		parent::__construct();
	}

	public function renderDefault(): void
	{
		$meeting = $this->getMeeting();
		$this->template->attachmentCounts = $this->attachmentFacade->countPublicByMeeting($meeting);
		$this->template->commentCounts = $this->commentFacade->countByMeeting($meeting);
		$this->template->documentTypes = array_values(array_filter(
			MeetingDocumentType::cases(),
			fn(MeetingDocumentType $type): bool => $type->isAvailableFor($this->getMeeting()->getStatus()),
		));
	}

	public function actionItem(int $id): void
	{
		$this->item = $this->getMeeting()->findAgendaItem($id) ?? $this->error('Bod programu neexistuje.');
	}

	public function renderItem(): void
	{
		$item = $this->item ?? $this->error();
		$items = $this->getMeeting()->getAgendaItems();
		$index = array_search($item, $items, true);

		$this->template->item = $item;
		$this->template->attachments = array_values(array_filter(
			$this->attachmentFacade->findByAgendaItem($item->getId()),
			static fn(Attachment $attachment): bool => $attachment->isPublicForMembers(),
		));
		$this->template->threads = $this->commentFacade->findThreads($item->getId());
		$this->template->canComment = $this->getMeeting()->canEditAttachments();
		$this->template->memberId = $this->getMember()->getId();
		$this->template->previous = is_int($index) ? ($items[$index - 1] ?? null) : null;
		$this->template->next = is_int($index) ? ($items[$index + 1] ?? null) : null;
	}

	public function actionAttachment(int $id, bool $download = false): void
	{
		try {
			$attachment = $this->attachmentFacade->get($id);
		} catch (NotFoundException $e) {
			$this->error($e->getMessage());
		}

		if ($attachment->getMeeting() !== $this->getMeeting() || !$attachment->isPublicForMembers()) {
			$this->error('Příloha neexistuje.');
		}

		$this->getHttpResponse()->setHeader('X-Content-Type-Options', 'nosniff');
		$this->sendResponse(new FileResponse(
			$this->attachmentFacade->getLocalPath($attachment),
			$attachment->getFile()->originalName,
			$attachment->getFile()->mimeType,
			$download || !$attachment->canPreview(),
		));
	}

	public function actionDocument(string $type): void
	{
		$documentType = MeetingDocumentType::tryFrom($type) ?? $this->error('Neznámý typ dokumentu.');
		if (!$documentType->isAvailableFor($this->getMeeting()->getStatus())) {
			$this->error('Dokument zatím není k dispozici.');
		}

		$document = $this->documentFacade->generate($this->getMeeting()->getId(), $documentType);
		$response = $this->getHttpResponse();
		$response->setContentType($document->mimeType);
		$response->setHeader('Content-Disposition', sprintf('inline; filename="%s"', $document->fileName));
		$this->sendResponse(new CallbackResponse(static function () use ($document): void {
			echo $document->content;
		}));
	}

	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleDeleteComment(int $commentId): void
	{
		$item = $this->item ?? $this->error();
		try {
			$comment = $this->commentFacade->get($commentId);
			if ($comment->getAgendaItem() !== $item) {
				$this->error('Komentář nepatří k tomuto bodu.');
			}

			$this->commentFacade->deleteByPerson($comment->getId(), $this->getMember()->getId());
			$this->flashMessage('Komentář byl smazán.', 'success');
		} catch (DomainException $e) {
			$this->flashMessage($e->getMessage(), 'danger');
		}

		$this->redrawOrRedirect();
	}

	protected function createComponentCommentForm(): Form
	{
		$item = $this->item ?? $this->error();
		$form = $this->formFactory->create();
		$form->getElementPrototype()->addClass('ajax');
		$form->addHidden('parentId')->setNullable();
		$form->addTextArea('body', 'Komentář', null, 3)
			->setMaxLength(Comment::MaxHtmlLength)
			->setHtmlAttribute('placeholder', 'Dotaz nebo připomínka k bodu – uvidí ji úřad i ostatní zastupitelé…');
		$form->addMultiUpload('files', 'Soubory')
			->addRule(Form::MaxFileSize, 'Soubor je příliš velký.', $this->attachmentPolicy->maxSize);
		$form->addSubmit('send', 'Odeslat');
		$form->onSuccess[] = function (Form $form) use ($item): void {
			$data = $form->getValues(CommentFormData::class);
			$files = FormUploads::toUploadedFiles($data->files);
			try {
				$this->commentFacade->addByPerson($item->getId(), $this->getMember()->getId(), (string) $data->body, is_numeric($data->parentId) ? (int) $data->parentId : null, $files);
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
				$this->redrawControl('comments');
			}
		};

		return $form;
	}

	private function redrawOrRedirect(): void
	{
		if ($this->isAjax()) {
			$this->redrawControl('comments');
			$this->redrawControl('flashes');

			return;
		}

		$this->redirect('this');
	}
}
