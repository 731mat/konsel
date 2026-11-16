<?php

declare(strict_types=1);

namespace App\Model\Template;

use App\Model\Municipality\Municipality;
use App\Model\Shared\Exception\NotFoundException;

interface ResolutionTemplateRepository
{
	/**
	 * @throws NotFoundException
	 */
	public function get(int $id): ResolutionTemplate;

	/**
	 * @return list<ResolutionTemplate> podle kategorie a názvu
	 */
	public function findByMunicipality(Municipality $municipality): array;

	public function add(ResolutionTemplate $template): void;

	public function remove(ResolutionTemplate $template): void;
}
