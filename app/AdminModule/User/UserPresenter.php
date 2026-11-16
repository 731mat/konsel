<?php

declare(strict_types=1);

namespace App\AdminModule\User;

use App\AdminModule\SecuredPresenter;
use App\Core\Security\Allowed;
use App\Core\Security\Privilege;
use App\Core\Security\Resource;
use App\Model\Shared\Exception\DomainException;
use App\Model\Shared\Exception\NotFoundException;
use App\Model\Shared\Exception\ValidationException;
use App\Model\User\Command\CreateUser;
use App\Model\User\Command\UpdateUser;
use App\Model\User\PasswordPolicy;
use App\Model\User\Role;
use App\Model\User\User;
use App\Model\User\UserFacade;
use App\UI\Form\FormErrors;
use App\UI\Form\FormFactory;
use Nette\Application\Attributes\Requires;
use Nette\Application\UI\Form;

/**
 * Uživatelé úřadu (účty s heslem). Zastupitelé se spravují v Osobách.
 */
#[Allowed(Resource::User, Privilege::Edit)]
final class UserPresenter extends SecuredPresenter
{
	private ?User $editedUser = null;

	public function __construct(
		private readonly UserFacade $userFacade,
		private readonly FormFactory $formFactory,
	) {
		parent::__construct();
	}

	public function renderDefault(): void
	{
		$this->template->users = $this->userFacade->findByMunicipality($this->requireMunicipality()->getId());
		$this->template->currentUserId = $this->getUser()->getId();
	}

	public function actionAdd(): void
	{
		$this->requireMunicipality();
	}

	public function actionEdit(int $id): void
	{
		$this->editedUser = $this->loadUser($id);
		$this->getComponent('userForm')->setDefaults([
			'name' => $this->editedUser->getName(),
			'email' => $this->editedUser->getEmail()->value,
			'role' => $this->editedUser->getRole()->value,
		]);
	}

	public function renderEdit(): void
	{
		$this->template->editedUser = $this->editedUser;
	}

	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleDeactivate(int $id): void
	{
		$user = $this->loadUser($id);
		$this->runAction(fn() => $this->userFacade->deactivate($user->getId(), $this->currentUserId()), "Účet {$user->getName()} byl deaktivován.");
	}

	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleActivate(int $id): void
	{
		$user = $this->loadUser($id);
		$this->runAction(fn() => $this->userFacade->activate($user->getId()), "Účet {$user->getName()} byl znovu aktivován.");
	}

	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleResetPassword(int $id): void
	{
		$user = $this->loadUser($id);
		$password = $this->userFacade->resetPassword($user->getId());
		$this->flashMessage("Dočasné heslo pro {$user->getName()}: {$password} – předejte jej uživateli, při přihlášení si ho musí změnit. Zobrazuje se jen teď.", 'warning');
		$this->redirect('default');
	}

	protected function createComponentUserForm(): Form
	{
		$form = $this->formFactory->create();
		$roles = [];
		foreach ([Role::Admin, Role::Editor, Role::Reader] as $role) {
			$roles[$role->value] = $role->label();
		}

		$form->addText('name', 'Jméno a příjmení')->setRequired('Zadejte jméno.')->setMaxLength(150);
		$form->addEmail('email', 'E-mail (přihlašovací jméno)')->setRequired('Zadejte e-mail.');
		$form->addSelect('role', 'Role', $roles)
			->setDefaultValue(Role::Editor->value)
			->setRequired()
			->setOption('description', 'Správce obce: vše včetně nastavení a uživatelů. Editor: příprava a vedení zasedání. Čtenář: jen náhled.');
		$form->addSubmit('save', $this->editedUser === null ? 'Založit účet' : 'Uložit');
		$form->onSuccess[] = $this->userFormSucceeded(...);

		return $form;
	}

	private function userFormSucceeded(Form $form): void
	{
		$data = $form->getValues(UserFormData::class);
		$role = Role::from($data->role);

		try {
			if ($this->editedUser === null) {
				$password = PasswordPolicy::generate();
				$user = $this->userFacade->create(new CreateUser($data->email, $data->name, $role, $password, $this->requireMunicipality()->getId(), mustChangePassword: true));
				$this->flashMessage("Účet {$user->getName()} byl založen. Dočasné heslo: {$password} – při prvním přihlášení si ho uživatel změní. Zobrazuje se jen teď.", 'warning');
			} else {
				$this->userFacade->update($this->editedUser->getId(), new UpdateUser($data->email, $data->name, $role), $this->currentUserId());
				$this->flashMessage('Změny byly uloženy.', 'success');
			}
		} catch (ValidationException $e) {
			FormErrors::apply($form, $e);

			return;
		} catch (DomainException $e) {
			$form->addError($e->getMessage());

			return;
		}

		$this->redirect('default');
	}

	/**
	 * @param callable(): void $action
	 */
	private function runAction(callable $action, string $successMessage): void
	{
		try {
			$action();
			$this->flashMessage($successMessage, 'success');
		} catch (DomainException $e) {
			$this->flashMessage($e->getMessage(), 'danger');
		}

		$this->redirect('default');
	}

	private function loadUser(int $id): User
	{
		try {
			$user = $this->userFacade->get($id);
		} catch (NotFoundException $e) {
			$this->error($e->getMessage());
		}

		if ($user->getMunicipality()?->getId() !== $this->requireMunicipality()->getId()) {
			$this->error('Uživatel neexistuje.');
		}

		return $user;
	}

	private function currentUserId(): int
	{
		$id = $this->getUser()->getId();

		return is_int($id) ? $id : 0;
	}
}
