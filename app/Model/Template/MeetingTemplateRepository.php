<?php

declare(strict_types=1);

namespace App\Model\Template;

use App\Model\Municipality\Municipality;
use App\Model\Shared\Exception\NotFoundException;

interface MeetingTemplateRepository
{
	/**
	 * @throws NotFoundException
	 */
	public function get(int $id): MeetingTemplate;

	/**
	 * @return list<MeetingTemplate>
	 */
	public function findByMunicipality(Municipality $municipality): array;

	public function add(MeetingTemplate $template): void;

	public function remove(MeetingTemplate $template): void;
}
