<?php

declare(strict_types=1);

namespace App\Presentation\Accessory;

use App\Application\Municipality\MunicipalityFacade;
use App\Application\Shared\Storage\FileStorage;
use App\Core\Security\Allowed;
use App\Core\Tenancy\CurrentMunicipality;
use App\Domain\Municipality\Municipality;
use App\Domain\Shared\ValueObject\StoredFile;
use App\Presentation\Accessory\Menu\AdminMenu;
use App\Presentation\Accessory\Menu\AdminMenuFactory;
use Nette\Application\Responses\FileResponse;
use Nette\DI\Attributes\Inject;
use Nette\Security\User;

/**
 * Předek presenterů pro přihlášené uživatele úřadu.
 * Ověří přihlášení, nastaví kontext obce (TenantFilter) a vynutí #[Allowed] oprávnění.
 */
abstract class SecuredPresenter extends BasePresenter
{
	#[Inject]
	public CurrentMunicipality $currentMunicipality;

	#[Inject]
	public MunicipalityFacade $municipalityFacade;

	#[Inject]
	public AdminMenuFactory $adminMenuFactory;

	#[Inject]
	public FileStorage $fileStorage;

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

		foreach ($element->getAttributes(Allowed::class) as $attribute) {
			$allowed = $attribute->newInstance();
			if (!$user->isAllowed($allowed->resource->value, $allowed->privilege->value)) {
				$this->error('K této stránce nemáte oprávnění.', 403);
			}
		}
	}

	protected function beforeRender(): void
	{
		parent::beforeRender();
		$this->template->currentMunicipality = $this->currentMunicipality->isSet() ? $this->currentMunicipality->get() : null;
	}

	protected function createComponentAdminMenu(): AdminMenu
	{
		return $this->adminMenuFactory->create();
	}

	/**
	 * Obec aktuálního uživatele. Superadmin bez zvolené obce dostane 404.
	 */
	protected function requireMunicipality(): Municipality
	{
		if (!$this->currentMunicipality->isSet()) {
			$this->error('Tato stránka vyžaduje kontext obce.');
		}

		return $this->currentMunicipality->get();
	}

	/**
	 * Odešle soubor z úložiště. Oprávnění musí ověřit volající akce (#[Allowed] + kontrola vlastnictví).
	 */
	protected function sendStoredFile(StoredFile $file, bool $forceDownload = true): never
	{
		$this->sendResponse(new FileResponse(
			$this->fileStorage->getLocalPath($file),
			$file->originalName,
			$file->mimeType,
			$forceDownload,
		));
	}
}
