<?php

declare(strict_types=1);

namespace App\Domain\People;

use App\Domain\Shared\Exception\NotFoundException;

interface BodyRepository
{
	/**
	 * @throws NotFoundException
	 */
	public function get(int $id): Body;

	/**
	 * @return list<Body> zastupitelstvo, rada, výbory
	 */
	public function findByTerm(Term $term): array;

	public function findByTermAndType(Term $term, BodyType $type): ?Body;

	public function add(Body $body): void;

	public function remove(Body $body): void;
}
