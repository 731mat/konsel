<?php

declare(strict_types=1);

namespace App\Model\Document;

use App\Model\Municipality\Municipality;
use App\Model\People\Body;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineDocumentTemplateRepository implements DocumentTemplateRepository
{
	public function __construct(
		private EntityManagerInterface $entityManager,
	) {}

	public function find(Municipality $municipality, ?Body $body, DocumentTemplateType $type): ?DocumentTemplate
	{
		return $this->entityManager->getRepository(DocumentTemplate::class)->findOneBy([
			'municipality' => $municipality,
			'body' => $body,
			'type' => $type,
		]);
	}

	public function findByMunicipality(Municipality $municipality): array
	{
		/** @var list<DocumentTemplate> */
		return $this->entityManager->getRepository(DocumentTemplate::class)->findBy([
			'municipality' => $municipality,
		]);
	}

	public function add(DocumentTemplate $template): void
	{
		$this->entityManager->persist($template);
	}

	public function remove(DocumentTemplate $template): void
	{
		$this->entityManager->remove($template);
	}
}
