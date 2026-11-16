<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Dashboard;

use App\Presentation\Accessory\SecuredPresenter;

final class DashboardPresenter extends SecuredPresenter
{
	public function renderDefault(): void
	{
		$this->template->municipality = $this->currentMunicipality->isSet() ? $this->currentMunicipality->get() : null;
	}
}
