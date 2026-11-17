<?php

declare(strict_types=1);

namespace App\AdminModule\Agenda;

use App\AdminModule\SecuredPresenter;
use App\Core\Security\Allowed;
use App\Core\Security\Privilege;
use App\Core\Security\Resource;
use App\Model\Ai\AgendaDraft;
use App\Model\Ai\AgendaWritingFacade;
use App\Model\Ai\AiException;
use App\Model\Ai\AiFacade;
use App\Model\Ai\AiPurpose;
use App\Model\Attachment\AttachmentFacade;
use App\Model\Attachment\AttachmentPolicy;
use App\Model\Comment\Comment;
use App\Model\Comment\CommentFacade;
use App\Model\Meeting\AgendaFacade;
use App\Model\Meeting\AgendaItem;
use App\Model\Meeting\Command\SaveAgendaItem;
use App\Model\Meeting\Meeting;
use App\Model\Meeting\MeetingFacade;
use App\Model\Municipality\Module;
use App\Model\People\PeopleFacade;
use App\Model\Project\ProjectFacade;
use App\Model\Shared\Exception\DomainException;
use App\Model\Shared\Exception\NotFoundException;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Template\TemplateFacade;
use App\UI\Form\FormErrors;
use App\UI\Form\FormFactory;
use App\UI\Form\FormUploads;
use Nette\Application\Attributes\Requires;
use Nette\Application\UI\Form;

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
		private readonly TemplateFacade $templateFacade,
		private readonly ProjectFacade $projectFacade,
		private readonly AiFacade $aiFacade,
		private readonly AgendaWritingFacade $writingFacade,
		private readonly FormFactory $formFactory,
	) {
		parent::__construct();
	}

	/**
	 * @param ?int $parent nový bod jako podbod tohoto bodu
	 */
	#[Allowed(Resource::Agenda, Privilege::Edit)]
	public function actionAdd(int $meeting, ?int $parent = null): void
	{
		$this->meeting = $loaded = $this->loadMeeting($meeting);
		$this->assertProgramEditable($loaded);
		$parentItem = $parent === null ? null : $loaded->findAgendaItem($parent);
		if ($parentItem !== null && !$parentItem->isSubItem()) {
			$this->getComponent('itemForm')->setDefaults([
				'parent' => $parentItem->getId(),
			]);
		}
	}

	public function renderAdd(): void
	{
		$this->template->meeting = $this->meeting;
		$this->template->resolutionTemplates = $this->templateFacade->renderResolutionTemplates(($this->meeting ?? $this->error())->getId());
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
			'parent' => $item->getParent()?->getId(),
			...($item->getMunicipality()->isModuleEnabled(Module::Plans) ? [
				'project' => $item->getProject()?->getId(),
			] : []),
		]);
	}

	public function renderEdit(): void
	{
		$this->template->item = $this->item;
		$this->template->meeting = $this->meeting;
		$this->template->resolutionTemplates = $this->templateFacade->renderResolutionTemplates(($this->meeting ?? $this->error())->getId());
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
			->setMaxLength(Comment::MaxHtmlLength)
			->setHtmlAttribute('placeholder', 'Připomínka, dotaz nebo doplnění k bodu…');
		$form->addMultiUpload('files', 'Soubory')
			->addRule(Form::MaxFileSize, 'Soubor je příliš velký.', $this->attachmentPolicy->maxSize);
		$form->addSubmit('send', 'Odeslat');
		$form->onSuccess[] = function (Form $form) use ($item): void {
			$data = $form->getValues(CommentFormData::class);
			$files = FormUploads::toUploadedFiles($data->files);
			try {
				$this->commentFacade->addByUser($item->getId(), $this->currentUserId(), (string) $data->body, is_numeric($data->parentId) ? (int) $data->parentId : null, $files);
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
			$files = FormUploads::toUploadedFiles($data->files);
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

	/**
	 * Návrh textu pomocí AI z rozepsaného formuláře (AJAX, odpověď JSON {text} nebo {error}). Nic neukládá.
	 */
	#[Allowed(Resource::Agenda, Privilege::Edit)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleSuggest(int $meeting, string $purpose): void
	{
		$loaded = $this->loadMeeting($meeting);
		$selected = AiPurpose::tryFrom($purpose) ?? $this->error('Neznámý druh návrhu.');
		$post = fn(string $name): ?string => is_string($value = $this->getRequest()->getPost($name)) && trim($value) !== '' ? $value : null;
		$parent = $post('parent');
		try {
			$text = $this->writingFacade->suggest($loaded->getId(), $selected, new AgendaDraft(
				(string) $post('title'),
				$post('description'),
				$post('proposedResolution'),
				$parent !== null && ctype_digit($parent) ? (int) $parent : null,
			), $this->currentUserId());
		} catch (DomainException|AiException $e) {
			$this->sendJson([
				'error' => $e->getMessage(),
			]);
		}

		$this->sendJson([
			'text' => $text,
		]);
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

		// podbod jde vložit jen pod hlavní bod; bod, který sám má podbody, podbodem být nemůže
		$parents = [];
		foreach ($meeting->getAgendaItems() as $candidate) {
			if (!$candidate->isSubItem() && $candidate !== $this->item) {
				$parents[$candidate->getId()] = "{$candidate->getNumber()}. {$candidate->getTitle()}";
			}
		}

		$parentSelect = $form->addSelect('parent', 'Podbod bodu', $parents)
			->setPrompt('— hlavní bod programu —')
			->setOption('description', 'O každém podbodu se hlasuje zvlášť a má vlastní návrh usnesení (např. 26.1 schválení projektu, 26.2 dodavatele).');
		if ($this->item?->hasSubItems() === true) {
			$parentSelect->setDisabled()->setOption('description', 'Bod má podbody, sám podbodem být nemůže.');
		}
		$plansEnabled = $meeting->getMunicipality()->isModuleEnabled(Module::Plans);
		if ($plansEnabled) {
			$projects = [];
			foreach ($this->projectFacade->find($meeting->getMunicipality()->getId(), onlyActive: true) as $project) {
				$projects[$project->getId()] = $project->getTitle();
			}

			if ($this->item?->getProject() !== null) {
				$projects[$this->item->getProject()->getId()] = $this->item->getProject()->getTitle();
			}

			$form->addSelect('project', 'Plán', $projects)
				->setPrompt('— bez plánu —')
				->setOption('description', 'Bod se objeví ve vlákně plánu (např. schválení projektové dokumentace, investice).');
		}

		$description = $form->addTextArea('description', 'Důvodová zpráva / popis', null, 8)
			->setNullable()
			->setOption('description', 'Prostý text, odstavce oddělte prázdným řádkem.');
		$proposal = $form->addTextArea('proposedResolution', 'Návrh usnesení', null, 6)
			->setNullable()
			->setHtmlAttribute('placeholder', "{$meeting->getBody()->getName()} schvaluje …")
			->setOption('description', 'Text, o kterém se bude hlasovat. Bod bez usnesení (např. informace, diskuse) nechte prázdný.');
		// tlačítko „Navrhnout pomocí AI“ doplní JS, jen když má obec AI zapnutou
		if ($this->aiFacade->isAvailable($meeting->getMunicipality()->getId())) {
			foreach ([[$description, AiPurpose::AgendaDescription], [$proposal, AiPurpose::ProposedResolution]] as [$field, $purpose]) {
				$field->setHtmlAttribute('data-ai-suggest', $this->link('suggest!', [
					'meeting' => $meeting->getId(),
					'purpose' => $purpose->value,
				]));
			}
		}
		$form->addSubmit('save', $this->item === null ? 'Přidat bod' : 'Uložit');
		$form->onSuccess[] = function (Form $form) use ($meeting, $plansEnabled): void {
			$data = $form->getValues(ItemFormData::class);
			$command = new SaveAgendaItem($data->title, $data->description, $data->proposedResolution, $data->rapporteur);
			try {
				if ($this->item === null) {
					$item = $this->agendaFacade->addItem($meeting->getId(), $command, $data->parent);
					$this->flashMessage("Bod „{$item->getTitle()}“ byl přidán do programu.", 'success');
				} else {
					$this->agendaFacade->updateItem($this->item->getId(), $command);
					$item = $this->item;
					if (!$this->item->hasSubItems() && $data->parent !== $this->item->getParent()?->getId()) {
						$this->agendaFacade->changeParent($this->item->getId(), $data->parent);
					}
					$this->flashMessage('Změny byly uloženy.', 'success');
				}

				// při vypnutých plánech pole ve formuláři není a propojení zůstane, jak bylo
				if ($plansEnabled) {
					$this->projectFacade->linkAgendaItem($item->getId(), $data->project);
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
