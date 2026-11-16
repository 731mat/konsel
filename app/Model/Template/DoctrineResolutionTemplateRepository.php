<?php

declare(strict_types=1);

namespace App\Model\Template;

use App\Model\Municipality\Municipality;
use App\Model\Shared\Exception\NotFoundException;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineResolutionTemplateRepository implements ResolutionTemplateRepository
{
	public function __construct(
		private EntityManagerInterface $entityManager,
	) {}

	public function get(int $id): ResolutionTemplate
	{
		return $this->entityManager->find(ResolutionTemplate::class, $id) ?? throw new NotFoundException('Šablona neexistuje.');
	}

	public function findByMunicipality(Municipality $municipality): array
	{
		return $this->entityManager->getRepository(ResolutionTemplate::class)->findBy([
			'municipality' => $municipality,
		], [
			'category' => 'ASC',
			'title' => 'ASC',
		]);
	}

	public function add(ResolutionTemplate $template): void
	{
		$this->entityManager->persist($template);
	}

	public function remove(ResolutionTemplate $template): void
	{
		$this->entityManager->remove($template);
	}
}
