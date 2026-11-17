<?php

declare(strict_types=1);

namespace App\AdminModule\Proceedings;

use App\AdminModule\SecuredPresenter;
use App\Core\Security\Allowed;
use App\Core\Security\Privilege;
use App\Core\Security\Resource;
use App\Model\Meeting\AgendaFacade;
use App\Model\Meeting\Attendance;
use App\Model\Meeting\AttendanceStatus;
use App\Model\Meeting\Command\SaveAgendaItem;
use App\Model\Meeting\Meeting;
use App\Model\Meeting\MeetingFacade;
use App\Model\Meeting\MeetingStatus;
use App\Model\Meeting\MeetingTransition;
use App\Model\Meeting\ProceedingsFacade;
use App\Model\Meeting\VoteChoice;
use App\Model\Meeting\VotingFacade;
use App\Model\Meeting\VotingRound;
use App\Model\Meeting\VotingType;
use App\Model\People\PeopleFacade;
use App\Model\Resolution\ResolutionFacade;
use App\Model\Shared\Exception\DomainException;
use App\Model\Shared\Exception\NotFoundException;
use App\Model\Shared\Exception\ValidationException;
use App\UI\Form\FormErrors;
use App\UI\Form\FormFactory;
use Nette\Application\Attributes\Requires;
use Nette\Application\UI\Form;

/**
 * Obrazovka průběhu zasedání: docházka a usnášeníschopnost nahoře, pod ní body programu.
 */
#[Allowed(Resource::Meeting)]
final class ProceedingsPresenter extends SecuredPresenter
{
	private ?Meeting $meeting = null;

	public function __construct(
		private readonly MeetingFacade $meetingFacade,
		private readonly ProceedingsFacade $proceedingsFacade,
		private readonly VotingFacade $votingFacade,
		private readonly PeopleFacade $peopleFacade,
		private readonly AgendaFacade $agendaFacade,
		private readonly FormFactory $formFactory,
		private readonly ResolutionFacade $resolutionFacade,
	) {
		parent::__construct();
	}

	public function actionDefault(int $id): void
	{
		try {
			$this->meeting = $this->assertOwned($this->meetingFacade->get($id));
		} catch (NotFoundException $e) {
			$this->error($e->getMessage());
		}

		$meeting = $this->meeting;
		$this->getComponent('minutesForm')->setDefaults([
			'startedAt' => $meeting->getStartedAt()?->format('H:i'),
			'endedAt' => $meeting->getEndedAt()?->format('H:i'),
			'guestCount' => $meeting->getGuestCount(),
			'minutesNote' => $meeting->getMinutesNote(),
		]);
	}

	public function renderDefault(): void
	{
		$meeting = $this->requireMeeting();
		$canEdit = $this->isAllowed(Resource::Meeting, Privilege::Edit);

		$this->template->meeting = $meeting;
		$this->template->attendance = $this->proceedingsFacade->attendance($meeting->getId());
		$this->template->canRecord = $canEdit && Attendance::canRecord($meeting);
		$this->template->choices = AttendanceStatus::choices();
		$this->template->items = $meeting->getAgendaItems();
		$this->template->rounds = $this->votingFacade->findByMeeting($meeting->getId());
		$this->template->resolutions = $this->resolutionFacade->mapByVotingRound($meeting->getId());
		$this->template->voters = fn(VotingRound $round): array => $this->votingFacade->voters($round);
		$this->template->canVote = $canEdit && $meeting->getStatus() === MeetingStatus::InProgress;
		$this->template->canRecordProceedings = $canEdit && $meeting->canRecordProceedings();
		$this->template->canAssignOfficials = $canEdit && $meeting->canAssignOfficials();
		$this->template->canCorrect = $canEdit && in_array($meeting->getStatus(), [MeetingStatus::InProgress, MeetingStatus::Closed], true);
		$this->template->voteChoices = VoteChoice::choices();
		$this->template->otherVotingTypes = [VotingType::CounterProposal, VotingType::Amendment, VotingType::Election];
		$this->template->candidates = $canEdit ? $this->peopleFacade->findPersons($meeting->getMunicipality()->getId()) : [];
		$this->template->transitions = $canEdit ? array_values(array_filter(
			$meeting->getAvailableTransitions(),
			static fn(MeetingTransition $t): bool => in_array($t, [MeetingTransition::Start, MeetingTransition::Close], true),
		)) : [];
	}

	#[Allowed(Resource::Meeting, Privilege::Edit)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleMark(int $personId): void
	{
		$status = AttendanceStatus::tryFrom($this->postString('status') ?? '');
		if ($status === null || $status === AttendanceStatus::Unknown) {
			$this->error('Neznámý stav docházky.');
		}

		$this->runAttendanceChange(fn() => $this->proceedingsFacade->mark($this->requireMeeting()->getId(), $personId, $status));
	}

	#[Allowed(Resource::Meeting, Privilege::Edit)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleAllPresent(): void
	{
		$this->runAttendanceChange(fn() => $this->proceedingsFacade->markAllPresent($this->requireMeeting()->getId()));
	}

	#[Allowed(Resource::Meeting, Privilege::Edit)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleLeave(int $personId): void
	{
		$this->runAttendanceChange(fn() => $this->proceedingsFacade->leave($this->requireMeeting()->getId(), $personId));
	}

	#[Allowed(Resource::Meeting, Privilege::Edit)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleReturn(int $personId): void
	{
		$this->runAttendanceChange(fn() => $this->proceedingsFacade->return($this->requireMeeting()->getId(), $personId));
	}

	/**
	 * Nové hlasování k bodu: typ, znění (u návrhu výchozí z bodu) a u volby kandidát.
	 */
	#[Allowed(Resource::Meeting, Privilege::Edit)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleOpenVoting(int $itemId): void
	{
		$meeting = $this->requireMeeting();
		if ($meeting->findAgendaItem($itemId) === null) {
			$this->error('Bod neexistuje.');
		}

		$type = VotingType::tryFrom($this->postString('type') ?? '') ?? $this->error('Neznámý typ hlasování.');
		$candidate = $this->postString('candidateId');
		$this->runVotingChange($itemId, fn() => $this->votingFacade->open(
			$itemId,
			$type,
			$this->postString('proposalText'),
			$candidate === null || $candidate === '' ? null : (int) $candidate,
		));
	}

	#[Allowed(Resource::Meeting, Privilege::Edit)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleVote(int $roundId, int $personId): void
	{
		$round = $this->ownedRound($roundId);
		$choice = VoteChoice::tryFrom($this->postString('choice') ?? '') ?? $this->error('Neznámá volba.');
		$this->runVotingChange($round->getAgendaItem()->getId(), fn() => $this->votingFacade->cast($round->getId(), $personId, $choice));
	}

	#[Allowed(Resource::Meeting, Privilege::Edit)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleAllFor(int $roundId): void
	{
		$round = $this->ownedRound($roundId);
		$this->runVotingChange($round->getAgendaItem()->getId(), fn() => $this->votingFacade->allFor($round->getId()));
	}

	#[Allowed(Resource::Meeting, Privilege::Edit)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleCloseVoting(int $roundId): void
	{
		$round = $this->ownedRound($roundId);
		$this->runVotingChange($round->getAgendaItem()->getId(), function () use ($round): void {
			$outcome = $this->votingFacade->close($round->getId());
			$resolution = $outcome->resolution === null ? '' : " Usnesení č. {$outcome->resolution->getNumber()}.";
			$this->flashMessage("Hlasování č. {$round->getSequence()} k bodu {$round->getAgendaItem()->getPosition()}: {$outcome->result->label($round->getType())}.{$resolution}", $outcome->result->tone());
		});
	}

	#[Allowed(Resource::Meeting, Privilege::Edit)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleReopenVoting(int $roundId): void
	{
		$round = $this->ownedRound($roundId);
		$this->runVotingChange($round->getAgendaItem()->getId(), fn() => $this->votingFacade->reopen($round->getId()));
	}

	#[Allowed(Resource::Meeting, Privilege::Edit)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleDeleteVoting(int $roundId): void
	{
		$round = $this->ownedRound($roundId);
		$this->runVotingChange($round->getAgendaItem()->getId(), fn() => $this->votingFacade->delete($round->getId()));
	}

	/**
	 * Diskuse k bodu do zápisu. Ukládá se průběžně (AJAX bez překreslení – rozepsaný text zůstane).
	 */
	#[Allowed(Resource::Meeting, Privilege::Edit)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleDiscussion(int $itemId): void
	{
		if ($this->requireMeeting()->findAgendaItem($itemId) === null) {
			$this->error('Bod neexistuje.');
		}

		try {
			$this->proceedingsFacade->recordDiscussion($itemId, $this->postString('discussion'));
			$this->payload->saved = true;
			$message = null;
		} catch (DomainException $e) {
			$this->payload->error = $message = $e->getMessage();
		}

		if ($this->isAjax()) {
			$this->sendPayload();
		}

		$this->flashMessage($message ?? 'Diskuse byla uložena.', $message === null ? 'success' : 'danger');
		$this->redirect('this#bod-' . $itemId);
	}

	/**
	 * Zahájení a ukončení přímo z obrazovky průběhu.
	 */
	#[Allowed(Resource::Meeting, Privilege::Edit)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleTransition(string $transition): void
	{
		$selected = MeetingTransition::tryFrom($transition);
		if ($selected !== MeetingTransition::Start && $selected !== MeetingTransition::Close) {
			$this->error('Neznámá akce.');
		}

		try {
			$this->meetingFacade->transition($this->requireMeeting()->getId(), $selected);
			$this->flashMessage($selected === MeetingTransition::Start ? 'Zasedání bylo zahájeno.' : 'Zasedání bylo ukončeno.', 'success');
		} catch (DomainException $e) {
			$this->flashMessage($e->getMessage(), 'danger');
		}

		$this->redirect('this');
	}

	/**
	 * @param callable(): void $change
	 */
	private function runAttendanceChange(callable $change): void
	{
		try {
			$change();
		} catch (DomainException $e) {
			$this->flashMessage($e->getMessage(), 'danger');
		}

		if (!$this->isAjax()) {
			$this->redirect('this');
		}

		$this->redrawControl('attendance');
		$this->redrawControl('quorum');
		$this->redrawControl('flashes');
	}

	/**
	 * Překreslí jen hlasování jednoho bodu (rozepsaná diskuse u ostatních bodů zůstane).
	 *
	 * @param callable(): mixed $change
	 */
	private function runVotingChange(int $itemId, callable $change): void
	{
		try {
			$change();
		} catch (DomainException $e) {
			$this->flashMessage($e->getMessage(), 'danger');
		}

		if (!$this->isAjax()) {
			$this->redirect('this');
		}

		$this->redrawControl('agenda');
		$this->redrawControl("voting-{$itemId}");
		$this->redrawControl('flashes');
	}

	private function ownedRound(int $roundId): VotingRound
	{
		try {
			$round = $this->votingFacade->get($roundId);
		} catch (NotFoundException) {
			$this->error('Hlasování neexistuje.');
		}

		if ($round->getMeeting() !== $this->requireMeeting()) {
			$this->error('Hlasování neexistuje.');
		}

		return $round;
	}

	private function postString(string $name): ?string
	{
		$value = $this->getRequest()->getPost($name);

		return is_string($value) ? $value : null;
	}

	/**
	 * Údaje do zápisu: skutečný čas zahájení a ukončení, počet hostů, poznámka.
	 */
	protected function createComponentMinutesForm(): Form
	{
		$form = $this->formFactory->create();
		$form->addText('startedAt', 'Zahájeno')
			->setHtmlType('time')
			->setNullable();
		$form->addText('endedAt', 'Ukončeno')
			->setHtmlType('time')
			->setNullable();
		$form->addInteger('guestCount', 'Počet hostů')
			->setNullable()
			->addRule(Form::Range, 'Počet hostů musí být 0 až 10 000.', [0, 10_000]);
		$form->addTextArea('minutesNote', 'Poznámka do zápisu', null, 3)
			->setNullable()
			->setMaxLength(Meeting::MaxMinutesNoteLength)
			->setHtmlAttribute('placeholder', 'Např. kdo se dostavil později, přerušení zasedání…');
		$form->addSubmit('save', 'Uložit údaje');
		$form->onSuccess[] = function (Form $form): void {
			$this->requirePermission(Resource::Meeting, Privilege::Edit);
			$data = $form->getValues(MinutesFormData::class);
			$meeting = $this->requireMeeting();
			try {
				if ($data->startedAt !== null && $meeting->getStartedAt() !== null) {
					$day = $meeting->getStartedAt();
					$this->meetingFacade->correctTimes(
						$meeting->getId(),
						self::atTime($day, $data->startedAt, 'startedAt'),
						$data->endedAt === null ? null : self::atTime($day, $data->endedAt, 'endedAt'),
					);
				}

				$this->proceedingsFacade->recordMinutesDetails($meeting->getId(), $data->guestCount, $data->minutesNote);
			} catch (ValidationException $e) {
				FormErrors::apply($form, $e);

				return;
			} catch (DomainException $e) {
				$form->addError($e->getMessage());

				return;
			}

			$this->flashMessage('Údaje do zápisu byly uloženy.', 'success');
			$this->redirect('this#zapis');
		};

		return $form;
	}

	/**
	 * Doplnění bodu do programu během zasedání.
	 */
	protected function createComponentAddItemForm(): Form
	{
		$meeting = $this->requireMeeting();
		$positions = [];
		foreach ($meeting->getAgendaItems() as $item) {
			$positions[$item->getPosition()] = "před bod {$item->getPosition()}. {$item->getTitle()}";
		}

		$form = $this->formFactory->create();
		$form->addText('title', 'Název bodu')
			->setRequired('Zadejte název bodu.')
			->setMaxLength(300);
		$form->addTextArea('proposedResolution', 'Návrh usnesení', null, 3)
			->setNullable()
			->setHtmlAttribute('placeholder', "{$meeting->getBody()->getName()} schvaluje …");
		$form->addSelect('position', 'Zařadit', $positions)
			->setPrompt('na konec programu');
		$form->addSubmit('add', 'Doplnit bod');
		$form->onSuccess[] = function (Form $form): void {
			$this->requirePermission(Resource::Agenda, Privilege::Edit);
			$data = $form->getValues(AddItemFormData::class);
			try {
				$item = $this->agendaFacade->addItemDuringMeeting(
					$this->requireMeeting()->getId(),
					new SaveAgendaItem($data->title, proposedResolution: $data->proposedResolution),
					$data->position,
				);
			} catch (ValidationException $e) {
				FormErrors::apply($form, $e);

				return;
			} catch (DomainException $e) {
				$form->addError($e->getMessage());

				return;
			}

			$this->flashMessage("Bod „{$item->getTitle()}“ byl doplněn do programu jako {$item->getPosition()}. bod.", 'success');
			$this->redirect('this#bod-' . $item->getId());
		};

		return $form;
	}

	/**
	 * Čas „HH:MM“ ke dni zasedání.
	 *
	 * @throws ValidationException
	 */
	private static function atTime(\DateTimeImmutable $day, string $time, string $field): \DateTimeImmutable
	{
		if (preg_match('~^(\d{1,2}):(\d{2})$~', $time, $m) !== 1 || (int) $m[1] > 23 || (int) $m[2] > 59) {
			throw new ValidationException('Zadejte čas ve tvaru HH:MM.', $field);
		}

		return $day->setTime((int) $m[1], (int) $m[2]);
	}

	private function requireMeeting(): Meeting
	{
		return $this->meeting ?? $this->error();
	}
}
