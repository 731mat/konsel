<?php

declare(strict_types=1);

namespace App\Model\Template;

use App\Model\Municipality\Municipality;
use App\Model\Shared\Exception\NotFoundException;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineMeetingTemplateRepository implements MeetingTemplateRepository
{
	public function __construct(
		private EntityManagerInterface $entityManager,
	) {}

	public function get(int $id): MeetingTemplate
	{
		return $this->entityManager->find(MeetingTemplate::class, $id) ?? throw new NotFoundException('Šablona zasedání neexistuje.');
	}

	public function findByMunicipality(Municipality $municipality): array
	{
		return $this->entityManager->getRepository(MeetingTemplate::class)->findBy([
			'municipality' => $municipality,
		], [
			'name' => 'ASC',
		]);
	}

	public function add(MeetingTemplate $template): void
	{
		$this->entityManager->persist($template);
	}

	public function remove(MeetingTemplate $template): void
	{
		$this->entityManager->remove($template);
	}
}
