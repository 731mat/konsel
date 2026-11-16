<?php

declare(strict_types=1);

namespace App\PortalModule\Entry;

use App\Model\Access\AccessFacade;
use App\Model\Shared\Exception\AccessDeniedException;
use App\PortalModule\PortalSession;
use App\UI\BasePresenter;

/**
 * Vstup zastupitele osobním odkazem /odkaz/<token>. Token se po ověření z URL odstraní přesměrováním.
 */
final class EntryPresenter extends BasePresenter
{
	public function __construct(
		private readonly AccessFacade $accessFacade,
		private readonly PortalSession $portalSession,
	) {
		parent::__construct();
	}

	public function actionDefault(string $token): void
	{
		$this->getHttpResponse()->setHeader('Referrer-Policy', 'no-referrer');
		try {
			$accessToken = $this->accessFacade->authenticate($token);
		} catch (AccessDeniedException) {
			$this->portalSession->logout();
			$this->setView('invalid');

			return;
		}

		$this->portalSession->login($accessToken->getId());
		$this->redirect(':Portal:Meeting:');
	}

	public function actionOut(): void
	{
		$this->portalSession->logout();
	}
}
