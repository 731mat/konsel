<?php

declare(strict_types=1);

namespace App\Model\Backup;

use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineBackupSettingsRepository implements BackupSettingsRepository
{
	public function __construct(
		private EntityManagerInterface $entityManager,
	) {}

	public function get(): BackupSettings
	{
		$settings = $this->entityManager->getRepository(BackupSettings::class)->findOneBy([], [
			'id' => 'ASC',
		]);
		if ($settings === null) {
			$settings = new BackupSettings();
			$this->entityManager->persist($settings);
		}

		return $settings;
	}
}
