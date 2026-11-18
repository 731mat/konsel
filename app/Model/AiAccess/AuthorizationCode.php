<?php

declare(strict_types=1);

namespace App\Model\AiAccess;

use App\Model\Municipality\Municipality;
use App\Model\Shared\IntegerId;
use App\Model\Shared\TenantOwned;
use App\Model\User\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * Jednorázový kód po souhlasu uživatele (OAuth authorization code). Platí 10 minut, vázaný na klienta,
 * návratovou adresu a PKCE výzvu.
 */
#[ORM\Entity]
#[ORM\Table(name: 'oauth_authorization_code')]
final class AuthorizationCode implements TenantOwned
{
	use IntegerId;

	public const int LifetimeSeconds = 600;

	#[ORM\ManyToOne(targetEntity: Municipality::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private Municipality $municipality;

	#[ORM\ManyToOne(targetEntity: OAuthClient::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private OAuthClient $client;

	#[ORM\ManyToOne(targetEntity: User::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private User $user;

	#[ORM\Column(length: 64, unique: true)]
	private string $codeHash;

	#[ORM\Column(length: 2000)]
	private string $redirectUri;

	#[ORM\Column(length: 128)]
	private string $codeChallenge;

	#[ORM\Column(length: 10, enumType: AccessScope::class)]
	private AccessScope $scope;

	#[ORM\Column]
	private \DateTimeImmutable $expiresAt;

	#[ORM\Column(nullable: true)]
	private ?\DateTimeImmutable $usedAt = null;

	/**
	 * @throws OAuthException
	 */
	public function __construct(Municipality $municipality, OAuthClient $client, User $user, string $codeHash, string $redirectUri, string $codeChallenge, AccessScope $scope, \DateTimeImmutable $now)
	{
		if (!$client->allowsRedirectUri($redirectUri)) {
			throw new OAuthException('invalid_request', 'redirect_uri is not registered for this client.');
		}

		if (!Pkce::isValidChallenge($codeChallenge)) {
			throw new OAuthException('invalid_request', 'code_challenge (S256) is required.');
		}

		$this->municipality = $municipality;
		$this->client = $client;
		$this->user = $user;
		$this->codeHash = $codeHash;
		$this->redirectUri = $redirectUri;
		$this->codeChallenge = $codeChallenge;
		$this->scope = $scope;
		$this->expiresAt = $now->modify('+' . self::LifetimeSeconds . ' seconds');
	}

	/**
	 * Výměna kódu za token – jen jednou, týmž klientem, se stejnou adresou a správným PKCE ověřovačem.
	 *
	 * @throws OAuthException
	 */
	public function redeem(OAuthClient $client, string $redirectUri, #[\SensitiveParameter] string $codeVerifier, \DateTimeImmutable $now): void
	{
		if ($this->usedAt !== null || $now >= $this->expiresAt) {
			throw new OAuthException('invalid_grant', 'Authorization code is expired or already used.');
		}

		if ($client !== $this->client || $redirectUri !== $this->redirectUri) {
			throw new OAuthException('invalid_grant', 'Authorization code was issued to another client or redirect URI.');
		}

		if (!Pkce::verify($codeVerifier, $this->codeChallenge)) {
			throw new OAuthException('invalid_grant', 'PKCE verification failed.');
		}

		$this->usedAt = $now;
	}

	public function getMunicipality(): Municipality
	{
		return $this->municipality;
	}

	public function getClient(): OAuthClient
	{
		return $this->client;
	}

	public function getUser(): User
	{
		return $this->user;
	}

	public function getScope(): AccessScope
	{
		return $this->scope;
	}
}
