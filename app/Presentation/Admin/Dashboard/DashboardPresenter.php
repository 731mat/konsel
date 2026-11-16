<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Dashboard;

use App\Core\Security\Allowed;
use App\Core\Security\Resource;
use App\Presentation\Accessory\SecuredPresenter;

#[Allowed(Resource::Dashboard)]
final class DashboardPresenter extends SecuredPresenter
{
	public function renderDefault(): void {}
}
