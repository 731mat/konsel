<?php

declare(strict_types=1);

namespace App\Model\AiAccess;

use App\Model\Municipality\Module;
use App\Model\Municipality\MunicipalityRepository;
use App\Model\Shared\Exception\AccessDeniedException;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\TransactionManager;
use App\Model\User\User;
use App\Model\User\UserRepository;
use Psr\Clock\ClockInterface;

/**
 * Připojení AI asistentů k účtům uživatelů: osobní tokeny, ověření tokenu při každém požadavku MCP,
 * přehled a odvolání připojení.
 */
final readonly class AiAccessFacade
{
	public const string PersonalPrefix = 'ksl_';

	/**
	 * Odvolaná a vypršelá připojení se mažou po 30 dnech, nepoužité registrace aplikací také.
	 */
	private const string StaleAfter = '-30 days';

	public function __construct(
		private AiAccessTokenRepository $tokens,
		private OAuthClientRepository $clients,
		private AuthorizationCodeRepository $codes,
		private UserRepository $users,
		private MunicipalityRepository $municipalities,
		private TransactionManager $transaction,
		private ClockInterface $clock,
	) {}

	/**
	 * Osobní token pro AI klienta, který umí poslat hlavičku Authorization (Claude Code, vlastní skripty…).
	 *
	 * @throws ValidationException
	 */
	public function createPersonalToken(int $userId, string $name, AccessScope $scope, int $validDays): IssuedAccessToken
	{
		return $this->transaction->run(function () use ($userId, $name, $scope, $validDays): IssuedAccessToken {
			$user = $this->users->get($userId);
			self::assertAllowedFor($user);
			$plain = Secret::generate(self::PersonalPrefix);
			$token = AiAccessToken::personal($user, $name, $scope, $plain, $validDays, $this->clock->now());
			$this->tokens->add($token);

			return new IssuedAccessToken($token, $plain);
		});
	}

	/**
	 * Ověření tokenu z hlavičky Authorization: Bearer – platnost, aktivní účet, zapnutý modul obce.
	 *
	 * @throws AccessDeniedException
	 */
	public function authenticate(#[\SensitiveParameter] string $bearer): AiAccessToken
	{
		return $this->transaction->run(function () use ($bearer): AiAccessToken {
			$now = $this->clock->now();
			$token = preg_match('~^[A-Za-z0-9_-]{20,100}$~', $bearer) === 1 ? $this->tokens->findByTokenHash(Secret::hash($bearer)) : null;
			if ($token === null || !$token->isUsableAt($now)) {
				throw new AccessDeniedException('Token je neplatný, odvolaný nebo vypršel.');
			}

			try {
				self::assertAllowedFor($token->getUser());
			} catch (ValidationException $e) {
				throw new AccessDeniedException($e->getMessage(), $e);
			}

			$token->recordUse($now);

			return $token;
		});
	}

	/**
	 * Účet smí mít připojení AI: aktivní uživatel obce se zapnutým modulem.
	 *
	 * @throws ValidationException
	 */
	public static function assertAllowedFor(User $user): void
	{
		if (!$user->isActive()) {
			throw new ValidationException('Účet uživatele je deaktivovaný.');
		}

		if (!AiAccessToken::municipalityOf($user)->isModuleEnabled(Module::AiConnections)) {
			throw new ValidationException('Obec má připojení AI asistentů vypnuté (Nastavení → Moduly).');
		}
	}

	/**
	 * Proč si uživatel nemůže připojit AI (null = může) – pro stránku souhlasu a přehled.
	 */
	public function accountProblem(int $userId): ?string
	{
		try {
			self::assertAllowedFor($this->users->get($userId));
		} catch (ValidationException $e) {
			return $e->getMessage();
		}

		return null;
	}

	public function get(int $tokenId): AiAccessToken
	{
		return $this->tokens->get($tokenId);
	}

	/**
	 * Platná připojení uživatele (osobní tokeny i aplikace).
	 *
	 * @return list<AiAccessToken>
	 */
	public function findForUser(int $userId): array
	{
		return $this->active($this->tokens->findByUser($this->users->get($userId)));
	}

	/**
	 * Platná připojení všech uživatelů obce (přehled pro správce).
	 *
	 * @return list<AiAccessToken>
	 */
	public function findForMunicipality(int $municipalityId): array
	{
		return $this->active($this->tokens->findByMunicipality($this->municipalities->get($municipalityId)));
	}

	public function revoke(int $tokenId): void
	{
		$this->transaction->run(function () use ($tokenId): void {
			$this->tokens->get($tokenId)->revoke($this->clock->now());
		});
	}

	/**
	 * Úklid: prošlé kódy, odvolaná/vypršelá připojení a nepoužité registrace aplikací.
	 *
	 * @return int počet smazaných záznamů
	 */
	public function cleanup(): int
	{
		return $this->transaction->run(function (): int {
			$now = $this->clock->now();
			$stale = $now->modify(self::StaleAfter);

			return $this->codes->deleteExpired($now->modify('-1 day'))
				+ $this->tokens->deleteStale($stale)
				+ $this->clients->deleteUnused($stale);
		});
	}

	/**
	 * @param list<AiAccessToken> $tokens
	 * @return list<AiAccessToken>
	 */
	private function active(array $tokens): array
	{
		$now = $this->clock->now();

		return array_values(array_filter($tokens, static fn(AiAccessToken $token): bool => $token->isActiveAt($now)));
	}
}
