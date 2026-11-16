<?php

declare(strict_types=1);

namespace App\Domain\People;

use App\Domain\Municipality\Municipality;
use App\Domain\Shared\Exception\NotFoundException;

interface TermRepository
{
	/**
	 * @throws NotFoundException
	 */
	public function get(int $id): Term;

	/**
	 * @return list<Term> od nejnovějšího
	 */
	public function findByMunicipality(Municipality $municipality): array;

	public function findAt(Municipality $municipality, \DateTimeImmutable $date): ?Term;

	public function add(Term $term): void;
}
