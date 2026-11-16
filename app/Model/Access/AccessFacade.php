<?php

declare(strict_types=1);

namespace App\Model\Access;

use App\Model\Meeting\Meeting;
use App\Model\Meeting\MeetingRepository;
use App\Model\People\Person;
use App\Model\People\PersonRepository;
use App\Model\Shared\Exception\AccessDeniedException;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\TransactionManager;
use Psr\Clock\ClockInterface;

/**
 * Osobní odkazy zastupitelů k zasedání a vstup do portálu.
 */
final readonly class AccessFacade
{
	/**
	 * Odkaz platí ještě 60 dní po zasedání (podklady k dohledání, ověření zápisu).
	 */
	public const int ValidityDays = 60;

	public function __construct(
		private MeetingAccessTokenRepository $tokens,
		private MeetingRepository $meetings,
		private PersonRepository $persons,
		private TransactionManager $transaction,
		private ClockInterface $clock,
	) {}

	/**
	 * Nové odkazy pro všechny členy orgánu k datu zasedání. Dosavadní platné odkazy těchto osob se odvolají.
	 *
	 * @return list<IssuedToken>
	 */
	public function issueForMembers(int $meetingId): array
	{
		return $this->transaction->run(function () use ($meetingId): array {
			$meeting = $this->meetings->get($meetingId);
			$issued = [];
			foreach ($meeting->getBody()->getMembersAt($meeting->getScheduledAt()) as $membership) {
				$issued[] = $this->issue($meeting, $membership->getPerson());
			}

			if ($issued === []) {
				throw new ValidationException('Orgán nemá k datu zasedání žádné členy.');
			}

			return $issued;
		});
	}

	/**
	 * Odkaz pro jednu osobu obce (člen orgánu nebo host, např. předkladatel).
	 */
	public function issueFor(int $meetingId, int $personId): IssuedToken
	{
		return $this->transaction->run(function () use ($meetingId, $personId): IssuedToken {
			$meeting = $this->meetings->get($meetingId);
			$person = $this->persons->get($personId);
			if ($person->getMunicipality() !== $meeting->getMunicipality()) {
				throw new ValidationException('Osoba patří k jiné obci.', 'person');
			}

			return $this->issue($meeting, $person);
		});
	}

	public function revoke(int $tokenId): void
	{
		$this->transaction->run(function () use ($tokenId): void {
			$this->tokens->get($tokenId)->revoke($this->clock->now());
		});
	}

	/**
	 * @return list<MeetingAccessToken>
	 */
	public function findByMeeting(int $meetingId): array
	{
		return $this->tokens->findByMeeting($this->meetings->get($meetingId));
	}

	/**
	 * Vstup z odkazu: ověří token a zaznamená použití.
	 *
	 * @throws AccessDeniedException neplatný, odvolaný nebo prošlý odkaz
	 */
	public function authenticate(#[\SensitiveParameter] string $token): MeetingAccessToken
	{
		return $this->transaction->run(function () use ($token): MeetingAccessToken {
			$accessToken = preg_match('~^[A-Za-z0-9_-]{43}$~', $token) === 1 ? $this->tokens->findByHash(MeetingAccessToken::hash($token)) : null;
			$now = $this->clock->now();
			if ($accessToken === null || !$accessToken->isValidAt($now)) {
				throw new AccessDeniedException('Odkaz je neplatný nebo jeho platnost skončila. Požádejte obecní úřad o nový.');
			}

			$accessToken->recordUse($now);

			return $accessToken;
		});
	}

	/**
	 * Kontrola při každém požadavku portálu (odkaz mohl být mezitím odvolán).
	 *
	 * @throws AccessDeniedException
	 */
	public function getValid(int $tokenId): MeetingAccessToken
	{
		$accessToken = $this->tokens->findById($tokenId);
		if ($accessToken === null || !$accessToken->isValidAt($this->clock->now())) {
			throw new AccessDeniedException('Platnost odkazu skončila. Požádejte obecní úřad o nový.');
		}

		return $accessToken;
	}

	private function issue(Meeting $meeting, Person $person): IssuedToken
	{
		$now = $this->clock->now();
		foreach ($this->tokens->findActive($meeting, $person) as $previous) {
			$previous->revoke($now);
		}

		$token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
		$base = max($now, $meeting->getScheduledAt());
		$accessToken = new MeetingAccessToken($meeting, $person, MeetingAccessToken::hash($token), $now, $base->modify('+' . self::ValidityDays . ' days'));
		$this->tokens->add($accessToken);

		return new IssuedToken($accessToken, $token);
	}
}
