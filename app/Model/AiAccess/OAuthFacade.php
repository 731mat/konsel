<?php

declare(strict_types=1);

namespace App\Model\AiAccess;

use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\TransactionManager;
use App\Model\User\User;
use App\Model\User\UserRepository;
use Psr\Clock\ClockInterface;

/**
 * Autorizační server OAuth 2.1 pro server MCP: registrace aplikací (RFC 7591), souhlas uživatele
 * (authorization code + PKCE S256), vydání a obnovení tokenů, odvolání (RFC 7009).
 */
final readonly class OAuthFacade
{
	public function __construct(
		private OAuthClientRepository $clients,
		private AuthorizationCodeRepository $codes,
		private AiAccessTokenRepository $tokens,
		private UserRepository $users,
		private TransactionManager $transaction,
		private ClockInterface $clock,
	) {}

	/**
	 * @param list<string> $redirectUris
	 * @throws OAuthException
	 */
	public function registerClient(string $name, array $redirectUris, string $authMethod = 'none'): RegisteredClient
	{
		return $this->transaction->run(function () use ($name, $redirectUris, $authMethod): RegisteredClient {
			$secret = $authMethod === 'none' ? null : Secret::generate();
			$client = new OAuthClient(Secret::generate(), $secret === null ? null : Secret::hash($secret), $name, $redirectUris, $authMethod, $this->clock->now());
			$this->clients->add($client);

			return new RegisteredClient($client, $secret);
		});
	}

	public function findClient(string $clientId): ?OAuthClient
	{
		return $clientId === '' ? null : $this->clients->findByClientId($clientId);
	}

	/**
	 * Uživatel souhlasil s připojením – vydá jednorázový kód pro návrat do aplikace.
	 *
	 * @throws OAuthException neplatná adresa nebo PKCE
	 * @throws ValidationException účet nesmí mít připojení AI
	 */
	public function authorize(int $userId, string $clientId, string $redirectUri, string $codeChallenge, AccessScope $scope): string
	{
		return $this->transaction->run(function () use ($userId, $clientId, $redirectUri, $codeChallenge, $scope): string {
			$client = $this->findClient($clientId) ?? throw new OAuthException('invalid_client', 'Unknown client_id.');
			$user = $this->users->get($userId);
			AiAccessFacade::assertAllowedFor($user);
			$code = Secret::generate();
			$this->codes->add(new AuthorizationCode(AiAccessToken::municipalityOf($user), $client, $user, Secret::hash($code), $redirectUri, $codeChallenge, $scope, $this->clock->now()));

			return $code;
		});
	}

	/**
	 * grant_type=authorization_code
	 *
	 * @throws OAuthException
	 */
	public function exchangeCode(string $clientId, #[\SensitiveParameter] ?string $clientSecret, #[\SensitiveParameter] string $code, string $redirectUri, #[\SensitiveParameter] string $codeVerifier): IssuedAccessToken
	{
		return $this->transaction->run(function () use ($clientId, $clientSecret, $code, $redirectUri, $codeVerifier): IssuedAccessToken {
			$client = $this->authenticateClient($clientId, $clientSecret);
			$authorization = $this->codes->findByHash(Secret::hash($code)) ?? throw new OAuthException('invalid_grant', 'Unknown authorization code.');
			$now = $this->clock->now();
			$authorization->redeem($client, $redirectUri, $codeVerifier, $now);
			$user = $authorization->getUser();
			$this->assertUser($user);
			$access = Secret::generate('ksla_');
			$refresh = Secret::generate('kslr_');
			$token = AiAccessToken::oauth($user, $client, $authorization->getScope(), $access, $refresh, $now);
			$this->tokens->add($token);

			return new IssuedAccessToken($token, $access, $refresh);
		});
	}

	/**
	 * grant_type=refresh_token – vydá nový pár, starý obnovovací token přestane platit.
	 *
	 * @throws OAuthException
	 */
	public function refresh(string $clientId, #[\SensitiveParameter] ?string $clientSecret, #[\SensitiveParameter] string $refreshToken): IssuedAccessToken
	{
		return $this->transaction->run(function () use ($clientId, $clientSecret, $refreshToken): IssuedAccessToken {
			$client = $this->authenticateClient($clientId, $clientSecret);
			$token = $this->tokens->findByRefreshHash(Secret::hash($refreshToken)) ?? throw new OAuthException('invalid_grant', 'Unknown refresh token.');
			$this->assertUser($token->getUser());
			$access = Secret::generate('ksla_');
			$refresh = Secret::generate('kslr_');
			$token->rotate($client, $access, $refresh, $this->clock->now());

			return new IssuedAccessToken($token, $access, $refresh);
		});
	}

	/**
	 * Odvolání přístupovým nebo obnovovacím tokenem; neznámý token se tiše ignoruje (RFC 7009).
	 */
	public function revokeToken(#[\SensitiveParameter] string $token): void
	{
		$this->transaction->run(function () use ($token): void {
			$hash = Secret::hash($token);
			($this->tokens->findByTokenHash($hash) ?? $this->tokens->findByRefreshHash($hash))?->revoke($this->clock->now());
		});
	}

	/**
	 * @throws OAuthException
	 */
	private function authenticateClient(string $clientId, #[\SensitiveParameter] ?string $secret): OAuthClient
	{
		$client = $this->findClient($clientId);
		if ($client === null || !$client->authenticate($secret)) {
			throw new OAuthException('invalid_client', 'Client authentication failed.');
		}

		return $client;
	}

	/**
	 * @throws OAuthException
	 */
	private function assertUser(User $user): void
	{
		try {
			AiAccessFacade::assertAllowedFor($user);
		} catch (ValidationException $e) {
			throw new OAuthException('invalid_grant', $e->getMessage());
		}
	}
}
