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
use App\Model\Meeting\MeetingTransition;
use App\Model\Meeting\ProceedingsFacade;
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
		$this->template->transitions = $canEdit ? array_values(array_filter(
			$meeting->getAvailableTransitions(),
			static fn(MeetingTransition $t): bool => in_array($t, [MeetingTransition::Start, MeetingTransition::Close], true),
		)) : [];
	}

	#[Allowed(Resource::Meeting, Privilege::Edit)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleMark(int $personId): void
	{
		$posted = $this->getRequest()->getPost('status');
		$status = is_string($posted) ? AttendanceStatus::tryFrom($posted) : null;
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

	private function requireMeeting(): Meeting
	{
		return $this->meeting ?? $this->error();
	}
}
