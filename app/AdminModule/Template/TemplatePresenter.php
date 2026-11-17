<?php

declare(strict_types=1);

namespace App\AdminModule\Template;

use App\AdminModule\SecuredPresenter;
use App\Core\Security\Allowed;
use App\Core\Security\Privilege;
use App\Core\Security\Resource;
use App\Model\Meeting\Meeting;
use App\Model\Meeting\MeetingFacade;
use App\Model\Shared\Exception\DomainException;
use App\Model\Shared\Exception\NotFoundException;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Template\Command\SaveResolutionTemplate;
use App\Model\Template\MeetingTemplate;
use App\Model\Template\ResolutionTemplate;
use App\Model\Template\TemplateFacade;
use App\Model\Template\TemplateVariables;
use App\UI\Form\FormErrors;
use App\UI\Form\FormFactory;
use Nette\Application\Attributes\Requires;
use Nette\Application\UI\Form;

/**
 * Knihovna šablon usnesení a šablon programu zasedání.
 */
#[Allowed(Resource::Template)]
final class TemplatePresenter extends SecuredPresenter
{
	private ?ResolutionTemplate $resolutionTemplate = null;

	private ?MeetingTemplate $meetingTemplate = null;

	public function __construct(
		private readonly TemplateFacade $templateFacade,
		private readonly MeetingFacade $meetingFacade,
		private readonly FormFactory $formFactory,
	) {
		parent::__construct();
	}

	public function renderDefault(): void
	{
		$municipalityId = $this->requireMunicipality()->getId();
		$grouped = [];
		foreach ($this->templateFacade->findResolutionTemplates($municipalityId) as $template) {
			$grouped[$template->getCategory()][] = $template;
		}

		$this->template->resolutionTemplates = $grouped;
		$this->template->meetingTemplates = $this->templateFacade->findMeetingTemplates($municipalityId);
		$this->template->canEdit = $this->isAllowed(Resource::Template, Privilege::Edit);
		$this->template->sourceMeetings = array_values(array_filter(
			array_slice($this->meetingFacade->find($municipalityId), 0, 30),
			static fn(Meeting $meeting): bool => $meeting->getAgendaItems() !== [],
		));
	}

	/**
	 * Šablona programu z programu existujícího zasedání.
	 */
	#[Allowed(Resource::Template, Privilege::Edit)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleSaveProgram(): void
	{
		$meetingId = $this->getRequest()->getPost('meetingId');
		$name = $this->getRequest()->getPost('name');
		try {
			$meeting = $this->assertOwned($this->meetingFacade->get(is_numeric($meetingId) ? (int) $meetingId : 0));
			$template = $this->templateFacade->saveProgramAsTemplate($meeting->getId(), is_string($name) ? $name : '');
			$this->flashMessage("Program byl uložen jako šablona „{$template->getName()}“.", 'success');
		} catch (DomainException $e) {
			$this->flashMessage($e->getMessage(), 'danger');
		}

		$this->redirect('this');
	}

	#[Allowed(Resource::Template, Privilege::Edit)]
	public function actionAdd(): void
	{
		$this->requireMunicipality();
	}

	public function renderAdd(): void
	{
		$this->template->variables = TemplateVariables::describe();
		$this->template->categories = $this->categories();
	}

	#[Allowed(Resource::Template, Privilege::Edit)]
	public function actionEdit(int $id): void
	{
		$this->resolutionTemplate = $template = $this->loadResolutionTemplate($id);
		$this->getComponent('resolutionTemplateForm')->setDefaults([
			'title' => $template->getTitle(),
			'category' => $template->getCategory(),
			'text' => $template->getText(),
		]);
	}

	public function renderEdit(): void
	{
		$this->template->resolutionTemplate = $this->resolutionTemplate;
		$this->template->variables = TemplateVariables::describe();
		$this->template->categories = $this->categories();
	}

	public function actionMeeting(int $id): void
	{
		try {
			$this->meetingTemplate = $this->assertOwned($this->templateFacade->getMeetingTemplate($id));
		} catch (NotFoundException $e) {
			$this->error($e->getMessage());
		}
	}

	public function renderMeeting(): void
	{
		$this->template->meetingTemplate = $this->meetingTemplate;
		$this->template->canEdit = $this->isAllowed(Resource::Template, Privilege::Edit);
	}

	#[Allowed(Resource::Template, Privilege::Edit)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleInstallDefaults(): void
	{
		$municipalityId = $this->requireMunicipality()->getId();
		$added = $this->templateFacade->installDefaultResolutionTemplates($municipalityId)
			+ $this->templateFacade->installDefaultMeetingTemplates($municipalityId);
		$this->flashMessage($added === 0 ? 'Výchozí šablony už v knihovně jsou.' : "Do knihovny bylo přidáno {$added} výchozích šablon.", 'success');
		$this->redirect('default');
	}

	#[Allowed(Resource::Template, Privilege::Edit)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleDeleteResolution(int $templateId): void
	{
		$template = $this->loadResolutionTemplate($templateId);
		$this->templateFacade->deleteResolutionTemplate($template->getId());
		$this->flashMessage("Šablona „{$template->getTitle()}“ byla smazána.", 'success');
		$this->redirect('default');
	}

	#[Allowed(Resource::Template, Privilege::Edit)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleDeleteMeetingTemplate(int $templateId): void
	{
		try {
			$template = $this->assertOwned($this->templateFacade->getMeetingTemplate($templateId));
			$this->templateFacade->deleteMeetingTemplate($template->getId());
			$this->flashMessage("Šablona „{$template->getName()}“ byla smazána.", 'success');
		} catch (DomainException $e) {
			$this->flashMessage($e->getMessage(), 'danger');
		}

		$this->redirect('default');
	}

	protected function createComponentResolutionTemplateForm(): Form
	{
		$form = $this->formFactory->create();
		$form->addText('title', 'Název')
			->setRequired('Zadejte název šablony.')
			->setMaxLength(200);
		$form->addText('category', 'Kategorie')
			->setRequired('Zadejte kategorii.')
			->setMaxLength(100)
			->setHtmlAttribute('list', 'template-categories')
			->setOption('description', 'Např. Rozpočet, Majetek, Smlouvy a dotace.');
		$form->addTextArea('text', 'Text usnesení', null, 6)
			->setRequired('Zadejte text usnesení.')
			->setOption('description', 'Místa k doplnění vyznačte tečkami ………… . Proměnné se při vložení do bodu nahradí.');
		$form->addSubmit('save', $this->resolutionTemplate === null ? 'Přidat šablonu' : 'Uložit');
		$form->onSuccess[] = function (Form $form): void {
			$data = $form->getValues(ResolutionTemplateFormData::class);
			$command = new SaveResolutionTemplate($data->title, $data->category, $data->text);
			try {
				if ($this->resolutionTemplate === null) {
					$this->templateFacade->createResolutionTemplate($this->requireMunicipality()->getId(), $command);
				} else {
					$this->templateFacade->updateResolutionTemplate($this->resolutionTemplate->getId(), $command);
				}
			} catch (ValidationException $e) {
				FormErrors::apply($form, $e);

				return;
			}

			$this->flashMessage('Šablona byla uložena.', 'success');
			$this->redirect('default');
		};
		return $form;
	}

	/**
	 * @return list<string>
	 */
	private function categories(): array
	{
		$categories = [];
		foreach ($this->templateFacade->findResolutionTemplates($this->requireMunicipality()->getId()) as $template) {
			$categories[$template->getCategory()] = $template->getCategory();
		}

		return array_values($categories);
	}

	private function loadResolutionTemplate(int $id): ResolutionTemplate
	{
		try {
			return $this->assertOwned($this->templateFacade->getResolutionTemplate($id));
		} catch (NotFoundException $e) {
			$this->error($e->getMessage());
		}
	}
}
