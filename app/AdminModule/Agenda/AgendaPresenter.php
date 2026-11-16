<?php

declare(strict_types=1);

namespace App\AdminModule\Agenda;

use App\AdminModule\SecuredPresenter;
use App\Core\Security\Allowed;
use App\Core\Security\Privilege;
use App\Core\Security\Resource;
use App\Model\Attachment\AttachmentFacade;
use App\Model\Attachment\AttachmentPolicy;
use App\Model\Comment\CommentFacade;
use App\Model\Meeting\AgendaFacade;
use App\Model\Meeting\AgendaItem;
use App\Model\Meeting\Command\SaveAgendaItem;
use App\Model\Meeting\Meeting;
use App\Model\Meeting\MeetingFacade;
use App\Model\People\PeopleFacade;
use App\Model\Shared\Exception\DomainException;
use App\Model\Shared\Exception\NotFoundException;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Storage\UploadedFile;
use App\UI\Form\FormErrors;
use App\UI\Form\FormFactory;
use Nette\Application\Attributes\Requires;
use Nette\Application\UI\Form;
use Nette\Http\FileUpload;

/**
 * Bod programu: založení, úprava, detail (text, návrh usnesení, přílohy, komentáře).
 */
#[Allowed(Resource::Agenda)]
final class AgendaPresenter extends SecuredPresenter
{
	private ?Meeting $meeting = null;

	private ?AgendaItem $item = null;

	public function __construct(
		private readonly AgendaFacade $agendaFacade,
		private readonly MeetingFacade $meetingFacade,
		private readonly PeopleFacade $peopleFacade,
		private readonly AttachmentFacade $attachmentFacade,
		private readonly AttachmentPolicy $attachmentPolicy,
		private readonly CommentFacade $commentFacade,
		private readonly FormFactory $formFactory,
	) {
		parent::__construct();
	}

	#[Allowed(Resource::Agenda, Privilege::Edit)]
	public function actionAdd(int $meeting): void
	{
		$this->meeting = $this->loadMeeting($meeting);
		$this->assertProgramEditable($this->meeting);
	}

	public function renderAdd(): void
	{
		$this->template->meeting = $this->meeting;
	}

	#[Allowed(Resource::Agenda, Privilege::Edit)]
	public function actionEdit(int $id): void
	{
		$item = $this->item = $this->loadItem($id);
		$this->meeting = $item->getMeeting();
		$this->assertProgramEditable($item->getMeeting());
		$this->getComponent('itemForm')->setDefaults([
			'title' => $item->getTitle(),
			'rapporteur' => $item->getRapporteur()?->getId(),
			'description' => $item->getDescription(),
			'proposedResolution' => $item->getProposedResolution(),
		]);
	}

	public function renderEdit(): void
	{
		$this->template->item = $this->item;
		$this->template->meeting = $this->meeting;
	}

	public function actionDetail(int $id): void
	{
		$this->item = $this->loadItem($id);
		$this->meeting = $this->item->getMeeting();
	}

	public function renderDetail(): void
	{
		$meeting = $this->meeting ?? $this->error();
		$this->template->item = $this->item;
		$this->template->meeting = $meeting;
		$this->template->canEdit = $meeting->canEditProgram() && $this->isAllowed(Resource::Agenda, Privilege::Edit);
		$this->template->canDelete = $meeting->canEditProgram() && $this->isAllowed(Resource::Agenda, Privilege::Delete);
		$this->template->attachments = $this->attachmentFacade->findByAgendaItem(($this->item ?? $this->error())->getId());
		$this->template->canUpload = $meeting->canEditAttachments() && $this->isAllowed(Resource::Attachment, Privilege::Edit);
		$this->template->canDeleteAttachment = $meeting->canEditAttachments() && $this->isAllowed(Resource::Attachment, Privilege::Delete);
		$this->template->threads = $this->commentFacade->findThreads(($this->item ?? $this->error())->getId());
		$this->template->canComment = $meeting->canEditAttachments();
		$this->template->canModerate = $this->isAllowed(Resource::Agenda, Privilege::Manage);
		$this->template->currentUserId = $this->currentUserId();
		$items = $meeting->getAgendaItems();
		$index = array_search($this->item, $items, true);
		$this->template->previous = is_int($index) ? ($items[$index - 1] ?? null) : null;
		$this->template->next = is_int($index) ? ($items[$index + 1] ?? null) : null;
	}

	#[Allowed(Resource::Agenda, Privilege::Delete)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleDelete(): void
	{
		$item = $this->item ?? $this->error();
		$meetingId = $item->getMeeting()->getId();
		try {
			$this->agendaFacade->removeItem($item->getId());
			$this->flashMessage("Bod „{$item->getTitle()}“ byl odstraněn.", 'success');
		} catch (DomainException $e) {
			$this->flashMessage($e->getMessage(), 'danger');
		}

		$this->redirect('Meeting:detail', [
			'id' => $meetingId,
		]);
	}

	#[Allowed(Resource::Attachment, Privilege::Delete)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleDeleteAttachment(int $attachmentId): void
	{
		$item = $this->item ?? $this->error();
		try {
			$attachment = $this->assertOwned($this->attachmentFacade->get($attachmentId));
			if ($attachment->getAgendaItem() !== $item) {
				$this->error('Příloha nepatří k tomuto bodu.');
			}

			$this->attachmentFacade->delete($attachment->getId());
			$this->flashMessage("Příloha „{$attachment->getTitle()}“ byla smazána.", 'success');
		} catch (DomainException $e) {
			$this->flashMessage($e->getMessage(), 'danger');
		}

		$this->redirect('this');
	}

	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleDeleteComment(int $commentId): void
	{
		$item = $this->item ?? $this->error();
		try {
			$comment = $this->assertOwned($this->commentFacade->get($commentId));
			if ($comment->getAgendaItem() !== $item) {
				$this->error('Komentář nepatří k tomuto bodu.');
			}

			$this->commentFacade->deleteByUser($comment->getId(), $this->currentUserId(), $this->isAllowed(Resource::Agenda, Privilege::Manage));
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
			->setRequired('Napište text komentáře.')
			->setMaxLength(5000)
			->setHtmlAttribute('placeholder', 'Připomínka, dotaz nebo doplnění k bodu…');
		$form->addSubmit('send', 'Odeslat');
		$form->onSuccess[] = function (Form $form) use ($item): void {
			$data = $form->getValues(CommentFormData::class);
			try {
				$this->commentFacade->addByUser($item->getId(), $this->currentUserId(), $data->body, is_numeric($data->parentId) ? (int) $data->parentId : null);
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

	protected function createComponentAttachmentForm(): Form
	{
		$this->requirePermission(Resource::Attachment, Privilege::Edit);
		$item = $this->item ?? $this->error();
		$maxMb = intdiv($this->attachmentPolicy->maxSize, 1024 * 1024);

		$form = $this->formFactory->create();
		$form->addMultiUpload('files', 'Soubory')
			->setRequired('Vyberte alespoň jeden soubor.')
			->addRule(Form::MaxFileSize, "Soubor může mít nejvýše {$maxMb} MB.", $this->attachmentPolicy->maxSize)
			->setHtmlAttribute('accept', '.' . implode(',.', AttachmentPolicy::AllowedExtensions))
			->setOption('description', "Můžete vybrat více souborů najednou (max. {$maxMb} MB na soubor). PDF, Word, Excel, obrázky, ZIP…");
		$form->addCheckbox('publicForMembers', 'Zpřístupnit zastupitelům')
			->setDefaultValue(true);
		$form->addSubmit('upload', 'Nahrát');
		$form->onSuccess[] = function (Form $form) use ($item): void {
			$data = $form->getValues(AttachmentFormData::class);
			$files = array_values(array_map(
				static fn(FileUpload $upload): UploadedFile => UploadedFile::fromPath($upload->getTemporaryFile(), $upload->getUntrustedName()),
				array_filter($data->files, static fn(FileUpload $upload): bool => $upload->isOk()),
			));
			if ($files === []) {
				$form->addError('Soubor se nepodařilo nahrát.');

				return;
			}

			try {
				$identityId = $this->getUser()->getId();
				$uploaded = $this->attachmentFacade->upload($item->getId(), $files, is_int($identityId) ? $identityId : null, $data->publicForMembers);
			} catch (ValidationException $e) {
				FormErrors::apply($form, $e);

				return;
			} catch (DomainException $e) {
				$form->addError($e->getMessage());

				return;
			}

			$this->flashMessage(count($uploaded) === 1 ? 'Příloha byla nahrána.' : 'Nahráno ' . count($uploaded) . ' příloh.', 'success');
			$this->redirect('this');
		};

		return $form;
	}

	protected function createComponentItemForm(): Form
	{
		$meeting = $this->meeting ?? $this->error();
		$form = $this->formFactory->create();

		$persons = [];
		foreach ($this->peopleFacade->findPersons($meeting->getMunicipality()->getId()) as $person) {
			$persons[$person->getId()] = $person->getName()->sortable();
		}

		$form->addText('title', 'Název bodu')
			->setRequired('Zadejte název bodu.')
			->setMaxLength(300)
			->setHtmlAttribute('placeholder', 'Např. Rozpočtové opatření č. 5/2026');
		$form->addSelect('rapporteur', 'Předkladatel', $persons)
			->setPrompt('— neurčeno —');
		$form->addTextArea('description', 'Důvodová zpráva / popis', null, 8)
			->setNullable()
			->setOption('description', 'Prostý text, odstavce oddělte prázdným řádkem.');
		$form->addTextArea('proposedResolution', 'Návrh usnesení', null, 6)
			->setNullable()
			->setHtmlAttribute('placeholder', "{$meeting->getBody()->getName()} schvaluje …")
			->setOption('description', 'Text, o kterém se bude hlasovat. Bod bez usnesení (např. informace, diskuse) nechte prázdný.');
		$form->addSubmit('save', $this->item === null ? 'Přidat bod' : 'Uložit');
		$form->onSuccess[] = function (Form $form) use ($meeting): void {
			$data = $form->getValues(ItemFormData::class);
			$command = new SaveAgendaItem($data->title, $data->description, $data->proposedResolution, $data->rapporteur);
			try {
				if ($this->item === null) {
					$item = $this->agendaFacade->addItem($meeting->getId(), $command);
					$this->flashMessage("Bod „{$item->getTitle()}“ byl přidán do programu.", 'success');
				} else {
					$this->agendaFacade->updateItem($this->item->getId(), $command);
					$item = $this->item;
					$this->flashMessage('Změny byly uloženy.', 'success');
				}
			} catch (ValidationException $e) {
				FormErrors::apply($form, $e);

				return;
			} catch (DomainException $e) {
				$form->addError($e->getMessage());

				return;
			}

			$this->redirect('detail', [
				'id' => $item->getId(),
			]);
		};

		return $form;
	}

	private function currentUserId(): int
	{
		$id = $this->getUser()->getId();

		return is_int($id) ? $id : $this->error('Nepřihlášený uživatel.', 403);
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

	private function assertProgramEditable(Meeting $meeting): void
	{
		if (!$meeting->canEditProgram()) {
			$this->flashMessage('Program lze měnit jen před zahájením zasedání.', 'warning');
			$this->redirect('Meeting:detail', [
				'id' => $meeting->getId(),
			]);
		}
	}

	private function loadMeeting(int $id): Meeting
	{
		try {
			return $this->assertOwned($this->meetingFacade->get($id));
		} catch (NotFoundException $e) {
			$this->error($e->getMessage());
		}
	}

	private function loadItem(int $id): AgendaItem
	{
		try {
			return $this->assertOwned($this->agendaFacade->getItem($id));
		} catch (NotFoundException $e) {
			$this->error($e->getMessage());
		}
	}
}
