<?php

declare(strict_types=1);

namespace App\AdminModule\Member;

use App\AdminModule\SecuredPresenter;
use App\Core\Security\Allowed;
use App\Core\Security\Privilege;
use App\Core\Security\Resource;
use App\Model\People\Body;
use App\Model\People\BodyType;
use App\Model\People\Command\AddMember;
use App\Model\People\Command\SaveBody;
use App\Model\People\MemberFunction;
use App\Model\People\Membership;
use App\Model\People\NumberingMask;
use App\Model\People\NumberingScope;
use App\Model\People\PeopleFacade;
use App\Model\People\Term;
use App\Model\Shared\Exception\DomainException;
use App\Model\Shared\Exception\NotFoundException;
use App\Model\Shared\Exception\ValidationException;
use App\UI\Form\FormErrors;
use App\UI\Form\FormFactory;
use Nette\Application\Attributes\Requires;
use Nette\Application\UI\Form;
use Nette\Utils\Html;
use Psr\Clock\ClockInterface;

/**
 * Volební období, orgány (zastupitelstvo, rada, výbory) a jejich členové.
 */
#[Allowed(Resource::Member)]
final class MemberPresenter extends SecuredPresenter
{
	private ?Term $term = null;

	private ?Body $body = null;

	private ?Membership $membership = null;

	public function __construct(
		private readonly PeopleFacade $peopleFacade,
		private readonly FormFactory $formFactory,
		private readonly ClockInterface $clock,
	) {
		parent::__construct();
	}

	public function renderDefault(?int $term = null): void
	{
		$municipalityId = $this->requireMunicipality()->getId();
		$terms = $this->peopleFacade->findTerms($municipalityId);
		$selected = $term === null
			? ($this->peopleFacade->findCurrentTerm($municipalityId) ?? ($terms[0] ?? null))
			: $this->loadTerm($term);

		$this->template->terms = $terms;
		$this->template->selectedTerm = $selected;
		$this->template->bodies = $selected === null ? [] : $this->peopleFacade->findBodies($selected->getId());
		$this->template->warnings = $selected === null ? [] : $this->peopleFacade->getStructureWarnings($selected->getId());
		$this->template->today = $this->clock->now();
		$this->template->canEdit = $this->isAllowed(Resource::Member, Privilege::Edit);
	}

	#[Allowed(Resource::Member, Privilege::Edit)]
	public function actionTermAdd(): void
	{
		$this->requireMunicipality();
	}

	public function actionBody(int $id): void
	{
		$this->body = $this->loadBody($id);
	}

	public function renderBody(): void
	{
		$this->template->body = $this->body;
		$this->template->today = $this->clock->now();
		$this->template->canEdit = $this->isAllowed(Resource::Member, Privilege::Edit);
	}

	#[Allowed(Resource::Member, Privilege::Edit)]
	public function actionBodyAdd(int $term): void
	{
		$this->term = $this->loadTerm($term);
	}

	public function renderBodyAdd(): void
	{
		$this->template->term = $this->term;
	}

	#[Allowed(Resource::Member, Privilege::Edit)]
	public function actionBodyEdit(int $id): void
	{
		$this->body = $this->loadBody($id);
		$this->term = $this->body->getTerm();
		$this->getComponent('bodyForm')->setDefaults([
			'type' => $this->body->getType()->value,
			'name' => $this->body->getName(),
			'seatCount' => $this->body->getSeatCount(),
			'numberingMask' => $this->body->getNumberingMask(),
			'numberingScope' => $this->body->getNumberingScope()->value,
		]);
	}

	public function renderBodyEdit(): void
	{
		$this->template->body = $this->body;
	}

	#[Allowed(Resource::Member, Privilege::Edit)]
	public function actionEndMembership(int $id, int $membershipId): void
	{
		$this->body = $this->loadBody($id);
		foreach ($this->body->getMemberships() as $membership) {
			if ($membership->getId() === $membershipId) {
				$this->membership = $membership;
			}
		}

		if ($this->membership === null) {
			$this->error('Členství neexistuje.');
		}
	}

	public function renderEndMembership(): void
	{
		$this->template->body = $this->body;
		$this->template->membership = $this->membership;
	}

	#[Allowed(Resource::Member, Privilege::Edit)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleRemoveMembership(int $membershipId): void
	{
		$body = $this->body ?? $this->error();
		try {
			$this->peopleFacade->removeMembership($body->getId(), $membershipId);
			$this->flashMessage('Členství bylo odstraněno.', 'success');
		} catch (DomainException $e) {
			$this->flashMessage($e->getMessage(), 'danger');
		}

		$this->redirect('body', [
			'id' => $body->getId(),
		]);
	}

	protected function createComponentTermForm(): Form
	{
		$form = $this->formFactory->create();
		$form->addText('name', 'Název')
			->setRequired('Zadejte název volebního období.')
			->setHtmlAttribute('placeholder', '2026–2030')
			->setMaxLength(50);
		$form->addDate('startsOn', 'Začátek')
			->setRequired('Zadejte začátek volebního období.')
			->setOption('description', 'Den voleb nebo den ustavujícího zasedání.');
		$form->addDate('endsOn', 'Konec');
		$form->addSubmit('save', 'Založit volební období');
		$form->onSuccess[] = function (Form $form): void {
			$data = $form->getValues(TermFormData::class);
			try {
				$term = $this->peopleFacade->createTerm($this->requireMunicipality()->getId(), $data->name, $data->startsOn, $data->endsOn);
			} catch (ValidationException $e) {
				FormErrors::apply($form, $e);

				return;
			}

			$this->flashMessage("Volební období {$term->getName()} bylo založeno.", 'success');
			$this->redirect('default', [
				'term' => $term->getId(),
			]);
		};

		return $form;
	}

	protected function createComponentBodyForm(): Form
	{
		$form = $this->formFactory->create();
		$types = [];
		foreach (BodyType::cases() as $type) {
			$types[$type->value] = $type->label();
		}

		$typeControl = $form->addSelect('type', 'Typ orgánu', $types)->setRequired();
		if ($this->body !== null) {
			$typeControl->setDisabled()->setOmitted(false)->setValue($this->body->getType()->value);
		}

		$form->addText('name', 'Název')
			->setRequired('Zadejte název orgánu.')
			->setMaxLength(200)
			->setHtmlAttribute('placeholder', 'Zastupitelstvo obce …');
		$form->addInteger('seatCount', 'Počet členů')
			->setRequired('Zadejte počet členů.')
			->addRule(Form::Range, 'Počet členů musí být 1 až 99.', [1, 99]);
		$form->addText('numberingMask', 'Číslování usnesení')
			->setDefaultValue(Body::DefaultNumberingMask)
			->setRequired('Zadejte masku čísla usnesení.')
			->setMaxLength(NumberingMask::MaxLength)
			->setHtmlAttribute('data-numbering-mask')
			->setOption('description', Html::el()->setHtml('Proměnné: <code>{n}</code> pořadové číslo, <code>{n:3}</code> doplněné nulami, <code>{yyyy}</code>/<code>{yy}</code> rok, <code>{meeting}</code> číslo zasedání, <code>{item}</code> číslo bodu, <code>{body}</code> zkratka orgánu. Náhled: <strong data-numbering-preview></strong>'));
		$form->addSelect('numberingScope', 'Řada čísel', array_combine(
			array_map(static fn(NumberingScope $scope): string => $scope->value, NumberingScope::cases()),
			array_map(static fn(NumberingScope $scope): string => $scope->label(), NumberingScope::cases()),
		))->setDefaultValue(NumberingScope::Year->value);
		$form->addSubmit('save', 'Uložit');
		$form->onSuccess[] = $this->bodyFormSucceeded(...);

		return $form;
	}

	protected function createComponentMemberForm(): Form
	{
		$this->requirePermission(Resource::Member, Privilege::Edit);
		$body = $this->body ?? $this->error();

		$form = $this->formFactory->create();
		$persons = [];
		foreach ($this->peopleFacade->findPersons($body->getMunicipality()->getId()) as $person) {
			$persons[$person->getId()] = $person->getName()->sortable();
		}

		$functions = [];
		foreach ($body->getType()->allowedFunctions() as $function) {
			$functions[$function->value] = $function->label();
		}

		$form->addSelect('person', 'Osoba', $persons)
			->setPrompt('— vyberte —')
			->setRequired('Vyberte osobu.');
		$form->addSelect('function', 'Funkce', $functions)
			->setDefaultValue(MemberFunction::Member->value)
			->setRequired();
		$form->addDate('validFrom', 'Od')
			->setRequired('Zadejte začátek členství.')
			->setDefaultValue($this->clock->now());
		$form->addDate('validTo', 'Do');
		$form->addSubmit('save', 'Přidat člena');
		$form->onSuccess[] = function (Form $form) use ($body): void {
			$data = $form->getValues(MemberFormData::class);
			try {
				$this->peopleFacade->addMember($body->getId(), new AddMember(
					$data->person,
					MemberFunction::from($data->function),
					$data->validFrom,
					$data->validTo,
				));
			} catch (ValidationException $e) {
				FormErrors::apply($form, $e);

				return;
			}

			$this->flashMessage('Člen byl přidán.', 'success');
			$this->redirect('this');
		};

		return $form;
	}

	protected function createComponentEndMembershipForm(): Form
	{
		$form = $this->formFactory->create();
		$form->addDate('validTo', 'Poslední den členství')
			->setRequired('Zadejte datum.')
			->setDefaultValue($this->clock->now());
		$form->addSubmit('save', 'Ukončit členství');
		$form->onSuccess[] = function (Form $form): void {
			$body = $this->body ?? $this->error();
			$membership = $this->membership ?? $this->error();
			$data = $form->getValues(EndMembershipFormData::class);
			try {
				$this->peopleFacade->endMembership($body->getId(), $membership->getId(), $data->validTo);
			} catch (ValidationException $e) {
				FormErrors::apply($form, $e);

				return;
			}

			$this->flashMessage('Členství bylo ukončeno.', 'success');
			$this->redirect('body', [
				'id' => $body->getId(),
			]);
		};

		return $form;
	}

	private function bodyFormSucceeded(Form $form): void
	{
		$data = $form->getValues(BodyFormData::class);
		$command = new SaveBody(BodyType::from($data->type), $data->name, $data->seatCount, $data->numberingMask, NumberingScope::from($data->numberingScope));

		try {
			if ($this->body === null) {
				$term = $this->term ?? $this->error();
				$body = $this->peopleFacade->createBody($term->getId(), $command);
				$this->flashMessage("Orgán {$body->getName()} byl založen.", 'success');
			} else {
				$this->peopleFacade->updateBody($this->body->getId(), $command);
				$body = $this->body;
				$this->flashMessage('Změny byly uloženy.', 'success');
			}
		} catch (ValidationException $e) {
			FormErrors::apply($form, $e);

			return;
		}

		$this->redirect('body', [
			'id' => $body->getId(),
		]);
	}

	private function loadTerm(int $id): Term
	{
		try {
			return $this->assertOwned($this->peopleFacade->getTerm($id));
		} catch (NotFoundException $e) {
			$this->error($e->getMessage());
		}
	}

	private function loadBody(int $id): Body
	{
		try {
			return $this->assertOwned($this->peopleFacade->getBody($id));
		} catch (NotFoundException $e) {
			$this->error($e->getMessage());
		}
	}
}
