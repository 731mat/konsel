<?php

declare(strict_types=1);

namespace App\AdminModule;

use App\Core\Security\Allowed;
use App\Core\Security\Privilege;
use App\Core\Security\RequiresModule;
use App\Core\Security\Resource;
use App\Core\Tenancy\CurrentMunicipality;
use App\Model\Municipality\Municipality;
use App\Model\Municipality\MunicipalityFacade;
use App\Model\People\Body;
use App\Model\People\PeopleFacade;
use App\Model\Shared\Exception\ConcurrencyException;
use App\Model\Shared\TenantOwned;
use App\Model\Shared\ValueObject\StoredFile;
use App\Model\Storage\FileStorage;
use App\UI\BasePresenter;
use App\UI\Menu\AdminMenu;
use App\UI\Menu\AdminMenuFactory;
use Nette\Application\Responses\FileResponse;
use Nette\DI\Attributes\Inject;
use Nette\Security\User;

/**
 * Předek presenterů pro přihlášené uživatele úřadu.
 * Ověří přihlášení, nastaví kontext obce (TenantFilter) a vynutí #[Allowed] oprávnění.
 */
abstract class SecuredPresenter extends BasePresenter
{
	private const string BodySessionSection = 'konsel.body';

	#[Inject]
	public CurrentMunicipality $currentMunicipality;

	#[Inject]
	public MunicipalityFacade $municipalityFacade;

	#[Inject]
	public AdminMenuFactory $adminMenuFactory;

	#[Inject]
	public FileStorage $fileStorage;

	/**
	 * Orgány pro přepínač v horní liště.
	 */
	#[Inject]
	public PeopleFacade $bodyDirectory;

	public function checkRequirements(mixed $element): void
	{
		parent::checkRequirements($element);

		$user = $this->getUser();
		if (!$user->isLoggedIn()) {
			if ($user->getLogoutReason() === User::LogoutInactivity) {
				$this->flashMessage('Byli jste odhlášeni z důvodu nečinnosti. Přihlaste se prosím znovu.', 'warning');
			}

			$this->redirect(':Public:Sign:in', [
				'backlink' => $this->storeRequest(),
			]);
		}

		$identityData = $user->getIdentity()?->getData() ?? [];
		if (($identityData['mustChangePassword'] ?? false) === true && !$this->isLinkCurrent(':Admin:Account:*')) {
			$this->redirect(':Admin:Account:');
		}

		$municipalityId = $identityData['municipalityId'] ?? null;
		if (is_int($municipalityId) && !$this->currentMunicipality->isSet()) {
			$this->currentMunicipality->set($this->municipalityFacade->get($municipalityId));
		}

		foreach ($element->getAttributes(Allowed::class) as $attribute) {
			$allowed = $attribute->newInstance();
			if (!$user->isAllowed($allowed->resource->value, $allowed->privilege->value)) {
				$this->error('K této stránce nemáte oprávnění.', 403);
			}
		}

		foreach ($element->getAttributes(RequiresModule::class) as $attribute) {
			if ($this->currentMunicipality->isSet()) {
				$this->requireModule($this->currentMunicipality->get(), $attribute->newInstance()->module);
			}
		}
	}

	/**
	 * Souběžná úprava (optimistický zámek): EntityManager je zavřený, nic se nevykreslí – jen přesměrování s hláškou.
	 */
	public function processSignal(): void
	{
		try {
			parent::processSignal();
		} catch (ConcurrencyException $e) { // @phpstan-ignore catch.neverThrown (vzniká při flush v TransactionManager hluboko pod signálem)
			$this->flashMessage($e->getMessage(), 'warning');
			$this->redirect('this');
		}
	}

	protected function beforeRender(): void
	{
		parent::beforeRender();
		$this->template->currentMunicipality = $this->currentMunicipality->isSet() ? $this->currentMunicipality->get() : null;
		$bodies = $this->switchableBodies();
		$this->template->switchBodies = $bodies;
		$this->template->selectedBody = $bodies[$this->selectedBodyId() ?? 0] ?? null;
	}

	/**
	 * Přepínač orgánu v horní liště (zastupitelstvo / rada / výbory). Volba platí pro seznamy v celé administraci.
	 */
	#[Allowed(Resource::Dashboard)]
	public function handleSelectBody(?int $bodyId = null): void
	{
		$section = $this->getSession(self::BodySessionSection);
		if ($bodyId === null || !array_key_exists($bodyId, $this->switchableBodies())) {
			$section->remove('bodyId');
		} else {
			$section->set('bodyId', $bodyId);
		}

		$this->redirect('this');
	}

	/**
	 * Orgán zvolený v přepínači (null = všechny).
	 */
	protected function selectedBodyId(): ?int
	{
		$bodyId = $this->getSession(self::BodySessionSection)->get('bodyId');

		return is_int($bodyId) && array_key_exists($bodyId, $this->switchableBodies()) ? $bodyId : null;
	}

	/**
	 * Orgány aktuálního volebního období obce, mezi kterými se přepíná.
	 *
	 * @return array<int, Body>
	 */
	private function switchableBodies(): array
	{
		if (!$this->currentMunicipality->isSet() || !$this->getUser()->isLoggedIn()) {
			return [];
		}

		$term = $this->bodyDirectory->findCurrentTerm($this->currentMunicipality->get()->getId());
		$bodies = [];
		foreach ($term === null ? [] : $this->bodyDirectory->findBodies($term->getId()) as $body) {
			$bodies[$body->getId()] = $body;
		}

		return $bodies;
	}

	protected function createComponentAdminMenu(): AdminMenu
	{
		return $this->adminMenuFactory->create();
	}

	/**
	 * Kontrola oprávnění tam, kde nestačí #[Allowed] (např. formulář na stránce jen pro čtení).
	 */
	protected function requirePermission(Resource $resource, Privilege $privilege): void
	{
		if (!$this->getUser()->isAllowed($resource->value, $privilege->value)) {
			$this->error('K této operaci nemáte oprávnění.', 403);
		}
	}

	/**
	 * ID přihlášeného uživatele úřadu.
	 */
	protected function currentUserId(): int
	{
		$id = $this->getUser()->getId();

		return is_int($id) ? $id : $this->error('Přihlaste se.', 403);
	}

	protected function isAllowed(Resource $resource, Privilege $privilege = Privilege::View): bool
	{
		return $this->getUser()->isAllowed($resource->value, $privilege->value);
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
	 * Druhá vrstva izolace obcí vedle TenantFilteru (ten se neuplatní na entity už načtené
	 * v identity mapě). Volat na každou entitu načtenou podle ID z URL.
	 *
	 * @template T of TenantOwned
	 * @param T $entity
	 * @return T
	 */
	protected function assertOwned(TenantOwned $entity): TenantOwned
	{
		if ($entity->getMunicipality()->getId() !== $this->requireMunicipality()->getId()) {
			$this->error('Záznam neexistuje.');
		}

		return $entity;
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
