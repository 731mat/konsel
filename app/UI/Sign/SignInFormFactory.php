<?php

declare(strict_types=1);

namespace App\UI\Sign;

use App\UI\Form\FormFactory;
use Nette\Application\UI\Form;
use Nette\Security\AuthenticationException;
use Nette\Security\User;

/**
 * Přihlašovací formulář (stránka přihlášení i rozcestník na úvodní stránce).
 */
final readonly class SignInFormFactory
{
	private const string RememberExpiration = '14 days';

	private const string DefaultExpiration = '60 minutes';

	public function __construct(
		private FormFactory $formFactory,
		private User $user,
	) {}

	/**
	 * @param callable(): void $onSuccess po úspěšném přihlášení (typicky přesměrování)
	 */
	public function create(callable $onSuccess, bool $autofocus = true): Form
	{
		$form = $this->formFactory->create();
		$email = $form->addEmail('email', 'E-mail')
			->setRequired('Zadejte e-mail.')
			->setHtmlAttribute('autocomplete', 'username');
		if ($autofocus) {
			$email->setHtmlAttribute('autofocus');
		}

		$form->addPassword('password', 'Heslo')
			->setRequired('Zadejte heslo.')
			->setHtmlAttribute('autocomplete', 'current-password');
		$form->addCheckbox('remember', 'Zůstat přihlášen 14 dní');
		$form->addSubmit('send', 'Přihlásit se');

		$form->onSuccess[] = function (Form $form) use ($onSuccess): void {
			$data = $form->getValues(SignInFormData::class);

			try {
				$this->user->setExpiration($data->remember ? self::RememberExpiration : self::DefaultExpiration);
				$this->user->login($data->email, $data->password);
			} catch (AuthenticationException $e) {
				$form->addError($e->getMessage());

				return;
			}

			$onSuccess();
		};

		return $form;
	}
}
