<?php

declare(strict_types=1);

namespace App\Model\Backup;

use App\Model\Backup\Command\SaveBackupSettings;
use App\Model\Shared\Exception\NotFoundException;
use App\Model\Shared\TransactionManager;
use Psr\Clock\ClockInterface;

/**
 * Zálohy celé instalace: databáze a nahrané soubory do jednoho ZIPu ve var/backups,
 * plán pravidelných záloh, stažení, úklid starých záloh.
 */
final readonly class BackupFacade
{
	public function __construct(
		private BackupSettingsRepository $settings,
		private DatabaseDumper $dumper,
		private TransactionManager $transaction,
		private ClockInterface $clock,
		private string $backupDir,
		private string $storageDir,
	) {}

	public function getSettings(): BackupSettings
	{
		return $this->transaction->run(fn(): BackupSettings => $this->settings->get());
	}

	/**
	 * @throws \App\Model\Shared\Exception\ValidationException
	 */
	public function saveSettings(SaveBackupSettings $command): void
	{
		$this->transaction->run(function () use ($command): void {
			$this->settings->get()->configure($command->enabled, $command->frequency, $command->hour, $command->weekday, $command->monthDay, $command->content, $command->keep);
		});
	}

	/**
	 * Zálohuje teď (ručně nebo podle plánu) a smaže zálohy nad limit.
	 *
	 * @throws BackupException
	 */
	public function run(BackupContent $content, BackupTrigger $trigger): BackupFile
	{
		$now = $this->clock->now();
		try {
			$file = $this->create($content, $trigger, $now);
		} catch (BackupException $e) {
			$this->transaction->run(fn() => $this->settings->get()->recordRun($now, false, $e->getMessage()));

			throw $e;
		}

		$this->transaction->run(fn() => $this->settings->get()->recordRun($now, true, sprintf('%s, %s', $content->label(), self::formatSize($file->size))));
		$this->prune($this->getSettings()->getKeep());

		return $file;
	}

	/**
	 * Pravidelná záloha z cronu – proběhne, jen když je podle plánu na řadě.
	 *
	 * @return ?BackupFile null = zatím není na řadě (nebo je plán vypnutý)
	 * @throws BackupException
	 */
	public function runScheduled(): ?BackupFile
	{
		$settings = $this->getSettings();

		return $settings->isDue($this->clock->now()) ? $this->run($settings->getContent(), BackupTrigger::Scheduled) : null;
	}

	/**
	 * @return list<BackupFile> od nejnovější
	 */
	public function list(): array
	{
		$files = [];
		$paths = glob($this->backupDir . '/konsel-*.zip');
		foreach ($paths === false ? [] : $paths as $path) {
			$file = BackupFile::fromPath($path, $this->clock->now()->getTimezone());
			if ($file !== null) {
				$files[] = $file;
			}
		}

		usort($files, static fn(BackupFile $a, BackupFile $b): int => [$b->createdAt, $b->name] <=> [$a->createdAt, $a->name]);

		return $files;
	}

	/**
	 * @throws NotFoundException neplatný název nebo soubor neexistuje
	 */
	public function get(string $name): BackupFile
	{
		if (preg_match(BackupFile::NamePattern, $name) !== 1) {
			throw new NotFoundException('Záloha neexistuje.');
		}

		return BackupFile::fromPath($this->backupDir . '/' . $name, $this->clock->now()->getTimezone())
			?? throw new NotFoundException('Záloha neexistuje.');
	}

	public function delete(string $name): void
	{
		$file = $this->get($name);
		if (!@unlink($file->path)) { // @ – selhání ohlásí výjimka
			throw new BackupException('Zálohu se nepodařilo smazat.');
		}
	}

	public function totalSize(): int
	{
		return array_sum(array_map(static fn(BackupFile $file): int => $file->size, $this->list()));
	}

	/**
	 * Řádek do crontabu serveru (spouští plán každou hodinu).
	 */
	public static function cronLine(string $consolePath = '/app/bin/console'): string
	{
		return "0 * * * * php {$consolePath} backup:run --scheduled";
	}

	public static function formatSize(int $bytes): string
	{
		return match (true) {
			$bytes >= 1024 ** 3 => number_format($bytes / 1024 ** 3, 1, ',', ' ') . ' GB',
			$bytes >= 1024 ** 2 => number_format($bytes / 1024 ** 2, 1, ',', ' ') . ' MB',
			default => number_format(max(1, intdiv($bytes, 1024)), 0, ',', ' ') . ' kB',
		};
	}

	/**
	 * @throws BackupException
	 */
	private function create(BackupContent $content, BackupTrigger $trigger, \DateTimeImmutable $now): BackupFile
	{
		if (!is_dir($this->backupDir) && !@mkdir($this->backupDir, 0o770, true) && !is_dir($this->backupDir)) { // @ – ohlásí výjimka
			throw new BackupException("Složku pro zálohy {$this->backupDir} nelze vytvořit.");
		}

		$name = BackupFile::fileName($now, $content, $trigger);
		$target = "{$this->backupDir}/{$name}";
		$partial = "{$target}.part";
		$dump = "{$this->backupDir}/.database-{$now->format('YmdHis')}-" . bin2hex(random_bytes(4));

		try {
			$extension = $this->dumper->dump($dump);
			$zip = new \ZipArchive();
			if ($zip->open($partial, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
				throw new BackupException('Soubor zálohy nelze vytvořit (místo na disku, oprávnění).');
			}

			$zip->addFile($dump, "database.{$extension}");
			$files = $content === BackupContent::Full ? $this->addStorage($zip) : 0;
			$zip->addFromString('manifest.json', (string) json_encode([
				'application' => 'Konšel',
				'createdAt' => $now->format(\DATE_ATOM),
				'content' => $content->value,
				'trigger' => $trigger->value,
				'database' => "database.{$extension}",
				'storageFiles' => $files,
				'restore' => 'Zastavte aplikaci, nahraďte var/database.sqlite souborem database.sqlite a obsah var/storage složkou storage, spusťte migrace.',
			], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
			if (!$zip->close()) {
				throw new BackupException('Soubor zálohy se nepodařilo dokončit (místo na disku).');
			}

			if (!rename($partial, $target)) {
				throw new BackupException('Soubor zálohy se nepodařilo uložit.');
			}
		} catch (BackupException $e) {
			@unlink($partial); // @ – nemusí existovat

			throw $e;
		} catch (\Throwable $e) {
			@unlink($partial); // @ – nemusí existovat

			throw new BackupException('Zálohu se nepodařilo vytvořit: ' . $e->getMessage(), 0, $e);
		} finally {
			@unlink($dump); // @ – nemusí existovat
		}

		return BackupFile::fromPath($target, $now->getTimezone()) ?? throw new BackupException('Soubor zálohy chybí.');
	}

	/**
	 * @return int počet přidaných souborů
	 */
	private function addStorage(\ZipArchive $zip): int
	{
		if (!is_dir($this->storageDir)) {
			return 0;
		}

		$count = 0;
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->storageDir, \FilesystemIterator::SKIP_DOTS));
		foreach ($iterator as $file) {
			if ($file instanceof \SplFileInfo && $file->isFile()) {
				$relative = ltrim(substr($file->getPathname(), strlen($this->storageDir)), '/');
				$zip->addFile($file->getPathname(), "storage/{$relative}");
				// soubory (záznamy, PDF) jsou už komprimované – zbytečně je nezmenšovat
				$zip->setCompressionName("storage/{$relative}", \ZipArchive::CM_STORE);
				$count++;
			}
		}

		return $count;
	}

	private function prune(int $keep): void
	{
		foreach (array_slice($this->list(), $keep) as $old) {
			@unlink($old->path); // @ – smazaný mezitím
		}
	}
}
