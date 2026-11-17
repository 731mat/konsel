<?php

declare(strict_types=1);

namespace App\PublicModule\Homepage;

use App\Core\Tenancy\CurrentMunicipality;
use App\Model\Municipality\Module;
use App\Model\Municipality\Municipality;
use App\Model\Municipality\MunicipalityFacade;
use App\Model\Project\ProjectFacade;
use App\PortalModule\PortalSession;
use App\UI\BasePresenter;
use App\UI\Sign\SignInFormFactory;
use Nette\Application\UI\Form;

/**
 * Úvodní stránka – rozcestník: občan (plány a podněty obce) / zastupitel (přihlášení).
 * Strana pro občany se ukáže jen u obcí se zapnutým modulem Plány.
 */
final class HomepagePresenter extends BasePresenter
{
	/**
	 * Kolik zveřejněných plánů ukázat občanovi přímo na rozcestníku.
	 */
	private const int ProjectPreviewCount = 4;

	public function __construct(
		private readonly MunicipalityFacade $municipalityFacade,
		private readonly ProjectFacade $projectFacade,
		private readonly CurrentMunicipality $currentMunicipality,
		private readonly PortalSession $portalSession,
		private readonly SignInFormFactory $signInFormFactory,
	) {
		parent::__construct();
	}

	public function renderDefault(): void
	{
		$all = $this->municipalityFacade->findAll();
		$municipalities = array_values(array_filter($all, static fn(Municipality $item): bool => $item->isModuleEnabled(Module::Plans)));
		$single = count($all) === 1 ? $all[0] : null;
		if ($single !== null) {
			// jediná obec v systému: její barvy
			$this->currentMunicipality->set($single);
		}

		$single = count($municipalities) === 1 ? $municipalities[0] : null;
		$projects = $single === null ? [] : $this->projectFacade->findPublic($single->getId());

		$this->template->citizenSide = $municipalities !== [] || $all === [];
		$this->template->municipalities = $municipalities;
		$this->template->municipality = $single;
		$this->template->projects = array_slice($projects, 0, self::ProjectPreviewCount);
		$this->template->projectCount = count($projects);
		$this->template->portalActive = $this->portalSession->getTokenId() !== null;
	}

	protected function createComponentSignInForm(): Form
	{
		return $this->signInFormFactory->create(function (): void {
			$this->redirect(':Admin:Dashboard:');
		}, autofocus: false);
	}
}
