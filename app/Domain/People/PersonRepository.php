<?php

declare(strict_types=1);

namespace App\Domain\People;

use App\Domain\Municipality\Municipality;
use App\Domain\Shared\Exception\NotFoundException;

interface PersonRepository
{
	/**
	 * @throws NotFoundException
	 */
	public function get(int $id): Person;

	/**
	 * @return list<Person> seřazené podle příjmení a jména
	 */
	public function findByMunicipality(Municipality $municipality): array;

	public function hasMemberships(Person $person): bool;

	public function add(Person $person): void;

	public function remove(Person $person): void;
}
