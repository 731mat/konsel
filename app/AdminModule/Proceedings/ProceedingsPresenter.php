<?php

declare(strict_types=1);

namespace App\AdminModule\Proceedings;

use App\AdminModule\SecuredPresenter;
use App\Core\Security\Allowed;
use App\Core\Security\Privilege;
use App\Core\Security\Resource;
use App\Model\Meeting\Attendance;
use App\Model\Meeting\AttendanceStatus;
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
use App\Model\Shared\Exception\DomainException;
use App\Model\Shared\Exception\NotFoundException;
use Nette\Application\Attributes\Requires;

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
		$this->template->voters = fn(VotingRound $round): array => $this->votingFacade->voters($round);
		$this->template->canVote = $canEdit && $meeting->getStatus() === MeetingStatus::InProgress;
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
		$this->runVotingChange(fn() => $this->votingFacade->open(
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
		$this->runVotingChange(fn() => $this->votingFacade->cast($round->getId(), $personId, $choice));
	}

	#[Allowed(Resource::Meeting, Privilege::Edit)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleAllFor(int $roundId): void
	{
		$round = $this->ownedRound($roundId);
		$this->runVotingChange(fn() => $this->votingFacade->allFor($round->getId()));
	}

	#[Allowed(Resource::Meeting, Privilege::Edit)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleCloseVoting(int $roundId): void
	{
		$round = $this->ownedRound($roundId);
		$this->runVotingChange(function () use ($round): void {
			$result = $this->votingFacade->close($round->getId());
			$this->flashMessage("Hlasování č. {$round->getSequence()} k bodu {$round->getAgendaItem()->getPosition()}: {$result->label($round->getType())}.", $result->tone());
		});
	}

	#[Allowed(Resource::Meeting, Privilege::Edit)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleReopenVoting(int $roundId): void
	{
		$round = $this->ownedRound($roundId);
		$this->runVotingChange(fn() => $this->votingFacade->reopen($round->getId()));
	}

	#[Allowed(Resource::Meeting, Privilege::Edit)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleDeleteVoting(int $roundId): void
	{
		$round = $this->ownedRound($roundId);
		$this->runVotingChange(fn() => $this->votingFacade->delete($round->getId()));
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
	 * @param callable(): mixed $change
	 */
	private function runVotingChange(callable $change): void
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

	private function requireMeeting(): Meeting
	{
		return $this->meeting ?? $this->error();
	}
}
