<?php

declare(strict_types=1);

namespace App\Model\Access;

use App\Model\Meeting\Meeting;
use App\Model\People\Person;
use App\Model\Shared\Exception\NotFoundException;

interface MeetingAccessTokenRepository
{
	/**
	 * @throws NotFoundException
	 */
	public function get(int $id): MeetingAccessToken;

	/**
	 * Hledá napříč obcemi (portál nastavuje obec až podle odkazu).
	 */
	public function findById(int $id): ?MeetingAccessToken;

	/**
	 * Hledá napříč obcemi (vstup do portálu proběhne dřív, než je známá obec).
	 */
	public function findByHash(string $tokenHash): ?MeetingAccessToken;

	/**
	 * @return list<MeetingAccessToken> nejnovější první
	 */
	public function findByMeeting(Meeting $meeting): array;

	/**
	 * @return list<MeetingAccessToken> platné (neodvolané) odkazy osoby k zasedání
	 */
	public function findActive(Meeting $meeting, Person $person): array;

	public function add(MeetingAccessToken $token): void;
}
