<?php

declare(strict_types=1);

namespace App\PublicModule\Sign;

use App\UI\BasePresenter;
use App\UI\Sign\SignInFormFactory;
use Nette\Application\Attributes\Persistent;
use Nette\Application\Attributes\Requires;
use Nette\Application\UI\Form;

final class SignPresenter extends BasePresenter
{
	#[Persistent]
	public string $backlink = '';

	public function __construct(
		private readonly SignInFormFactory $signInFormFactory,
	) {
		parent::__construct();
	}

	public function actionIn(): void
	{
		if ($this->getUser()->isLoggedIn()) {
			$this->redirect(':Admin:Dashboard:');
		}
	}

	#[Requires(sameOrigin: true)]
	public function actionOut(): void
	{
		$this->getUser()->logout(clearIdentity: true);
		$this->flashMessage('Byli jste odhlášeni.', 'info');
		$this->redirect('in');
	}

	protected function createComponentSignInForm(): Form
	{
		return $this->signInFormFactory->create(function (): void {
			$this->restoreRequest($this->backlink);
			$this->redirect(':Admin:Dashboard:');
		});
	}
}
