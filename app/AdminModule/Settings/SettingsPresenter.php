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
use App\Model\Document\DocumentTemplateFacade;
use App\Model\Document\DocumentTemplateType;
use App\Model\Municipality\BrandColors;
use App\Model\Municipality\Command\UpdateMunicipalitySettings;
use App\Model\Municipality\Module;
use App\Model\People\PeopleFacade;
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
	/**
	 * Hotové kombinace barev: název, hlavní, doplňková.
	 */
	public const array ColorPresets = [
		['Konšel (výchozí)', BrandColors::DefaultPrimary, BrandColors::DefaultAccent],
		['Státní modrá a červená', '#11457e', '#d7141a'],
		['Lesní zelená', '#1e6b45', '#d9a441'],
		['Vínová', '#8c1b3a', '#e3b45d'],
		['Tyrkysová', '#0f6e74', '#f08a5d'],
		['Fialová', '#5b3c88', '#f2b84b'],
		['Heraldická hnědá', '#7a4a1e', '#c9a227'],
		['Břidlicová', '#334155', '#f59e0b'],
	];

	private ?DocumentTemplateType $documentType = null;

	private ?int $documentBodyId = null;

	public function __construct(
		private readonly FormFactory $formFactory,
		private readonly AiFacade $aiFacade,
		private readonly DocumentTemplateFacade $documentTemplateFacade,
		private readonly PeopleFacade $peopleFacade,
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

	public function actionAppearance(): void
	{
		$colors = $this->requireMunicipality()->getBrandColors();
		$this->getComponent('appearanceForm')->setDefaults([
			'primaryColor' => $colors->primary->value,
			'accentColor' => $colors->accent->value,
		]);
	}

	public function renderAppearance(): void
	{
		$this->template->presets = self::ColorPresets;
		$this->template->customColors = $this->requireMunicipality()->hasCustomBrandColors();
		$this->template->minContrast = BrandColors::MinPrimaryContrast;
	}

	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleResetColors(): void
	{
		$this->municipalityFacade->changeBrandColors($this->requireMunicipality()->getId(), null, null);
		$this->flashMessage('Barvy byly vráceny na výchozí.', 'success');
		$this->redirect('appearance');
	}

	protected function createComponentAppearanceForm(): Form
	{
		$form = $this->formFactory->create();
		$form->addColor('primaryColor', 'Hlavní barva')
			->setRequired('Vyberte hlavní barvu.');
		$form->addColor('accentColor', 'Doplňková barva')
			->setRequired('Vyberte doplňkovou barvu.');
		$form->addSubmit('save', 'Uložit barvy');
		$form->onSuccess[] = function (Form $form): void {
			$data = $form->getValues(AppearanceFormData::class);
			try {
				$this->municipalityFacade->changeBrandColors($this->requireMunicipality()->getId(), $data->primaryColor, $data->accentColor);
			} catch (ValidationException $e) {
				FormErrors::apply($form, $e);

				return;
			}

			$this->flashMessage('Barvy obce byly uloženy.', 'success');
			$this->redirect('this');
		};

		return $form;
	}

	public function renderDocuments(): void
	{
		$municipalityId = $this->requireMunicipality()->getId();
		$custom = [];
		foreach ($this->documentTemplateFacade->findCustom($municipalityId) as $template) {
			$custom[$template->getType()->value][] = $template;
		}

		$this->template->types = DocumentTemplateType::cases();
		$this->template->custom = $custom;
	}

	/**
	 * Editor šablony dokumentu. body = vlastní šablona jen pro orgán (jinak pro celou obec).
	 */
	public function actionDocument(string $type, ?int $body = null): void
	{
		$municipalityId = $this->requireMunicipality()->getId();
		$this->documentType = DocumentTemplateType::tryFrom($type) ?? $this->error('Neznámá šablona.');
		$bodies = $this->documentBodies();
		$this->documentBodyId = $body !== null && isset($bodies[$body]) ? $body : null;
		$this->getComponent('documentTemplateForm')->setDefaults([
			'source' => $this->documentTemplateFacade->getSource($municipalityId, $this->documentType, $this->documentBodyId),
		]);
	}

	public function renderDocument(): void
	{
		$type = $this->documentType ?? $this->error();
		$this->template->documentType = $type;
		$this->template->bodies = $this->documentBodies();
		$this->template->selectedBody = $this->documentBodyId;
		$this->template->custom = $this->documentTemplateFacade->findCustomFor($this->requireMunicipality()->getId(), $type, $this->documentBodyId);
	}

	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleResetDocument(): void
	{
		$type = $this->documentType ?? $this->error();
		$this->documentTemplateFacade->reset($this->requireMunicipality()->getId(), $type, $this->documentBodyId);
		$this->flashMessage("Šablona „{$type->label()}“ je zase výchozí.", 'success');
		$this->redirect('this');
	}

	protected function createComponentDocumentTemplateForm(): Form
	{
		$form = $this->formFactory->create();
		$form->addTextArea('source', 'Šablona (HTML + Latte)')
			->setRequired('Šablona je prázdná.')
			->setHtmlAttribute('rows', 32)
			->setHtmlAttribute('spellcheck', 'false')
			->setHtmlAttribute('class', 'form-control font-monospace small code-editor');
		$form->addSubmit('save', 'Uložit šablonu');
		$form->addSubmit('preview', 'Náhled PDF')
			->setValidationScope(null)
			->setHtmlAttribute('formtarget', '_blank');
		$form->onSuccess[] = function (Form $form): void {
			$type = $this->documentType ?? $this->error();
			/** @var array{source: string} $values */
			$values = $form->getValues('array');
			$municipalityId = $this->requireMunicipality()->getId();
			$preview = $form['preview'];
			assert($preview instanceof \Nette\Forms\Controls\SubmitButton);
			try {
				if ($preview->isSubmittedBy()) {
					$document = $this->documentTemplateFacade->preview($municipalityId, $type, $this->documentBodyId, $values['source']);
					$this->getHttpResponse()->setContentType($document->mimeType);
					$this->getHttpResponse()->setHeader('Content-Disposition', 'inline; filename="nahled.pdf"');
					$this->sendResponse(new \Nette\Application\Responses\CallbackResponse(static function () use ($document): void {
						echo $document->content;
					}));
				}

				$this->documentTemplateFacade->save($municipalityId, $type, $this->documentBodyId, $values['source'], $this->currentUserId());
			} catch (ValidationException $e) {
				FormErrors::apply($form, $e);

				return;
			}

			$this->flashMessage("Šablona „{$type->label()}“ byla uložena. Nové dokumenty se už tvoří podle ní.", 'success');
			$this->redirect('this');
		};

		return $form;
	}

	/**
	 * @return array<int, string> orgány obce (aktuální i minulá období)
	 */
	private function documentBodies(): array
	{
		$bodies = [];
		foreach ($this->peopleFacade->findTerms($this->requireMunicipality()->getId()) as $term) {
			foreach ($this->peopleFacade->findBodies($term->getId()) as $body) {
				$bodies[$body->getId()] = "{$body->getName()} ({$term->getName()})";
			}
		}

		return $bodies;
	}

	public function actionModules(): void
	{
		$municipality = $this->requireMunicipality();
		$defaults = [];
		foreach (Module::cases() as $module) {
			$defaults[$module->value] = $municipality->isModuleEnabled($module);
		}

		$this->getComponent('modulesForm')->setDefaults($defaults);
	}

	public function renderModules(): void
	{
		$this->template->modules = Module::cases();
	}

	protected function createComponentModulesForm(): Form
	{
		$form = $this->formFactory->create();
		foreach (Module::cases() as $module) {
			$form->addCheckbox($module->value, $module->label());
		}

		$form->addSubmit('save', 'Uložit moduly');
		$form->onSuccess[] = function (Form $form): void {
			/** @var array<string, bool> $values */
			$values = $form->getValues('array');
			$enabled = array_values(array_filter(Module::cases(), static fn(Module $module): bool => ($values[$module->value] ?? false) === true));
			$this->municipalityFacade->changeModules($this->requireMunicipality()->getId(), $enabled);
			$this->flashMessage('Moduly byly uloženy.', 'success');
			$this->redirect('this');
		};

		return $form;
	}

	public function actionAi(): void
	{
		$settings = $this->aiFacade->getSettings($this->requireMunicipality()->getId());
		$this->getComponent('aiForm')->setDefaults([
			'model' => $settings?->getModel() ?? AiSettings::DefaultModel,
			'enabled' => $settings?->isEnabled() ?? true,
		]);
		$this->getComponent('writingGuideForm')->setDefaults([
			'instructions' => $settings?->getInstructions(),
			'examples' => $settings?->getExamples(),
			'useRecentResolutions' => $settings?->usesRecentResolutions() ?? true,
		]);
	}

	public function renderAi(): void
	{
		$this->template->usage = $this->aiFacade->monthlyUsage($this->requireMunicipality()->getId());
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

	protected function createComponentWritingGuideForm(): Form
	{
		$form = $this->formFactory->create();
		$form->addTextArea('instructions', 'Pokyny pro AI (štábní kultura)', rows: 8)
			->setNullable()
			->setMaxLength(AiSettings::MaxInstructionsLength)
			->setHtmlAttribute('placeholder', "Např.:\nUsnesení začínej slovesem s dvojtečkou (Schvaluje:, Bere na vědomí:).\nNepiš „Zastupitelstvo obce … schvaluje“.\nČástky v Kč s mezerami (1 840 000 Kč).\nŽádné anglicismy, zkratky jen ZO, OÚ, RO.")
			->setOption('description', 'Co AI má a nemá dělat – styl, oslovení, tvar usnesení, zakázaná slova. Platí přednostně.');
		$form->addTextArea('examples', 'Vzory textů', rows: 10)
			->setNullable()
			->setMaxLength(AiSettings::MaxExamplesLength)
			->setHtmlAttribute('placeholder', 'Vložte několik vašich typických usnesení nebo důvodových zpráv, každé na nový řádek.')
			->setOption('description', 'AI napodobí jejich tvar a styl, obsah z nich nepřebírá.');
		$form->addCheckbox('useRecentResolutions', 'Jako vzory použít i posledních 20 přijatých usnesení obce a knihovnu šablon usnesení');
		$form->addSubmit('save', 'Uložit pokyny a vzory');
		$form->onSuccess[] = function (Form $form): void {
			/** @var array{instructions: ?string, examples: ?string, useRecentResolutions: bool} $values */
			$values = $form->getValues('array');
			try {
				$this->aiFacade->saveWritingGuide($this->requireMunicipality()->getId(), $values['instructions'], $values['examples'], $values['useRecentResolutions']);
			} catch (ValidationException $e) {
				FormErrors::apply($form, $e);

				return;
			}

			$this->flashMessage('Pokyny a vzory pro AI byly uloženy.', 'success');
			$this->redirect('this');
		};

		return $form;
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
