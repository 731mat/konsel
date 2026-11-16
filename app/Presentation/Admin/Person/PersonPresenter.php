<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Person;

use App\Application\People\Command\SavePerson;
use App\Application\People\PeopleFacade;
use App\Core\Security\Allowed;
use App\Core\Security\Privilege;
use App\Core\Security\Resource;
use App\Domain\People\Person;
use App\Domain\Shared\Exception\DomainException;
use App\Domain\Shared\Exception\NotFoundException;
use App\Domain\Shared\Exception\ValidationException;
use App\Presentation\Accessory\Form\FormErrors;
use App\Presentation\Accessory\FormFactory;
use App\Presentation\Accessory\SecuredPresenter;
use Nette\Application\Attributes\Requires;
use Nette\Application\UI\Form;

#[Allowed(Resource::Member)]
final class PersonPresenter extends SecuredPresenter
{
	private ?Person $person = null;

	public function __construct(
		private readonly PeopleFacade $peopleFacade,
		private readonly FormFactory $formFactory,
	) {
		parent::__construct();
	}

	public function renderDefault(): void
	{
		$this->template->persons = $this->peopleFacade->findPersons($this->requireMunicipality()->getId());
		$this->template->canEdit = $this->isAllowed(Resource::Member, Privilege::Edit);
	}

	#[Allowed(Resource::Member, Privilege::Edit)]
	public function actionAdd(): void
	{
		$this->requireMunicipality();
	}

	#[Allowed(Resource::Member, Privilege::Edit)]
	public function actionEdit(int $id): void
	{
		$this->person = $this->loadPerson($id);
		$name = $this->person->getName();
		$this->getComponent('personForm')->setDefaults([
			'titleBefore' => $name->titleBefore,
			'firstName' => $name->firstName,
			'lastName' => $name->lastName,
			'titleAfter' => $name->titleAfter,
			'email' => $this->person->getEmail()?->value,
			'phone' => $this->person->getPhone(),
		]);
	}

	public function renderEdit(): void
	{
		$this->template->person = $this->person;
	}

	#[Allowed(Resource::Member, Privilege::Edit)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleDelete(int $id): void
	{
		$person = $this->loadPerson($id);
		try {
			$this->peopleFacade->deletePerson($person->getId());
			$this->flashMessage("Osoba {$person->getFullName()} byla smazána.", 'success');
		} catch (DomainException $e) {
			$this->flashMessage($e->getMessage(), 'danger');
		}

		$this->redirect('default');
	}

	protected function createComponentPersonForm(): Form
	{
		$form = $this->formFactory->create();
		$form->addText('titleBefore', 'Titul před jménem')->setNullable()->setMaxLength(50);
		$form->addText('firstName', 'Jméno')->setRequired('Zadejte jméno.')->setMaxLength(100);
		$form->addText('lastName', 'Příjmení')->setRequired('Zadejte příjmení.')->setMaxLength(100);
		$form->addText('titleAfter', 'Titul za jménem')->setNullable()->setMaxLength(50);
		$form->addEmail('email', 'E-mail')
			->setNullable()
			->setOption('description', 'Na tento e-mail přijde pozvánka s odkazem na podklady.');
		$form->addText('phone', 'Telefon')->setNullable()->setMaxLength(50);
		$form->addSubmit('save', 'Uložit');
		$form->onSuccess[] = $this->personFormSucceeded(...);

		return $form;
	}

	private function personFormSucceeded(Form $form): void
	{
		$data = $form->getValues(PersonFormData::class);
		$command = new SavePerson($data->firstName, $data->lastName, $data->titleBefore, $data->titleAfter, $data->email, $data->phone);

		try {
			if ($this->person === null) {
				$this->peopleFacade->createPerson($this->requireMunicipality()->getId(), $command);
				$this->flashMessage('Osoba byla přidána.', 'success');
			} else {
				$this->peopleFacade->updatePerson($this->person->getId(), $command);
				$this->flashMessage('Změny byly uloženy.', 'success');
			}
		} catch (ValidationException $e) {
			FormErrors::apply($form, $e);

			return;
		}

		$this->redirect('default');
	}

	private function loadPerson(int $id): Person
	{
		try {
			return $this->assertOwned($this->peopleFacade->getPerson($id));
		} catch (NotFoundException $e) {
			$this->error($e->getMessage());
		}
	}
}
