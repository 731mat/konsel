<?php

declare(strict_types=1);

namespace App\Model\Installation;

use App\Core\Security\SecretBox;
use App\Model\Backup\BackupContent;
use App\Model\Backup\BackupFacade;
use App\Model\Backup\BackupFrequency;
use App\Model\Backup\Command\SaveBackupSettings;
use App\Model\Installation\Command\Install;
use App\Model\Municipality\Command\CreateMunicipality;
use App\Model\Municipality\Command\UpdateMunicipalitySettings;
use App\Model\Municipality\MunicipalityFacade;
use App\Model\People\BodyType;
use App\Model\People\Command\SaveBody;
use App\Model\People\PeopleFacade;
use App\Model\Shared\Exception\InvalidStateException;
use App\Model\Shared\TransactionManager;
use App\Model\Template\TemplateFacade;
use App\Model\User\Command\CreateUser;
use App\Model\User\Role;
use App\Model\User\User;
use App\Model\User\UserFacade;
use App\Model\User\UserRepository;

/**
 * Webová instalace prázdné instance: instalační kód (z logu kontejneru, aby instalaci nemohl dokončit cizí),
 * kontrola prostředí a založení obce, orgánů, správce a výchozího nastavení najednou.
 */
final readonly class InstallationFacade
{
	/**
	 * Bez zaměnitelných znaků (0/O, 1/I/L).
	 */
	private const string CodeAlphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

	private const array RequiredExtensions = ['pdo_sqlite', 'intl', 'mbstring', 'gd', 'zip', 'sodium', 'fileinfo'];

	/**
	 * @param string $codeFile soubor s instalačním kódem (var/install.code)
	 * @param array<string, string> $writableDirs složky, kam musí aplikace zapisovat (popis → cesta)
	 */
	public function __construct(
		private string $codeFile,
		private array $writableDirs,
		private UserRepository $users,
		private MunicipalityFacade $municipalities,
		private PeopleFacade $people,
		private UserFacade $userFacade,
		private TemplateFacade $templates,
		private BackupFacade $backups,
		private SecretBox $secretBox,
		private TransactionManager $transaction,
	) {}

	/**
	 * Instalace je potřeba, dokud neexistuje žádný uživatel.
	 */
	public function isRequired(): bool
	{
		return !$this->users->hasAny();
	}

	/**
	 * Instalační kód – vznikne při prvním dotazu a platí až do dokončení instalace.
	 */
	public function code(): string
	{
		$existing = is_file($this->codeFile) ? trim((string) file_get_contents($this->codeFile)) : '';
		if (preg_match('/^[A-Z0-9]{5}-[A-Z0-9]{5}$/', $existing) === 1) {
			return $existing;
		}

		$code = '';
		for ($i = 0; $i < 10; $i++) {
			$code .= self::CodeAlphabet[random_int(0, strlen(self::CodeAlphabet) - 1)];
		}

		$code = substr($code, 0, 5) . '-' . substr($code, 5);
		@mkdir(dirname($this->codeFile), 0o775, true);
		file_put_contents($this->codeFile, $code . "\n");
		@chmod($this->codeFile, 0o600);

		return $code;
	}

	public function verifyCode(#[\SensitiveParameter] string $code): bool
	{
		$normalize = static fn(string $value): string => strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $value));

		return $this->isRequired() && $normalize($code) !== '' && hash_equals($normalize($this->code()), $normalize($code));
	}

	/**
	 * @return list<InstallationCheck>
	 */
	public function checks(): array
	{
		$checks = [
			new InstallationCheck('PHP', true, 'Verze ' . PHP_VERSION, blocking: false),
		];
		foreach (self::RequiredExtensions as $extension) {
			$checks[] = new InstallationCheck("Rozšíření PHP {$extension}", extension_loaded($extension), extension_loaded($extension) ? 'načteno' : 'chybí – doplňte do image PHP');
		}

		foreach ($this->writableDirs as $label => $dir) {
			$writable = is_dir($dir) && is_writable($dir);
			$checks[] = new InstallationCheck("Zápis do {$label}", $writable, $writable ? 'v pořádku' : "Složka {$dir} neexistuje nebo do ní nejde zapisovat (práva webového serveru).");
		}

		$checks[] = new InstallationCheck(
			'Šifrovací klíč aplikace',
			$this->secretBox->isConfigured(),
			$this->secretBox->isConfigured() ? 'nastavený' : 'Chybí – bez něj nepůjde uložit klíč k AI. Nastavte KONSEL_ENCRYPTION_KEY nebo encryptionKey v local.neon.',
			blocking: false,
		);
		$upload = ini_get('upload_max_filesize');
		$checks[] = new InstallationCheck('Velikost nahrávaných souborů', true, "upload_max_filesize = {$upload} (záznamy zasedání až 500 MB)", blocking: false);

		return $checks;
	}

	/**
	 * @param list<InstallationCheck> $checks
	 */
	public static function canContinue(array $checks): bool
	{
		foreach ($checks as $check) {
			if ($check->blocking && !$check->ok) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Založí obec, volební období a orgány, správce obce, výchozí šablony a plán záloh.
	 *
	 * @throws InvalidStateException instalace už proběhla
	 * @throws \App\Model\Shared\Exception\ValidationException neplatný údaj (pole formuláře v $field)
	 */
	public function install(Install $command): User
	{
		$user = $this->transaction->run(function () use ($command): User {
			if (!$this->isRequired()) {
				throw new InvalidStateException('Instalace už proběhla – přihlaste se.');
			}

			$municipality = $this->municipalities->create(new CreateMunicipality($command->municipalityName, $command->ico));
			$id = $municipality->getId();
			$this->municipalities->updateSettings($id, new UpdateMunicipalitySettings(
				name: $command->municipalityName,
				officialName: $command->officialName,
				ico: $command->ico,
				street: $command->street,
				city: $command->city,
				postalCode: $command->postalCode,
				email: $command->email,
			));
			$this->municipalities->changeModules($id, $command->modules);

			$term = $this->people->createTerm($id, $command->termName, $command->termStartsOn);
			$this->people->createBody($term->getId(), new SaveBody(BodyType::Council, $command->councilName, $command->councilSeats));
			if ($command->boardName !== null && $command->boardSeats !== null) {
				$this->people->createBody($term->getId(), new SaveBody(BodyType::Board, $command->boardName, $command->boardSeats));
			}

			$user = $this->userFacade->create(new CreateUser($command->adminEmail, $command->adminName, Role::Admin, $command->adminPassword, $id));
			if ($command->installTemplates) {
				$this->templates->installDefaultResolutionTemplates($id);
				$this->templates->installDefaultMeetingTemplates($id);
			}

			if ($command->dailyBackup) {
				$this->backups->saveSettings(new SaveBackupSettings(true, BackupFrequency::Daily, 2, 1, 1, BackupContent::Full, 14));
			}

			return $user;
		});

		@unlink($this->codeFile);

		return $user;
	}
}
