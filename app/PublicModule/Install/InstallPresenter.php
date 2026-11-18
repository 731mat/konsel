<?php

declare(strict_types=1);

namespace App\PublicModule\Install;

use App\Model\Ares\AresFacade;
use App\Model\Installation\Command\Install;
use App\Model\Installation\InstallationFacade;
use App\Model\Municipality\Module;
use App\Model\Shared\Exception\DomainException;
use App\Model\Shared\Exception\ValidationException;
use App\Model\User\PasswordPolicy;
use App\UI\BasePresenter;
use App\UI\Form\FormErrors;
use App\UI\Form\FormFactory;
use Nette\Application\Attributes\Requires;
use Nette\Application\UI\Form;
use Nette\Http\SessionSection;

/**
 * Webový průvodce první instalací (/instalace). Dostupný jen v prázdné instanci (bez uživatelů)
 * a jen s instalačním kódem z logu kontejneru. Údaje kroků drží session, heslo správce až poslední krok.
 */
final class InstallPresenter extends BasePresenter
{
	/**
	 * Kroky v pořadí: akce → název.
	 */
	public const array Steps = [
		'code' => 'Instalační kód',
		'check' => 'Kontrola',
		'municipality' => 'Obec',
		'bodies' => 'Orgány',
		'options' => 'Nastavení',
		'admin' => 'Správce',
	];

	public function __construct(
		private readonly InstallationFacade $installation,
		private readonly AresFacade $ares,
		private readonly FormFactory $formFactory,
	) {
		parent::__construct();
	}

	protected function startup(): void
	{
		parent::startup();
		if (!$this->installation->isRequired()) {
			$this->redirect(':Public:Sign:in');
		}

		$action = $this->getAction();
		if ($action !== 'code' && $action !== 'default' && $this->section()->get('verified') !== true) {
			$this->redirect('code');
		}

		// do dalšího kroku jen s vyplněnými předchozími
		foreach ([
			'municipality' => 'bodies',
			'bodies' => 'options',
			'options' => 'admin',
		] as $required => $step) {
			if ($action === $step && !is_array($this->section()->get($required))) {
				$this->redirect($required);
			}
		}
	}

	public function actionDefault(): void
	{
		$this->redirect($this->section()->get('verified') === true ? 'check' : 'code');
	}

	public function renderCheck(): void
	{
		$checks = $this->installation->checks();
		$this->template->checks = $checks;
		$this->template->canContinue = InstallationFacade::canContinue($checks);
		$this->template->baseUrl = rtrim($this->getHttpRequest()->getUrl()->getBaseUrl(), '/');
	}

	public function renderAdmin(): void
	{
		$this->template->summary = [
			'municipality' => $this->stored('municipality'),
			'bodies' => $this->stored('bodies'),
			'options' => $this->stored('options'),
		];
	}

	protected function beforeRender(): void
	{
		parent::beforeRender();
		$this->template->steps = self::Steps;
		$this->template->currentStep = $this->getAction();
	}

	/**
	 * Načtení údajů obce z ARES podle IČO (jen v ověřené instalaci).
	 */
	#[Requires(methods: 'POST')]
	public function handleAresLookup(): void
	{
		if ($this->section()->get('verified') !== true) {
			$this->error('Nejdřív zadejte instalační kód.', 403);
		}

		$ico = $this->getHttpRequest()->getPost('ico');
		try {
			$subject = $this->ares->lookup(is_string($ico) ? $ico : '');
		} catch (ValidationException $e) {
			$this->sendJson([
				'error' => $e->getMessage(),
			]);
		}

		$this->sendJson([
			'subject' => $subject->toArray(),
		]);
	}

	protected function createComponentCodeForm(): Form
	{
		$form = $this->formFactory->create();
		$code = $form->addText('code', 'Instalační kód')
			->setRequired('Zadejte instalační kód.')
			->setHtmlAttribute('autocomplete', 'off')
			->setHtmlAttribute('placeholder', 'XXXXX-XXXXX')
			->setOption('description', 'Najdete ho v logu kontejneru (Portainer → kontejner → Logs) nebo vypíše příkaz bin/console install:code.');
		$form->addSubmit('send', 'Pokračovat');
		$form->onSuccess[] = function (Form $form) use ($code): void {
			$values = self::values($form);
			if (!$this->installation->verifyCode(is_string($values['code'] ?? null) ? $values['code'] : '')) {
				$code->addError('Kód nesouhlasí. Zkopírujte ho z logu kontejneru celý.');

				return;
			}

			$this->getSession()->regenerateId();
			$this->section()->set('verified', true);
			$this->redirect('check');
		};

		return $form;
	}

	protected function createComponentMunicipalityForm(): Form
	{
		$form = $this->formFactory->create();
		$form->addText('ico', 'IČO obce')
			->setNullable()
			->setMaxLength(12)
			->setHtmlAttribute('inputmode', 'numeric')
			->setHtmlAttribute('data-ares-lookup', $this->link('aresLookup!'))
			->setHtmlAttribute('data-ares-fill', '{"officialName":"name","street":"street","city":"city","postalCode":"postalCode"}')
			->setOption('description', 'Tlačítko Načíst z ARES vyplní úřední název a adresu.');
		$form->addText('name', 'Název obce')
			->setRequired('Zadejte název obce.')
			->setMaxLength(100)
			->setOption('description', 'Krátký název, jak se v aplikaci zobrazuje, např. Neplachovice.');
		$form->addText('officialName', 'Úřední název')
			->setRequired('Zadejte úřední název, např. Obec Neplachovice.')
			->setMaxLength(150);
		$form->addText('street', 'Ulice a číslo')->setNullable()->setMaxLength(150);
		$form->addText('city', 'Obec / město')->setNullable()->setMaxLength(100);
		$form->addText('postalCode', 'PSČ')->setNullable()->setMaxLength(10);
		$form->addEmail('email', 'E-mail úřadu')->setNullable()->setMaxLength(180);
		$form->setDefaults($this->stored('municipality'));
		$form->addSubmit('send', 'Pokračovat');
		$form->onSuccess[] = function (Form $form): void {
			$values = self::values($form);
			$this->section()->set('municipality', $values);
			$this->redirect('bodies');
		};

		return $form;
	}

	protected function createComponentBodiesForm(): Form
	{
		$name = $this->stored('municipality')['name'] ?? '';
		$name = is_string($name) ? $name : '';
		$termStart = self::termStartYear(new \DateTimeImmutable());
		$form = $this->formFactory->create();
		$form->addText('termName', 'Volební období')
			->setRequired('Zadejte volební období.')
			->setDefaultValue($termStart . '–' . ($termStart + 4));
		$form->addDate('termStartsOn', 'Začátek období')
			->setRequired('Zadejte začátek volebního období.')
			->setDefaultValue(new \DateTimeImmutable("{$termStart}-10-01"))
			->setOption('description', 'Den ustavujícího zasedání nebo voleb.');
		$form->addText('councilName', 'Zastupitelstvo')
			->setRequired('Zadejte název zastupitelstva.')
			->setDefaultValue("Zastupitelstvo obce {$name}");
		$form->addInteger('councilSeats', 'Počet zastupitelů')
			->setRequired('Zadejte počet zastupitelů.')
			->addRule(Form::Range, 'Počet zastupitelů musí být %d až %d.', [5, 55])
			->setDefaultValue(9);
		$board = $form->addCheckbox('hasBoard', 'Obec má radu');
		$form->addText('boardName', 'Rada')
			->setDefaultValue("Rada obce {$name}")
			->addConditionOn($board, Form::Equal, true)
			->setRequired('Zadejte název rady.');
		$form->addInteger('boardSeats', 'Počet radních')
			->setDefaultValue(5)
			->addConditionOn($board, Form::Equal, true)
			->setRequired('Zadejte počet radních.')
			->addRule(Form::Range, 'Počet radních musí být %d až %d.', [5, 11]);
		$form->setDefaults($this->stored('bodies'));
		$form->addSubmit('send', 'Pokračovat');
		$form->onSuccess[] = function (Form $form): void {
			$values = self::values($form);
			$values['termStartsOn'] = $values['termStartsOn'] instanceof \DateTimeInterface ? $values['termStartsOn']->format('Y-m-d') : null;
			$this->section()->set('bodies', $values);
			$this->redirect('options');
		};

		return $form;
	}

	protected function createComponentOptionsForm(): Form
	{
		$form = $this->formFactory->create();
		$modules = [];
		foreach (Module::cases() as $module) {
			$modules[$module->value] = $module->label();
		}

		$form->addCheckboxList('modules', 'Zapnuté moduly', $modules)
			->setDefaultValue(array_keys($modules))
			->setOption('description', 'Lze kdykoli změnit v Nastavení → Moduly.');
		$form->addCheckbox('installTemplates', 'Nahrát vzorové šablony programu a usnesení')->setDefaultValue(true);
		$form->addCheckbox('dailyBackup', 'Zálohovat každou noc ve 2:00 (databáze i soubory, posledních 14 záloh)')->setDefaultValue(true);
		$form->setDefaults($this->stored('options'));
		$form->addSubmit('send', 'Pokračovat');
		$form->onSuccess[] = function (Form $form): void {
			$values = self::values($form);
			$this->section()->set('options', $values);
			$this->redirect('admin');
		};

		return $form;
	}

	protected function createComponentAdminForm(): Form
	{
		$form = $this->formFactory->create();
		$form->addText('name', 'Jméno a příjmení')
			->setRequired('Zadejte jméno správce.')
			->setMaxLength(150);
		$form->addEmail('email', 'E-mail (přihlašovací jméno)')
			->setRequired('Zadejte e-mail.')
			->setMaxLength(180)
			->setHtmlAttribute('autocomplete', 'username');
		$password = $form->addPassword('password', 'Heslo')
			->setRequired('Zadejte heslo.')
			->addRule(Form::MinLength, 'Heslo musí mít alespoň %d znaků.', PasswordPolicy::MinLength)
			->setHtmlAttribute('autocomplete', 'new-password');
		$form->addPassword('passwordConfirm', 'Heslo znovu')
			->setRequired('Zopakujte heslo.')
			->addRule(Form::Equal, 'Hesla se neshodují.', $password)
			->setOmitted()
			->setHtmlAttribute('autocomplete', 'new-password');
		$form->addSubmit('send', 'Dokončit instalaci');
		$form->onSuccess[] = function (Form $form): void {
			$values = self::values($form);
			$email = is_string($values['email'] ?? null) ? $values['email'] : '';
			$password = is_string($values['password'] ?? null) ? $values['password'] : '';
			try {
				$this->installation->install($this->command(is_string($values['name'] ?? null) ? $values['name'] : '', $email, $password));
			} catch (ValidationException $e) {
				if (in_array($e->field, ['name', 'email', 'password'], true)) {
					FormErrors::apply($form, $e);
				} else {
					$form->addError("{$e->getMessage()} Opravte údaje v předchozích krocích.");
				}

				return;
			} catch (DomainException $e) {
				$form->addError($e->getMessage());

				return;
			}

			$this->section()->remove();
			$this->getUser()->login($email, $password);
			$this->flashMessage('Instalace je hotová. Vítejte v Konšelu – pokračujte doplněním členů zastupitelstva (Osoby a orgány) a prvním zasedáním.', 'success');
			$this->redirect(':Admin:Dashboard:');
		};

		return $form;
	}

	/**
	 * Začátek volebního období: obecní volby jsou na podzim každého čtvrtého roku (2022, 2026, 2030…).
	 */
	public static function termStartYear(\DateTimeImmutable $today): int
	{
		$year = (int) $today->format('Y');
		$offset = ($year - 2) % 4;
		$start = $year - $offset;

		return $offset === 0 && (int) $today->format('n') < 10 ? $start - 4 : $start;
	}

	private function command(string $adminName, string $adminEmail, string $adminPassword): Install
	{
		$municipality = $this->stored('municipality');
		$bodies = $this->stored('bodies');
		$options = $this->stored('options');
		$text = static fn(array $values, string $key): ?string => is_string($values[$key] ?? null) && $values[$key] !== '' ? $values[$key] : null;
		$int = static fn(array $values, string $key): ?int => is_int($values[$key] ?? null) ? $values[$key] : null;
		$hasBoard = ($bodies['hasBoard'] ?? false) === true;
		$moduleValues = is_array($options['modules'] ?? null) ? $options['modules'] : [];

		return new Install(
			municipalityName: $text($municipality, 'name') ?? '',
			officialName: $text($municipality, 'officialName') ?? '',
			ico: $text($municipality, 'ico'),
			street: $text($municipality, 'street'),
			city: $text($municipality, 'city'),
			postalCode: $text($municipality, 'postalCode'),
			email: $text($municipality, 'email'),
			termName: $text($bodies, 'termName') ?? '',
			termStartsOn: new \DateTimeImmutable($text($bodies, 'termStartsOn') ?? 'today'),
			councilName: $text($bodies, 'councilName') ?? '',
			councilSeats: $int($bodies, 'councilSeats') ?? 0,
			boardName: $hasBoard ? $text($bodies, 'boardName') : null,
			boardSeats: $hasBoard ? $int($bodies, 'boardSeats') : null,
			adminName: $adminName,
			adminEmail: $adminEmail,
			adminPassword: $adminPassword,
			modules: array_values(array_filter(array_map(
				static fn(mixed $value): ?Module => is_string($value) ? Module::tryFrom($value) : null,
				$moduleValues,
			), static fn(?Module $module): bool => $module !== null)),
			installTemplates: ($options['installTemplates'] ?? false) === true,
			dailyBackup: ($options['dailyBackup'] ?? false) === true,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function stored(string $step): array
	{
		$values = $this->section()->get($step);

		return is_array($values) ? self::stringKeys($values) : [];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function values(Form $form): array
	{
		$values = $form->getValues('array');

		return is_array($values) ? self::stringKeys($values) : [];
	}

	/**
	 * @param array<mixed> $values
	 * @return array<string, mixed>
	 */
	private static function stringKeys(array $values): array
	{
		$result = [];
		foreach ($values as $key => $value) {
			$result[(string) $key] = $value;
		}

		return $result;
	}

	private function section(): SessionSection
	{
		return $this->getSession('konsel.install');
	}
}
