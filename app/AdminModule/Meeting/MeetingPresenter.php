<?php

declare(strict_types=1);

namespace App\AdminModule\Meeting;

use App\AdminModule\SecuredPresenter;
use App\Core\Security\Allowed;
use App\Core\Security\Privilege;
use App\Core\Security\Resource;
use App\Model\Attachment\AttachmentFacade;
use App\Model\Comment\CommentFacade;
use App\Model\Meeting\AgendaFacade;
use App\Model\Meeting\Command\AssignOfficials;
use App\Model\Meeting\Command\SaveMeeting;
use App\Model\Meeting\Meeting;
use App\Model\Meeting\MeetingFacade;
use App\Model\Meeting\MeetingStatus;
use App\Model\Meeting\MeetingTransition;
use App\Model\Meeting\MeetingType;
use App\Model\People\Body;
use App\Model\People\PeopleFacade;
use App\Model\Shared\Exception\DomainException;
use App\Model\Shared\Exception\NotFoundException;
use App\Model\Shared\Exception\ValidationException;
use App\UI\Form\FormErrors;
use App\UI\Form\FormFactory;
use Nette\Application\Attributes\Requires;
use Nette\Application\UI\Form;

/**
 * Zasedání: seznam, založení, detail se záložkami a přechody životního cyklu.
 */
#[Allowed(Resource::Meeting)]
final class MeetingPresenter extends SecuredPresenter
{
	/**
	 * Záložky detailu zasedání (obsah doplní další tasky).
	 */
	public const array Tabs = [
		'program' => 'Program',
		'proceedings' => 'Průběh',
		'resolutions' => 'Usnesení',
		'documents' => 'Dokumenty',
	];

	private ?Meeting $meeting = null;

	public function __construct(
		private readonly MeetingFacade $meetingFacade,
		private readonly AgendaFacade $agendaFacade,
		private readonly AttachmentFacade $attachmentFacade,
		private readonly CommentFacade $commentFacade,
		private readonly PeopleFacade $peopleFacade,
		private readonly FormFactory $formFactory,
	) {
		parent::__construct();
	}

	/**
	 * Parametry filtru přicházejí z GET formuláře jako řetězce (prázdný = bez omezení).
	 */
	public function renderDefault(?string $body = null, ?string $year = null, ?string $status = null): void
	{
		$municipalityId = $this->requireMunicipality()->getId();
		$selectedBody = ctype_digit((string) $body) ? $this->loadBody((int) $body) : null;
		$year = ctype_digit((string) $year) ? (int) $year : null;
		$selectedStatus = MeetingStatus::tryFrom((string) $status);

		$this->template->meetings = $this->meetingFacade->find($municipalityId, $selectedBody?->getId(), $year, $selectedStatus);
		$this->template->bodies = $this->findBodies();
		$this->template->years = $this->meetingFacade->findYears($municipalityId);
		$this->template->statuses = MeetingStatus::cases();
		$this->template->selectedBody = $selectedBody;
		$this->template->selectedYear = $year;
		$this->template->selectedStatus = $selectedStatus;
		$this->template->canEdit = $this->isAllowed(Resource::Meeting, Privilege::Edit);
	}

	#[Allowed(Resource::Meeting, Privilege::Edit)]
	public function actionAdd(?int $body = null): void
	{
		$municipalityId = $this->requireMunicipality()->getId();
		$form = $this->getComponent('meetingForm');
		$form->setDefaults([
			'place' => $this->meetingFacade->suggestPlace($municipalityId),
		]);
		if ($body !== null) {
			$form->setDefaults([
				'body' => $this->loadBody($body)->getId(),
			]);
		}
	}

	public function renderAdd(): void
	{
		$this->template->hasBodies = $this->findBodies() !== [];
	}

	#[Allowed(Resource::Meeting, Privilege::Edit)]
	public function actionEdit(int $id): void
	{
		$this->meeting = $this->loadMeeting($id);
		if (!$this->meeting->canReschedule()) {
			$this->flashMessage('Datum, místo a typ lze měnit jen před zahájením zasedání.', 'warning');
			$this->redirect('detail', [
				'id' => $id,
			]);
		}

		$this->getComponent('meetingForm')->setDefaults([
			'type' => $this->meeting->getType()->value,
			'number' => $this->meeting->getNumber(),
			'scheduledAt' => $this->meeting->getScheduledAt(),
			'place' => $this->meeting->getPlace(),
		]);
	}

	public function renderEdit(): void
	{
		$this->template->meeting = $this->meeting;
	}

	public function actionDetail(int $id): void
	{
		$this->meeting = $this->loadMeeting($id);
	}

	public function renderDetail(string $tab = 'program'): void
	{
		$meeting = $this->meeting ?? $this->error();
		$canEdit = $this->isAllowed(Resource::Meeting, Privilege::Edit);
		$canManage = $this->isAllowed(Resource::Meeting, Privilege::Manage);

		$this->template->meeting = $meeting;
		$this->template->tab = array_key_exists($tab, self::Tabs) ? $tab : 'program';
		$this->template->tabs = self::Tabs;
		$this->template->transitions = array_values(array_filter(
			$meeting->getAvailableTransitions(),
			static fn(MeetingTransition $t): bool => $t === MeetingTransition::Reopen ? $canManage : $canEdit,
		));
		$this->template->warnings = $warnings = $this->meetingFacade->getWarnings($meeting);
		$this->template->confirmations = array_filter([
			MeetingTransition::Share->value => $warnings === [] ? null : implode(' ', $warnings) . ' Přesto sdílet?',
			MeetingTransition::Close->value => 'Ukončit zasedání?',
			MeetingTransition::ApproveMinutes->value => 'Po ověření zápisu už nepůjde měnit průběh zasedání ani funkce. Pokračovat?',
			MeetingTransition::Reopen->value => 'Znovu otevřít ukončené zasedání?',
		]);
		$this->template->canEdit = $canEdit;
		$this->template->attachmentCounts = $this->attachmentFacade->countByMeeting($meeting);
		$this->template->commentCounts = $this->commentFacade->countByMeeting($meeting);
		$this->template->canEditProgram = $meeting->canEditProgram() && $this->isAllowed(Resource::Agenda, Privilege::Edit);
		$this->template->canDelete = $this->isAllowed(Resource::Meeting, Privilege::Delete) && $meeting->getStatus() === MeetingStatus::Draft;
	}

	#[Allowed(Resource::Meeting, Privilege::Edit)]
	public function actionOfficials(int $id): void
	{
		$this->meeting = $this->loadMeeting($id);
		if (!$this->meeting->canAssignOfficials()) {
			$this->flashMessage('Po ověření zápisu už funkce nelze měnit.', 'warning');
			$this->redirect('detail', [
				'id' => $id,
			]);
		}

		$this->getComponent('officialsForm')->setDefaults([
			'chairperson' => $this->meeting->getChairperson()?->getId(),
			'recorder' => $this->meeting->getRecorder()?->getId(),
			'firstVerifier' => $this->meeting->getFirstVerifier()?->getId(),
			'secondVerifier' => $this->meeting->getSecondVerifier()?->getId(),
		]);
	}

	public function renderOfficials(): void
	{
		$this->template->meeting = $this->meeting;
	}

	/**
	 * Přechody životního cyklu kromě znovuotevření (to má vlastní signál s vyšším oprávněním).
	 */
	#[Allowed(Resource::Meeting, Privilege::Edit)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleTransition(string $transition): void
	{
		$meeting = $this->meeting ?? $this->error();
		$selected = MeetingTransition::tryFrom($transition);
		if ($selected === null || $selected === MeetingTransition::Reopen) {
			$this->error('Neznámá akce.');
		}

		$this->runTransition($meeting, $selected);
	}

	#[Allowed(Resource::Meeting, Privilege::Manage)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleReopen(): void
	{
		$this->runTransition($this->meeting ?? $this->error(), MeetingTransition::Reopen);
	}

	#[Allowed(Resource::Agenda, Privilege::Edit)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleInsertStandard(): void
	{
		$meeting = $this->meeting ?? $this->error();
		$this->runProgramChange(function () use ($meeting): void {
			$added = $this->agendaFacade->insertStandardItems($meeting->getId());
			$this->flashMessage($added === 0 ? 'Standardní body už v programu jsou.' : "Do programu bylo vloženo {$added} standardních bodů.", 'success');
		});
	}

	#[Allowed(Resource::Agenda, Privilege::Edit)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleMoveItem(int $itemId, int $position): void
	{
		$meeting = $this->meeting ?? $this->error();
		$item = $meeting->findAgendaItem($itemId) ?? $this->error('Bod programu neexistuje.');
		$this->runProgramChange(fn() => $this->agendaFacade->moveItem($item->getId(), $position));
	}

	/**
	 * Nové pořadí po přetažení myší (AJAX, POST order[] = ID bodů).
	 */
	#[Allowed(Resource::Agenda, Privilege::Edit)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleReorder(): void
	{
		$meeting = $this->meeting ?? $this->error();
		$order = $this->getRequest()->getPost('order');
		$ids = is_array($order) ? array_values(array_map(intval(...), array_filter($order, is_numeric(...)))) : [];
		$this->runProgramChange(fn() => $this->agendaFacade->reorder($meeting->getId(), $ids));
	}

	#[Allowed(Resource::Meeting, Privilege::Delete)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleDelete(): void
	{
		$meeting = $this->meeting ?? $this->error();
		try {
			$this->meetingFacade->delete($meeting->getId());
		} catch (DomainException $e) {
			$this->flashMessage($e->getMessage(), 'danger');
			$this->redirect('detail', [
				'id' => $meeting->getId(),
			]);
		}

		$this->flashMessage('Zasedání bylo smazáno.', 'success');
		$this->redirect('default');
	}

	protected function createComponentMeetingForm(): Form
	{
		$form = $this->formFactory->create();

		if ($this->meeting === null) {
			$bodies = [];
			foreach ($this->findBodies() as $termName => $termBodies) {
				foreach ($termBodies as $body) {
					$bodies[$termName][$body->getId()] = $body->getName();
				}
			}

			$form->addSelect('body', 'Orgán', $bodies)
				->setRequired('Vyberte orgán.');
		}

		$types = [];
		foreach (MeetingType::cases() as $type) {
			$types[$type->value] = $type->label();
		}

		$form->addSelect('type', 'Typ zasedání', $types)
			->setDefaultValue(MeetingType::Regular->value)
			->setRequired();
		$form->addDateTime('scheduledAt', 'Datum a čas')
			->setRequired('Zadejte datum a čas zasedání.');
		$form->addText('place', 'Místo konání')
			->setRequired('Zadejte místo konání.')
			->setMaxLength(200)
			->setHtmlAttribute('placeholder', 'Zasedací místnost obecního úřadu');
		$form->addInteger('number', 'Pořadové číslo')
			->setNullable()
			->addRule(Form::Range, 'Pořadové číslo musí být 1 až 999.', [1, 999])
			->setOption('description', $this->meeting === null ? 'Nechte prázdné – doplní se další číslo v pořadí volebního období.' : 'Pořadí zasedání orgánu ve volebním období.');
		$form->addSubmit('save', $this->meeting === null ? 'Založit zasedání' : 'Uložit');
		$form->onSuccess[] = $this->meetingFormSucceeded(...);

		return $form;
	}

	protected function createComponentOfficialsForm(): Form
	{
		$meeting = $this->meeting ?? $this->error();
		$form = $this->formFactory->create();

		$members = [];
		foreach ($meeting->getBody()->getMembersAt($meeting->getScheduledAt()) as $membership) {
			$members[$membership->getPerson()->getId()] = $membership->getPerson()->getName()->sortable();
		}

		$persons = [];
		foreach ($this->peopleFacade->findPersons($meeting->getMunicipality()->getId()) as $person) {
			$persons[$person->getId()] = $person->getName()->sortable();
		}

		$form->addSelect('chairperson', 'Předsedající', $members)->setPrompt('— neurčeno —');
		$form->addSelect('firstVerifier', 'Ověřovatel', $members)->setPrompt('— neurčeno —');
		$form->addSelect('secondVerifier', 'Ověřovatel', $members)->setPrompt('— neurčeno —');
		$form->addSelect('recorder', 'Zapisovatel', $persons)
			->setPrompt('— neurčeno —')
			->setOption('description', 'Zapisovatelem může být i pracovník úřadu – stačí ho přidat mezi osoby.');
		$form->addSubmit('save', 'Uložit');
		$form->onSuccess[] = function (Form $form) use ($meeting): void {
			$data = $form->getValues(OfficialsFormData::class);
			try {
				$this->meetingFacade->assignOfficials($meeting->getId(), new AssignOfficials(
					$data->chairperson,
					$data->recorder,
					$data->firstVerifier,
					$data->secondVerifier,
				));
			} catch (ValidationException $e) {
				FormErrors::apply($form, $e);

				return;
			} catch (DomainException $e) {
				$form->addError($e->getMessage());

				return;
			}

			$this->flashMessage('Funkce byly uloženy.', 'success');
			$this->redirect('detail', [
				'id' => $meeting->getId(),
			]);
		};

		return $form;
	}

	private function meetingFormSucceeded(Form $form): void
	{
		$data = $form->getValues(MeetingFormData::class);
		$command = new SaveMeeting(MeetingType::from($data->type), $data->scheduledAt, $data->place, $data->number);

		try {
			if ($this->meeting === null) {
				$body = $this->loadBody($data->body ?? $this->error());
				$meeting = $this->meetingFacade->create($body->getId(), $command);
				$this->flashMessage("Zasedání {$meeting->getTitle()} bylo založeno.", 'success');
			} else {
				$this->meetingFacade->update($this->meeting->getId(), $command);
				$meeting = $this->meeting;
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
			'id' => $meeting->getId(),
		]);
	}

	/**
	 * Změna programu: chybu ukáže jako flash, při AJAXu překreslí jen program.
	 *
	 * @param callable(): mixed $change
	 */
	private function runProgramChange(callable $change): void
	{
		$meeting = $this->meeting ?? $this->error();
		try {
			$change();
		} catch (DomainException $e) {
			$this->flashMessage($e->getMessage(), 'danger');
		}

		if ($this->isAjax()) {
			$this->meeting = $this->meetingFacade->get($meeting->getId());
			$this->redrawControl('program');
			$this->redrawControl('flashes');

			return;
		}

		$this->redirect('detail', [
			'id' => $meeting->getId(),
		]);
	}

	private function runTransition(Meeting $meeting, MeetingTransition $transition): never
	{
		try {
			$warnings = $this->meetingFacade->transition($meeting->getId(), $transition);
			$this->flashMessage(match ($transition) {
				MeetingTransition::Share => 'Zasedání bylo sdíleno.',
				MeetingTransition::Start => 'Zasedání bylo zahájeno.',
				MeetingTransition::Close => 'Zasedání bylo ukončeno.',
				MeetingTransition::ApproveMinutes => 'Zápis je označen jako ověřený. Zasedání je uzavřené.',
				MeetingTransition::Reopen => 'Zasedání bylo znovu otevřeno.',
			}, 'success');
			foreach ($warnings as $warning) {
				$this->flashMessage($warning, 'warning');
			}
		} catch (DomainException $e) {
			$this->flashMessage($e->getMessage(), 'danger');
		}

		$this->redirect('detail', [
			'id' => $meeting->getId(),
		]);
	}

	/**
	 * Orgány obce seskupené podle volebního období (nejnovější období první).
	 *
	 * @return array<string, list<Body>>
	 */
	private function findBodies(): array
	{
		$grouped = [];
		foreach ($this->peopleFacade->findTerms($this->requireMunicipality()->getId()) as $term) {
			$bodies = $this->peopleFacade->findBodies($term->getId());
			if ($bodies !== []) {
				$grouped[$term->getName()] = $bodies;
			}
		}

		return $grouped;
	}

	private function loadMeeting(int $id): Meeting
	{
		try {
			return $this->assertOwned($this->meetingFacade->get($id));
		} catch (NotFoundException $e) {
			$this->error($e->getMessage());
		}
	}

	private function loadBody(int $id): Body
	{
		try {
			return $this->assertOwned($this->peopleFacade->getBody($id));
		} catch (NotFoundException $e) {
			$this->error($e->getMessage());
		}
	}
}
