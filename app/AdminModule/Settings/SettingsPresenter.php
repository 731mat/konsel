<?php

declare(strict_types=1);

namespace App\AdminModule\Settings;

use App\AdminModule\SecuredPresenter;
use App\Core\Security\Allowed;
use App\Core\Security\Privilege;
use App\Core\Security\Resource;
use App\Model\Ai\AiException;
use App\Model\Ai\AiFacade;
use App\Model\Ai\AiSettings;
use App\Model\Ai\Command\SaveAiSettings;
use App\Model\Municipality\Command\UpdateMunicipalitySettings;
use App\Model\Shared\Exception\DomainException;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Storage\UploadedFile;
use App\UI\Form\FormErrors;
use App\UI\Form\FormFactory;
use Nette\Application\Attributes\Requires;
use Nette\Application\UI\Form;

#[Allowed(Resource::Settings, Privilege::Edit)]
final class SettingsPresenter extends SecuredPresenter
{
	public function __construct(
		private readonly FormFactory $formFactory,
		private readonly AiFacade $aiFacade,
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

	public function actionAi(): void
	{
		$settings = $this->aiFacade->getSettings($this->requireMunicipality()->getId());
		$this->getComponent('aiForm')->setDefaults([
			'model' => $settings?->getModel() ?? AiSettings::DefaultModel,
			'enabled' => $settings?->isEnabled() ?? true,
		]);
	}

	public function renderAi(): void
	{
		$this->template->aiSettings = $this->aiFacade->getSettings($this->requireMunicipality()->getId());
		$this->template->encryptionConfigured = $this->aiFacade->isEncryptionConfigured();
	}

	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleTestAi(): void
	{
		try {
			$this->aiFacade->testConnection($this->requireMunicipality()->getId());
			$this->flashMessage('Připojení k OpenAI funguje, klíč i model jsou v pořádku.', 'success');
		} catch (AiException|DomainException $e) {
			$this->flashMessage($e->getMessage(), 'danger');
		}

		$this->redirect('ai');
	}

	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleRemoveAiKey(): void
	{
		$this->aiFacade->removeApiKey($this->requireMunicipality()->getId());
		$this->flashMessage('API klíč byl odstraněn. AI funkce jsou vypnuté.', 'success');
		$this->redirect('ai');
	}

	protected function createComponentAiForm(): Form
	{
		$settings = $this->aiFacade->getSettings($this->requireMunicipality()->getId());
		$form = $this->formFactory->create();
		$form->addPassword('apiKey', 'API klíč OpenAI')
			->setNullable()
			->setHtmlAttribute('autocomplete', 'off')
			->setHtmlAttribute('placeholder', $settings?->hasApiKey() === true ? "Uložen klíč sk-{$settings->getApiKeyHint()} – vyplňte jen pro změnu" : 'sk-…')
			->setOption('description', 'Klíč vytvoříte na platform.openai.com → API keys. Uloží se zašifrovaný a už nikdy se nezobrazí celý.');
		$form->addText('model', 'Model')
			->setRequired('Zadejte model.')
			->setMaxLength(100)
			->setOption('description', 'Např. ' . AiSettings::DefaultModel . '. Dostupnost modelu ověří tlačítko „Otestovat připojení“.');
		$form->addCheckbox('enabled', 'Povolit AI funkce (návrhy textů bodů a usnesení)');
		$form->addSubmit('save', 'Uložit');
		$form->onSuccess[] = function (Form $form): void {
			$data = $form->getValues(AiFormData::class);
			try {
				$this->aiFacade->saveSettings($this->requireMunicipality()->getId(), new SaveAiSettings($data->model, $data->enabled, $data->apiKey));
			} catch (ValidationException $e) {
				FormErrors::apply($form, $e);

				return;
			}

			$this->flashMessage('Nastavení AI bylo uloženo.', 'success');
			$this->redirect('this');
		};

		return $form;
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
