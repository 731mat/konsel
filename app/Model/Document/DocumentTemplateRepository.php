<?php

declare(strict_types=1);

namespace App\Model\Document;

use App\Model\Municipality\Municipality;
use App\Model\People\Body;

interface DocumentTemplateRepository
{
	public function find(Municipality $municipality, ?Body $body, DocumentTemplateType $type): ?DocumentTemplate;

	/**
	 * @return list<DocumentTemplate>
	 */
	public function findByMunicipality(Municipality $municipality): array;

	public function add(DocumentTemplate $template): void;

	public function remove(DocumentTemplate $template): void;
}
