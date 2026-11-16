<?php

declare(strict_types=1);

namespace App\PublicModule\Sign;

use App\UI\BasePresenter;
use App\UI\Form\FormFactory;
use Nette\Application\Attributes\Persistent;
use Nette\Application\Attributes\Requires;
use Nette\Application\UI\Form;
use Nette\Security\AuthenticationException;

final class SignPresenter extends BasePresenter
{
	private const string RememberExpiration = '14 days';

	private const string DefaultExpiration = '60 minutes';

	#[Persistent]
	public string $backlink = '';

	public function __construct(
		private readonly FormFactory $formFactory,
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
		$form = $this->formFactory->create();
		$form->addEmail('email', 'E-mail')
			->setRequired('Zadejte e-mail.')
			->setHtmlAttribute('autocomplete', 'username')
			->setHtmlAttribute('autofocus');
		$form->addPassword('password', 'Heslo')
			->setRequired('Zadejte heslo.')
			->setHtmlAttribute('autocomplete', 'current-password');
		$form->addCheckbox('remember', 'Zůstat přihlášen 14 dní');
		$form->addSubmit('send', 'Přihlásit se');

		$form->onSuccess[] = $this->signInFormSucceeded(...);

		return $form;
	}

	private function signInFormSucceeded(Form $form): void
	{
		$data = $form->getValues(SignInFormData::class);

		try {
			$this->getUser()->setExpiration($data->remember ? self::RememberExpiration : self::DefaultExpiration);
			$this->getUser()->login($data->email, $data->password);
		} catch (AuthenticationException $e) {
			$form->addError($e->getMessage());

			return;
		}

		$this->restoreRequest($this->backlink);
		$this->redirect(':Admin:Dashboard:');
	}
}
