<?php

declare(strict_types=1);

namespace App\AdminModule\Backup;

use App\AdminModule\SecuredPresenter;
use App\Core\Security\Allowed;
use App\Core\Security\Privilege;
use App\Core\Security\Resource;
use App\Model\Backup\BackupContent;
use App\Model\Backup\BackupException;
use App\Model\Backup\BackupFacade;
use App\Model\Backup\BackupFrequency;
use App\Model\Backup\BackupTrigger;
use App\Model\Backup\Command\SaveBackupSettings;
use App\Model\Shared\Exception\NotFoundException;
use App\Model\Shared\Exception\ValidationException;
use App\UI\Form\FormErrors;
use App\UI\Form\FormFactory;
use Nette\Application\Attributes\Requires;
use Nette\Application\Responses\FileResponse;
use Nette\Application\UI\Form;
use Psr\Clock\ClockInterface;

/**
 * Zálohy celé instalace: plán pravidelných záloh, záloha teď, stažení a smazání.
 */
#[Allowed(Resource::Settings, Privilege::Edit)]
final class BackupPresenter extends SecuredPresenter
{
	public const array Weekdays = [
		1 => 'pondělí',
		2 => 'úterý',
		3 => 'středa',
		4 => 'čtvrtek',
		5 => 'pátek',
		6 => 'sobota',
		7 => 'neděle',
	];

	public function __construct(
		private readonly BackupFacade $backupFacade,
		private readonly FormFactory $formFactory,
		private readonly ClockInterface $clock,
	) {
		parent::__construct();
	}

	protected function startup(): void
	{
		parent::startup();
		if (!$this->canManageBackups()) {
			$this->error('Zálohy celé instalace spravuje správce systému.', 403);
		}
	}

	public function actionDefault(): void
	{
		$settings = $this->backupFacade->getSettings();
		$this->getComponent('settingsForm')->setDefaults([
			'enabled' => $settings->isEnabled(),
			'frequency' => $settings->getFrequency()->value,
			'hour' => $settings->getHour(),
			'weekday' => $settings->getWeekday(),
			'monthDay' => $settings->getMonthDay(),
			'content' => $settings->getContent()->value,
			'keep' => $settings->getKeep(),
		]);
	}

	public function renderDefault(): void
	{
		$settings = $this->backupFacade->getSettings();
		$now = $this->clock->now();
		$this->template->settings = $settings;
		$this->template->backups = $this->backupFacade->list();
		$this->template->totalSize = BackupFacade::formatSize($this->backupFacade->totalSize());
		$this->template->nextRun = $settings->isEnabled() ? $settings->nextSlot($now) : null;
		$this->template->due = $settings->isDue($now);
		$this->template->cronLine = BackupFacade::cronLine();
		$this->template->contents = BackupContent::cases();
		$this->template->formatSize = BackupFacade::formatSize(...);
	}

	public function actionDownload(string $name): void
	{
		try {
			$file = $this->backupFacade->get($name);
		} catch (NotFoundException $e) {
			$this->error($e->getMessage());
		}

		$this->sendResponse(new FileResponse($file->path, $file->name, 'application/zip'));
	}

	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleRun(string $content): void
	{
		$selected = BackupContent::tryFrom($content) ?? $this->error('Neznámý obsah zálohy.');
		// kompletní záloha velkých souborů může trvat déle než běžný požadavek
		set_time_limit(600);
		try {
			$file = $this->backupFacade->run($selected, BackupTrigger::Manual);
			$this->flashMessage(sprintf('Záloha je hotová (%s, %s). Můžete ji stáhnout níže.', $selected->label(), BackupFacade::formatSize($file->size)), 'success');
		} catch (BackupException $e) {
			$this->flashMessage('Záloha selhala: ' . $e->getMessage(), 'danger');
		}

		$this->redirect('this');
	}

	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleDelete(string $name): void
	{
		try {
			$this->backupFacade->delete($name);
			$this->flashMessage('Záloha byla smazána.', 'success');
		} catch (NotFoundException|BackupException $e) {
			$this->flashMessage($e->getMessage(), 'danger');
		}

		$this->redirect('this');
	}

	protected function createComponentSettingsForm(): Form
	{
		$form = $this->formFactory->create();
		$form->addCheckbox('enabled', 'Zálohovat pravidelně');
		$form->addSelect('frequency', 'Jak často', array_combine(
			array_map(static fn(BackupFrequency $f): string => $f->value, BackupFrequency::cases()),
			array_map(static fn(BackupFrequency $f): string => $f->label(), BackupFrequency::cases()),
		));
		$hours = [];
		foreach (range(0, 23) as $hour) {
			$hours[$hour] = sprintf('%d:00', $hour);
		}

		$form->addSelect('hour', 'V kolik hodin', $hours);
		$form->addSelect('weekday', 'Den v týdnu (týdenní záloha)', self::Weekdays);
		$form->addInteger('monthDay', 'Den v měsíci (měsíční záloha)')
			->addRule(Form::Range, 'Den v měsíci musí být 1 až 28.', [1, 28]);
		$form->addRadioList('content', 'Co zálohovat', array_combine(
			array_map(static fn(BackupContent $c): string => $c->value, BackupContent::cases()),
			array_map(static fn(BackupContent $c): string => $c->label(), BackupContent::cases()),
		));
		$form->addInteger('keep', 'Kolik posledních záloh držet')
			->addRule(Form::Range, 'Držet lze 1 až 100 záloh.', [1, 100])
			->setOption('description', 'Starší zálohy se po vytvoření nové samy smažou. Pozor na místo na disku u kompletních záloh se záznamy zasedání.');
		$form->addSubmit('save', 'Uložit plán');
		$form->onSuccess[] = function (Form $form): void {
			/** @var array{enabled: bool, frequency: string, hour: int, weekday: int, monthDay: int, content: string, keep: int} $values */
			$values = $form->getValues('array');
			try {
				$this->backupFacade->saveSettings(new SaveBackupSettings(
					$values['enabled'],
					BackupFrequency::from($values['frequency']),
					$values['hour'],
					$values['weekday'],
					$values['monthDay'],
					BackupContent::from($values['content']),
					$values['keep'],
				));
			} catch (ValidationException $e) {
				FormErrors::apply($form, $e);

				return;
			}

			$this->flashMessage('Plán záloh byl uložen.', 'success');
			$this->redirect('this');
		};

		return $form;
	}
}
