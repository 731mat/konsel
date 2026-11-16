<?php

declare(strict_types=1);

namespace App\PublicModule\Ideas;

use App\Core\Tenancy\CurrentMunicipality;
use App\Model\Attachment\Attachment;
use App\Model\Attachment\AttachmentFacade;
use App\Model\Municipality\Municipality;
use App\Model\Municipality\MunicipalityFacade;
use App\Model\Project\Project;
use App\Model\Project\ProjectFacade;
use App\Model\Project\ProjectStatus;
use App\Model\Shared\Exception\NotFoundException;
use App\Model\Shared\Exception\ValidationException;
use App\UI\BasePresenter;
use App\UI\Form\FormErrors;
use App\UI\Form\FormFactory;
use Nette\Application\Responses\FileResponse;
use Nette\Application\UI\Form;

/**
 * Veřejná stránka obce pro občany: zveřejněné úkoly (fáze, priorita, veřejné komentáře) a formulář podnětu.
 */
final class IdeasPresenter extends BasePresenter
{
	private ?Municipality $municipality = null;

	private ?Project $project = null;

	public function __construct(
		private readonly MunicipalityFacade $municipalityFacade,
		private readonly ProjectFacade $projectFacade,
		private readonly AttachmentFacade $attachmentFacade,
		private readonly CurrentMunicipality $currentMunicipality,
		private readonly FormFactory $formFactory,
	) {
		parent::__construct();
	}

	protected function startup(): void
	{
		parent::startup();
		$slug = $this->getParameter('municipality');
		$municipality = is_string($slug) ? $this->municipalityFacade->findBySlug($slug) : null;
		if ($municipality === null) {
			$this->error('Obec nenalezena.');
		}

		$this->municipality = $municipality;
		$this->currentMunicipality->set($municipality);
	}

	public function renderDefault(): void
	{
		$municipality = $this->requireMunicipality();
		$this->template->municipality = $municipality;
		$this->template->projects = $this->projectFacade->findPublic($municipality->getId());
		$this->template->path = ProjectStatus::path();
	}

	public function actionDetail(int $id): void
	{
		try {
			$project = $this->projectFacade->get($id);
		} catch (NotFoundException) {
			$this->error('Úkol neexistuje.');
		}

		if ($project->getMunicipality() !== $this->requireMunicipality() || !$project->isPublic()) {
			$this->error('Úkol neexistuje.');
		}

		$this->project = $project;
	}

	public function renderDetail(): void
	{
		$project = $this->project ?? $this->error();
		$this->template->municipality = $this->requireMunicipality();
		$this->template->project = $project;
		$this->template->timeline = $this->projectFacade->timeline($project->getId(), publicOnly: true);
		$this->template->path = ProjectStatus::path();
		$this->template->fileLink = fn(Attachment $a): string => $this->link('file', [
			'id' => $a->getId(),
		]);
	}

	/**
	 * Soubor ze zveřejněného komentáře zveřejněného úkolu.
	 */
	public function actionFile(int $id): void
	{
		try {
			$attachment = $this->attachmentFacade->get($id);
		} catch (NotFoundException) {
			$this->error('Soubor neexistuje.');
		}

		$comment = $attachment->getComment();
		$project = $comment?->getProject();
		if ($comment === null || $project === null || !$comment->isPublicForCitizens() || $comment->isDeleted()
			|| !$project->isPublic() || $project->getMunicipality() !== $this->requireMunicipality()) {
			$this->error('Soubor neexistuje.');
		}

		$this->getHttpResponse()->setHeader('X-Content-Type-Options', 'nosniff');
		$this->sendResponse(new FileResponse(
			$this->attachmentFacade->getLocalPath($attachment),
			$attachment->getFile()->originalName,
			$attachment->getFile()->mimeType,
			!$attachment->canPreview(),
		));
	}

	protected function createComponentProposalForm(): Form
	{
		$form = $this->formFactory->create();
		$form->addText('name', 'Vaše jméno')
			->setRequired('Zadejte své jméno.')
			->setMaxLength(100);
		$form->addEmail('email', 'E-mail')
			->setRequired('Zadejte e-mail, abychom vás mohli kontaktovat.')
			->setOption('description', 'Nezveřejňuje se, slouží jen obecnímu úřadu pro případné doplnění.');
		$form->addText('title', 'Co navrhujete')
			->setRequired('Napište, co navrhujete.')
			->setMaxLength(200)
			->setHtmlAttribute('placeholder', 'Např. Lavičky v parku u kostela');
		$form->addTextArea('description', 'Popis', null, 5)
			->setNullable()
			->setMaxLength(5000)
			->setHtmlAttribute('placeholder', 'Kde, proč a pro koho by to bylo užitečné…');
		// past na roboty – skryté pole, člověk ho nevyplní
		$form->addText('website', 'Web')
			->setHtmlAttribute('autocomplete', 'off')
			->setHtmlAttribute('tabindex', '-1');
		$form->addCheckbox('consent', 'Souhlasím se zpracováním jména a e-mailu obcí za účelem vyřízení podnětu.')
			->setRequired('Bez souhlasu nemůžeme podnět přijmout.');
		$form->addSubmit('send', 'Odeslat podnět');
		$form->onSuccess[] = function (Form $form): void {
			$data = $form->getValues(ProposalFormData::class);
			if ($data->website !== '') {
				$this->redirect('this', [
					'sent' => true,
				]);
			}

			try {
				$this->projectFacade->submitProposal(
					$this->requireMunicipality()->getId(),
					$data->name,
					$data->email,
					$data->title,
					$data->description,
					$this->getHttpRequest()->getRemoteAddress(),
				);
			} catch (ValidationException $e) {
				FormErrors::apply($form, $e);

				return;
			}

			$this->flashMessage('Děkujeme! Podnět jsme přijali. Obecní úřad ho posoudí a po zveřejnění ho uvidíte v seznamu.', 'success');
			$this->redirect('this');
		};

		return $form;
	}

	private function requireMunicipality(): Municipality
	{
		return $this->municipality ?? $this->error('Obec nenalezena.');
	}
}
