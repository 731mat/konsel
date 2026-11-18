<?php

declare(strict_types=1);

namespace App\Model\Backup;

/**
 * Soubor zálohy ve var/backups. Údaje nese název: konsel-20261118-030000-full-scheduled.zip.
 */
final readonly class BackupFile
{
	public const string NamePattern = '~^konsel-(\d{8}-\d{6})-(full|db)-(manual|scheduled)\.zip$~';

	public function __construct(
		public string $name,
		public string $path,
		public int $size,
		public \DateTimeImmutable $createdAt,
		public BackupContent $content,
		public BackupTrigger $trigger,
	) {}

	public static function fileName(\DateTimeImmutable $at, BackupContent $content, BackupTrigger $trigger): string
	{
		return "konsel-{$at->format('Ymd-His')}-{$content->value}-{$trigger->value}.zip";
	}

	/**
	 * @return ?self null = soubor není záloha Konšela
	 */
	public static function fromPath(string $path, \DateTimeZone $timeZone): ?self
	{
		$name = basename($path);
		if (preg_match(self::NamePattern, $name, $match) !== 1 || !is_file($path)) {
			return null;
		}

		$createdAt = \DateTimeImmutable::createFromFormat('Ymd-His', $match[1], $timeZone);
		$size = filesize($path);
		if ($createdAt === false || $size === false) {
			return null;
		}

		return new self($name, $path, $size, $createdAt, BackupContent::from($match[2]), BackupTrigger::from($match[3]));
	}
}
