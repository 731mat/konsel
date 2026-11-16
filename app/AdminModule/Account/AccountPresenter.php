<?php

declare(strict_types=1);

namespace App\AdminModule\Account;

use App\AdminModule\SecuredPresenter;
use App\Core\Security\Allowed;
use App\Core\Security\Resource;
use App\Model\Shared\Exception\ValidationException;
use App\Model\User\PasswordPolicy;
use App\Model\User\UserFacade;
use App\UI\Form\FormErrors;
use App\UI\Form\FormFactory;
use Nette\Application\UI\Form;

/**
 * Můj účet – změna vlastního hesla (dostupné každému přihlášenému uživateli).
 */
#[Allowed(Resource::Dashboard)]
final class AccountPresenter extends SecuredPresenter
{
	public function __construct(
		private readonly UserFacade $userFacade,
		private readonly FormFactory $formFactory,
	) {
		parent::__construct();
	}

	public function renderDefault(): void
	{
		$this->template->mustChangePassword = (bool) ($this->getUser()->getIdentity()?->getData()['mustChangePassword'] ?? false);
	}

	protected function createComponentPasswordForm(): Form
	{
		$form = $this->formFactory->create();
		$form->addPassword('currentPassword', 'Současné heslo')
			->setRequired('Zadejte současné heslo.')
			->setHtmlAttribute('autocomplete', 'current-password');
		$password = $form->addPassword('password', 'Nové heslo')
			->setRequired('Zadejte nové heslo.')
			->addRule(Form::MinLength, 'Heslo musí mít alespoň %d znaků.', PasswordPolicy::MinLength)
			->setHtmlAttribute('autocomplete', 'new-password');
		$form->addPassword('passwordConfirm', 'Nové heslo znovu')
			->setRequired('Zopakujte nové heslo.')
			->addRule(Form::Equal, 'Hesla se neshodují.', $password)
			->setOmitted(false)
			->setHtmlAttribute('autocomplete', 'new-password');
		$form->addSubmit('save', 'Změnit heslo');
		$form->onSuccess[] = function (Form $form): void {
			$data = $form->getValues(PasswordFormData::class);
			$userId = $this->getUser()->getId();
			try {
				$this->userFacade->changeOwnPassword(is_int($userId) ? $userId : 0, $data->currentPassword, $data->password);
			} catch (ValidationException $e) {
				FormErrors::apply($form, $e);

				return;
			}

			$this->flashMessage('Heslo bylo změněno.', 'success');
			$this->redirect(':Admin:Dashboard:');
		};

		return $form;
	}
}
