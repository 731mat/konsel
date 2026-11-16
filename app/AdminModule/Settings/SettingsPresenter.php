<?php

declare(strict_types=1);

namespace App\AdminModule\Settings;

use App\AdminModule\SecuredPresenter;
use App\Core\Security\Allowed;
use App\Core\Security\Privilege;
use App\Core\Security\Resource;
use App\Model\Municipality\Command\UpdateMunicipalitySettings;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Storage\UploadedFile;
use App\UI\Form\FormErrors;
use App\UI\Form\FormFactory;
use Nette\Application\UI\Form;

#[Allowed(Resource::Settings, Privilege::Edit)]
final class SettingsPresenter extends SecuredPresenter
{
	public function __construct(
		private readonly FormFactory $formFactory,
	) {
		parent::__construct();
	}

	public function actionDefault(): void
	{
		$municipality = $this->requireMunicipality();
		$profile = $municipality->getProfile();

		$form = $this->getComponent('settingsForm');
		$form->setDefaults([
			'name' => $municipality->getName(),
			'officialName' => $profile->officialName,
			'ico' => $municipality->getIco()?->value,
			'street' => $profile->street,
			'city' => $profile->city,
			'postalCode' => $profile->postalCode,
			'email' => $profile->email?->value,
			'phone' => $profile->phone,
			'website' => $profile->website,
			'population' => $profile->population,
		]);
	}

	public function renderDefault(): void
	{
		$this->template->logo = $this->requireMunicipality()->getLogo();
	}

	public function actionLogo(): void
	{
		$logo = $this->requireMunicipality()->getLogo() ?? $this->error('Obec nemá logo.');
		$this->sendStoredFile($logo, forceDownload: false);
	}

	protected function createComponentSettingsForm(): Form
	{
		$form = $this->formFactory->create();

		$form->addGroup('Základní údaje');
		$form->addText('name', 'Název obce')
			->setRequired('Zadejte název obce.')
			->setMaxLength(200)
			->setOption('description', 'Krátký název, např. Neplachovice.');
		$form->addText('officialName', 'Oficiální název')
			->setRequired('Zadejte oficiální název.')
			->setMaxLength(200)
			->setOption('description', 'Do hlaviček dokumentů, např. Obec Neplachovice.');
		$form->addText('ico', 'IČO')
			->setNullable()
			->setMaxLength(8)
			->setHtmlAttribute('inputmode', 'numeric');
		$form->addInteger('population', 'Počet obyvatel')
			->setNullable()
			->addRule(Form::Min, 'Počet obyvatel nemůže být záporný.', 0);

		$form->addGroup('Adresa');
		$form->addText('street', 'Ulice a číslo popisné')->setNullable()->setMaxLength(200);
		$form->addText('city', 'Obec / město')->setNullable()->setMaxLength(100);
		$form->addText('postalCode', 'PSČ')->setNullable()->setMaxLength(6);

		$form->addGroup('Kontakty');
		$form->addEmail('email', 'E-mail')->setNullable();
		$form->addText('phone', 'Telefon')->setNullable()->setMaxLength(50);
		$form->addText('website', 'Web')->setNullable()->setMaxLength(255);

		$form->addGroup('Logo / znak obce');
		$form->addUpload('logo', 'Nahrát nové logo')
			->setNullable()
			->addRule(Form::Image, 'Logo musí být obrázek PNG nebo JPEG.')
			->addRule(Form::MaxFileSize, 'Logo může mít nejvýše 2 MB.', 2 * 1024 * 1024)
			->setOption('description', 'PNG nebo JPEG, max. 2 MB. Použije se v hlavičkách dokumentů.');
		$form->addCheckbox('removeLogo', 'Odstranit současné logo');

		$form->setCurrentGroup(null);
		$form->addSubmit('save', 'Uložit nastavení');

		$form->onSuccess[] = $this->settingsFormSucceeded(...);

		return $form;
	}

	private function settingsFormSucceeded(Form $form): void
	{
		$data = $form->getValues(SettingsFormData::class);
		$municipalityId = $this->requireMunicipality()->getId();

		try {
			$this->municipalityFacade->updateSettings($municipalityId, new UpdateMunicipalitySettings(
				name: $data->name,
				officialName: $data->officialName,
				ico: $data->ico,
				street: $data->street,
				city: $data->city,
				postalCode: $data->postalCode,
				email: $data->email,
				phone: $data->phone,
				website: $data->website,
				population: $data->population,
			));

			if ($data->logo !== null && $data->logo->isOk()) {
				$this->municipalityFacade->changeLogo(
					$municipalityId,
					UploadedFile::fromPath($data->logo->getTemporaryFile(), $data->logo->getUntrustedName()),
				);
			} elseif ($data->removeLogo) {
				$this->municipalityFacade->removeLogo($municipalityId);
			}
		} catch (ValidationException $e) {
			FormErrors::apply($form, $e);

			return;
		}

		$this->flashMessage('Nastavení obce bylo uloženo.', 'success');
		$this->redirect('this');
	}
}
