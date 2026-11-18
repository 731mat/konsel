<?php

declare(strict_types=1);

namespace App\PortalModule;

use App\Core\Tenancy\CurrentMunicipality;
use App\Model\Access\AccessFacade;
use App\Model\Access\MeetingAccessToken;
use App\Model\Audit\AuditActor;
use App\Model\Audit\AuditContext;
use App\Model\Meeting\Meeting;
use App\Model\People\Person;
use App\Model\Shared\Exception\AccessDeniedException;
use App\UI\BasePresenter;
use Nette\DI\Attributes\Inject;

/**
 * Předek presenterů portálu zastupitele (přístup osobním odkazem k jednomu zasedání).
 */
abstract class PortalPresenter extends BasePresenter
{
	#[Inject]
	public AccessFacade $accessFacade;

	#[Inject]
	public PortalSession $portalSession;

	#[Inject]
	public CurrentMunicipality $currentMunicipality;

	#[Inject]
	public AuditContext $auditContext;

	private ?MeetingAccessToken $accessToken = null;

	protected function startup(): void
	{
		parent::startup();

		$tokenId = $this->portalSession->getTokenId();
		try {
			$this->accessToken = $this->accessFacade->getValid($tokenId ?? 0);
		} catch (AccessDeniedException) {
			$this->portalSession->logout();
			$this->redirect(':Portal:Entry:invalid');
		}

		$this->currentMunicipality->set($this->accessToken->getMunicipality());
		$this->auditContext->setActor(new AuditActor(
			$this->accessToken->getPerson()->getFullName() . ' (zastupitel, osobní odkaz)',
			null,
			$this->getHttpRequest()->getRemoteAddress(),
		));
	}

	protected function beforeRender(): void
	{
		parent::beforeRender();
		$this->template->member = $this->getMember();
		$this->template->meeting = $this->getMeeting();
		$this->template->municipality = $this->getMeeting()->getMunicipality();
	}

	protected function getMeeting(): Meeting
	{
		return ($this->accessToken ?? $this->error('Přístup odepřen.', 403))->getMeeting();
	}

	protected function getMember(): Person
	{
		return ($this->accessToken ?? $this->error('Přístup odepřen.', 403))->getPerson();
	}
}
