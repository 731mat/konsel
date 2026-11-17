<?php

declare(strict_types=1);

namespace App\AdminModule\Dashboard;

use App\AdminModule\SecuredPresenter;
use App\Core\Security\Allowed;
use App\Core\Security\Privilege;
use App\Core\Security\Resource;
use App\Model\Meeting\Meeting;
use App\Model\Meeting\MeetingFacade;
use App\Model\Resolution\ResolutionTask;
use App\Model\Resolution\ResolutionTaskFacade;
use App\Model\Resolution\TaskFilter;
use Psr\Clock\ClockInterface;

#[Allowed(Resource::Dashboard)]
final class DashboardPresenter extends SecuredPresenter
{
	public function __construct(
		private readonly MeetingFacade $meetingFacade,
		private readonly ResolutionTaskFacade $taskFacade,
		private readonly ClockInterface $clock,
	) {
		parent::__construct();
	}

	/**
	 * Logo obce pro hlavičku menu (vidí každý přihlášený uživatel obce).
	 */
	public function actionLogo(): void
	{
		$logo = $this->requireMunicipality()->getLogo() ?? $this->error('Obec nemá logo.');
		$this->sendStoredFile($logo, forceDownload: false);
	}

	public function renderDefault(): void
	{
		$showMeetings = $this->currentMunicipality->isSet() && $this->isAllowed(Resource::Meeting);
		$municipalityId = $showMeetings ? $this->currentMunicipality->get()->getId() : null;

		$upcoming = $municipalityId === null ? [] : $this->meetingFacade->findUnfinished($municipalityId, 6);
		$next = array_shift($upcoming);

		$this->template->showMeetings = $showMeetings;
		$this->template->next = $next;
		$this->template->nextIn = $next === null ? null : $this->relativeDay($next);
		$this->template->upcoming = $upcoming;
		$this->template->recent = $recent = $municipalityId === null ? [] : $this->meetingFacade->findFinished($municipalityId);
		$today = $this->clock->now()->setTime(0, 0);
		$this->template->minutesDue = array_values(array_filter($recent, static fn(Meeting $meeting): bool => $meeting->getMinutesDeadline() !== null));
		$this->template->daysLeft = static fn(Meeting $meeting): int => (int) $today->diff($meeting->getMinutesDeadline() ?? $today)->format('%r%a');
		$this->template->canCreateMeeting = $this->isAllowed(Resource::Meeting, Privilege::Edit);

		$showTasks = $this->currentMunicipality->isSet() && $this->isAllowed(Resource::Resolution);
		$open = $showTasks ? $this->taskFacade->find($this->currentMunicipality->get()->getId(), TaskFilter::Open) : [];
		$this->template->tasks = array_slice($open, 0, 5);
		$this->template->overdueCount = count(array_filter($open, fn(ResolutionTask $task): bool => $this->taskFacade->isOverdue($task)));
		$this->template->isOverdue = fn(ResolutionTask $task): bool => $this->taskFacade->isOverdue($task);
	}

	private function relativeDay(Meeting $meeting): string
	{
		$days = $meeting->daysUntil($this->clock->now());

		return match (true) {
			$days === 0 => 'dnes',
			$days === 1 => 'zítra',
			$days === -1 => 'včera',
			$days > 1 && $days < 5 => "za {$days} dny",
			$days >= 5 => "za {$days} dní",
			default => 'před ' . abs($days) . ' dny',
		};
	}
}
