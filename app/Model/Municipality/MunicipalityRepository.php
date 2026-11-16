<?php

declare(strict_types=1);

namespace App\Model\Municipality;

use App\Model\Shared\Exception\NotFoundException;
use App\Model\Shared\ValueObject\Ico;

interface MunicipalityRepository
{
	/**
	 * @throws NotFoundException
	 */
	public function get(int $id): Municipality;

	public function findBySlug(string $slug): ?Municipality;

	public function findByIco(Ico $ico): ?Municipality;

	/**
	 * @return list<Municipality>
	 */
	public function findAll(): array;

	public function add(Municipality $municipality): void;
}
