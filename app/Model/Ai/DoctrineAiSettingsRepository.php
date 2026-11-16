<?php

declare(strict_types=1);

namespace App\Model\Ai;

use App\Model\Municipality\Municipality;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineAiSettingsRepository implements AiSettingsRepository
{
	public function __construct(
		private EntityManagerInterface $entityManager,
	) {}

	public function findByMunicipality(Municipality $municipality): ?AiSettings
	{
		return $this->entityManager->getRepository(AiSettings::class)->findOneBy([
			'municipality' => $municipality,
		]);
	}

	public function add(AiSettings $settings): void
	{
		$this->entityManager->persist($settings);
	}
}
