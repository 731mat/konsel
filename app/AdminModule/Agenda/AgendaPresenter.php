<?php

declare(strict_types=1);

namespace App\AdminModule\Agenda;

use App\AdminModule\SecuredPresenter;
use App\Core\Security\Allowed;
use App\Core\Security\Privilege;
use App\Core\Security\Resource;
use App\Model\Meeting\AgendaFacade;
use App\Model\Meeting\AgendaItem;
use App\Model\Meeting\Command\SaveAgendaItem;
use App\Model\Meeting\Meeting;
use App\Model\Meeting\MeetingFacade;
use App\Model\People\PeopleFacade;
use App\Model\Shared\Exception\DomainException;
use App\Model\Shared\Exception\NotFoundException;
use App\Model\Shared\Exception\ValidationException;
use App\UI\Form\FormErrors;
use App\UI\Form\FormFactory;
use Nette\Application\Attributes\Requires;
use Nette\Application\UI\Form;

/**
 * Bod programu: založení, úprava, detail (text, návrh usnesení, přílohy, komentáře).
 */
#[Allowed(Resource::Agenda)]
final class AgendaPresenter extends SecuredPresenter
{
	private ?Meeting $meeting = null;

	private ?AgendaItem $item = null;

	public function __construct(
		private readonly AgendaFacade $agendaFacade,
		private readonly MeetingFacade $meetingFacade,
		private readonly PeopleFacade $peopleFacade,
		private readonly FormFactory $formFactory,
	) {
		parent::__construct();
	}

	#[Allowed(Resource::Agenda, Privilege::Edit)]
	public function actionAdd(int $meeting): void
	{
		$this->meeting = $this->loadMeeting($meeting);
		$this->assertProgramEditable($this->meeting);
	}

	public function renderAdd(): void
	{
		$this->template->meeting = $this->meeting;
	}

	#[Allowed(Resource::Agenda, Privilege::Edit)]
	public function actionEdit(int $id): void
	{
		$item = $this->item = $this->loadItem($id);
		$this->meeting = $item->getMeeting();
		$this->assertProgramEditable($item->getMeeting());
		$this->getComponent('itemForm')->setDefaults([
			'title' => $item->getTitle(),
			'rapporteur' => $item->getRapporteur()?->getId(),
			'description' => $item->getDescription(),
			'proposedResolution' => $item->getProposedResolution(),
		]);
	}

	public function renderEdit(): void
	{
		$this->template->item = $this->item;
		$this->template->meeting = $this->meeting;
	}

	public function actionDetail(int $id): void
	{
		$this->item = $this->loadItem($id);
		$this->meeting = $this->item->getMeeting();
	}

	public function renderDetail(): void
	{
		$meeting = $this->meeting ?? $this->error();
		$this->template->item = $this->item;
		$this->template->meeting = $meeting;
		$this->template->canEdit = $meeting->canEditProgram() && $this->isAllowed(Resource::Agenda, Privilege::Edit);
		$this->template->canDelete = $meeting->canEditProgram() && $this->isAllowed(Resource::Agenda, Privilege::Delete);
		$items = $meeting->getAgendaItems();
		$index = array_search($this->item, $items, true);
		$this->template->previous = is_int($index) ? ($items[$index - 1] ?? null) : null;
		$this->template->next = is_int($index) ? ($items[$index + 1] ?? null) : null;
	}

	#[Allowed(Resource::Agenda, Privilege::Delete)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleDelete(): void
	{
		$item = $this->item ?? $this->error();
		$meetingId = $item->getMeeting()->getId();
		try {
			$this->agendaFacade->removeItem($item->getId());
			$this->flashMessage("Bod „{$item->getTitle()}“ byl odstraněn.", 'success');
		} catch (DomainException $e) {
			$this->flashMessage($e->getMessage(), 'danger');
		}

		$this->redirect('Meeting:detail', [
			'id' => $meetingId,
		]);
	}

	protected function createComponentItemForm(): Form
	{
		$meeting = $this->meeting ?? $this->error();
		$form = $this->formFactory->create();

		$persons = [];
		foreach ($this->peopleFacade->findPersons($meeting->getMunicipality()->getId()) as $person) {
			$persons[$person->getId()] = $person->getName()->sortable();
		}

		$form->addText('title', 'Název bodu')
			->setRequired('Zadejte název bodu.')
			->setMaxLength(300)
			->setHtmlAttribute('placeholder', 'Např. Rozpočtové opatření č. 5/2026');
		$form->addSelect('rapporteur', 'Předkladatel', $persons)
			->setPrompt('— neurčeno —');
		$form->addTextArea('description', 'Důvodová zpráva / popis', null, 8)
			->setNullable()
			->setOption('description', 'Prostý text, odstavce oddělte prázdným řádkem.');
		$form->addTextArea('proposedResolution', 'Návrh usnesení', null, 6)
			->setNullable()
			->setHtmlAttribute('placeholder', "{$meeting->getBody()->getName()} schvaluje …")
			->setOption('description', 'Text, o kterém se bude hlasovat. Bod bez usnesení (např. informace, diskuse) nechte prázdný.');
		$form->addSubmit('save', $this->item === null ? 'Přidat bod' : 'Uložit');
		$form->onSuccess[] = function (Form $form) use ($meeting): void {
			$data = $form->getValues(ItemFormData::class);
			$command = new SaveAgendaItem($data->title, $data->description, $data->proposedResolution, $data->rapporteur);
			try {
				if ($this->item === null) {
					$item = $this->agendaFacade->addItem($meeting->getId(), $command);
					$this->flashMessage("Bod „{$item->getTitle()}“ byl přidán do programu.", 'success');
				} else {
					$this->agendaFacade->updateItem($this->item->getId(), $command);
					$item = $this->item;
					$this->flashMessage('Změny byly uloženy.', 'success');
				}
			} catch (ValidationException $e) {
				FormErrors::apply($form, $e);

				return;
			} catch (DomainException $e) {
				$form->addError($e->getMessage());

				return;
			}

			$this->redirect('detail', [
				'id' => $item->getId(),
			]);
		};

		return $form;
	}

	private function assertProgramEditable(Meeting $meeting): void
	{
		if (!$meeting->canEditProgram()) {
			$this->flashMessage('Program lze měnit jen před zahájením zasedání.', 'warning');
			$this->redirect('Meeting:detail', [
				'id' => $meeting->getId(),
			]);
		}
	}

	private function loadMeeting(int $id): Meeting
	{
		try {
			return $this->assertOwned($this->meetingFacade->get($id));
		} catch (NotFoundException $e) {
			$this->error($e->getMessage());
		}
	}

	private function loadItem(int $id): AgendaItem
	{
		try {
			return $this->assertOwned($this->agendaFacade->getItem($id));
		} catch (NotFoundException $e) {
			$this->error($e->getMessage());
		}
	}
}
