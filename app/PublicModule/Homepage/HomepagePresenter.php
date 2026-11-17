<?php

declare(strict_types=1);

namespace App\PublicModule\Homepage;

use App\Core\Tenancy\CurrentMunicipality;
use App\Model\Municipality\MunicipalityFacade;
use App\Model\Project\ProjectFacade;
use App\PortalModule\PortalSession;
use App\UI\BasePresenter;
use App\UI\Sign\SignInFormFactory;
use Nette\Application\UI\Form;

/**
 * Úvodní stránka – rozcestník: občan (úkoly a podněty obce) / zastupitel (přihlášení).
 */
final class HomepagePresenter extends BasePresenter
{
	/**
	 * Kolik zveřejněných úkolů ukázat občanovi přímo na rozcestníku.
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
		$municipalities = $this->municipalityFacade->findAll();
		$single = count($municipalities) === 1 ? $municipalities[0] : null;
		$projects = [];
		if ($single !== null) {
			// jediná obec v systému: její barvy a rovnou její úkoly
			$this->currentMunicipality->set($single);
			$projects = $this->projectFacade->findPublic($single->getId());
		}

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
