<?php

declare(strict_types=1);

namespace App\AdminModule\Dashboard;

use App\AdminModule\SecuredPresenter;
use App\Core\Security\Allowed;
use App\Core\Security\Privilege;
use App\Core\Security\Resource;
use App\Model\Meeting\MeetingFacade;

#[Allowed(Resource::Dashboard)]
final class DashboardPresenter extends SecuredPresenter
{
	public function __construct(
		private readonly MeetingFacade $meetingFacade,
	) {
		parent::__construct();
	}

	public function renderDefault(): void
	{
		$showMeetings = $this->currentMunicipality->isSet() && $this->isAllowed(Resource::Meeting);
		$municipalityId = $showMeetings ? $this->currentMunicipality->get()->getId() : null;

		$this->template->showMeetings = $showMeetings;
		$this->template->upcoming = $municipalityId === null ? [] : $this->meetingFacade->findUnfinished($municipalityId);
		$this->template->recent = $municipalityId === null ? [] : $this->meetingFacade->findFinished($municipalityId);
		$this->template->canCreateMeeting = $this->isAllowed(Resource::Meeting, Privilege::Edit);
	}
}
