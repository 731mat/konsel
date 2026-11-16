<?php

declare(strict_types=1);

namespace App\Presentation\Accessory;

use App\Application\Municipality\MunicipalityFacade;
use App\Core\Tenancy\CurrentMunicipality;
use Nette\DI\Attributes\Inject;
use Nette\Security\User;

/**
 * Předek presenterů pro přihlášené uživatele úřadu. Nastaví kontext obce (TenantFilter).
 */
abstract class SecuredPresenter extends BasePresenter
{
	#[Inject]
	public CurrentMunicipality $currentMunicipality;

	#[Inject]
	public MunicipalityFacade $municipalityFacade;

	public function checkRequirements(mixed $element): void
	{
		parent::checkRequirements($element);

		$user = $this->getUser();
		if (!$user->isLoggedIn()) {
			if ($user->getLogoutReason() === User::LogoutInactivity) {
				$this->flashMessage('Byli jste odhlášeni z důvodu nečinnosti. Přihlaste se prosím znovu.', 'warning');
			}

			$this->redirect(':Sign:in', [
				'backlink' => $this->storeRequest(),
			]);
		}

		$municipalityId = $user->getIdentity()?->getData()['municipalityId'] ?? null;
		if (is_int($municipalityId) && !$this->currentMunicipality->isSet()) {
			$this->currentMunicipality->set($this->municipalityFacade->get($municipalityId));
		}
	}
}
