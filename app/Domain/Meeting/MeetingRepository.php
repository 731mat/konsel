<?php

declare(strict_types=1);

namespace App\Domain\Meeting;

use App\Domain\Municipality\Municipality;
use App\Domain\People\Body;
use App\Domain\People\Person;
use App\Domain\Shared\Exception\NotFoundException;

interface MeetingRepository
{
	/**
	 * @throws NotFoundException
	 */
	public function get(int $id): Meeting;

	/**
	 * @return list<Meeting> seřazené od nejnovějšího
	 */
	public function findByFilter(Municipality $municipality, MeetingFilter $filter): array;

	/**
	 * Zasedání, která ještě neskončila (v přípravě, sdílená, probíhající), od nejbližšího.
	 *
	 * @return list<Meeting>
	 */
	public function findUnfinished(Municipality $municipality, int $limit): array;

	/**
	 * Proběhlá zasedání od posledního.
	 *
	 * @return list<Meeting>
	 */
	public function findFinished(Municipality $municipality, int $limit): array;

	/**
	 * @return list<int> roky, ve kterých obec má zasedání, sestupně
	 */
	public function findYears(Municipality $municipality): array;

	public function findByBodyAndNumber(Body $body, int $number): ?Meeting;

	public function findConstituent(Body $body): ?Meeting;

	public function findLast(Municipality $municipality): ?Meeting;

	public function getMaxNumber(Body $body): int;

	/**
	 * Osoba je u některého zasedání předsedající, zapisovatel nebo ověřovatel.
	 */
	public function hasOfficial(Person $person): bool;

	public function add(Meeting $meeting): void;

	public function remove(Meeting $meeting): void;
}
