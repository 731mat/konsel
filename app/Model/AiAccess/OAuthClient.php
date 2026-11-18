<?php

declare(strict_types=1);

namespace App\Model\AiAccess;

use App\Model\Shared\IntegerId;
use Doctrine\ORM\Mapping as ORM;

/**
 * Aplikace zaregistrovaná přes OAuth Dynamic Client Registration (RFC 7591) – např. Claude nebo ChatGPT.
 * Registrace je veřejná (tak to MCP vyžaduje); k datům se aplikace dostane až souhlasem přihlášeného uživatele.
 * Název uvádí aplikace sama, proto se v UI ukazuje i adresa, kam se vrací.
 */
#[ORM\Entity]
#[ORM\Table(name: 'oauth_client')]
final class OAuthClient
{
	use IntegerId;

	public const array AuthMethods = ['none', 'client_secret_post', 'client_secret_basic'];

	#[ORM\Column(length: 64, unique: true)]
	private string $clientId;

	#[ORM\Column(length: 64, nullable: true)]
	private ?string $secretHash;

	#[ORM\Column(length: 120)]
	private string $name;

	/**
	 * @var list<string>
	 */
	#[ORM\Column(type: 'json')]
	private array $redirectUris;

	#[ORM\Column(length: 30)]
	private string $authMethod;

	#[ORM\Column]
	private \DateTimeImmutable $createdAt;

	/**
	 * @param list<string> $redirectUris
	 * @throws OAuthException
	 */
	public function __construct(string $clientId, ?string $secretHash, string $name, array $redirectUris, string $authMethod, \DateTimeImmutable $createdAt)
	{
		if ($redirectUris === [] || count($redirectUris) > 10) {
			throw new OAuthException('invalid_redirect_uri', 'Between 1 and 10 redirect URIs are required.');
		}

		if (!in_array($authMethod, self::AuthMethods, true)) {
			throw new OAuthException('invalid_client_metadata', "Unsupported token_endpoint_auth_method: {$authMethod}");
		}

		if (($authMethod === 'none') !== ($secretHash === null)) {
			throw new \LogicException('Client secret must be set exactly for confidential clients.');
		}

		$this->clientId = $clientId;
		$this->secretHash = $secretHash;
		$name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name) ?? '');
		$this->name = mb_substr($name === '' ? 'Neznámá aplikace' : $name, 0, 120);
		$this->redirectUris = array_values(array_unique(array_map(RedirectUri::validate(...), $redirectUris)));
		$this->authMethod = $authMethod;
		$this->createdAt = $createdAt;
	}

	/**
	 * Ověření klienta na token endpointu (veřejný klient tajemství nemá).
	 */
	public function authenticate(#[\SensitiveParameter] ?string $secret): bool
	{
		if ($this->secretHash === null) {
			return true;
		}

		return $secret !== null && hash_equals($this->secretHash, Secret::hash($secret));
	}

	public function allowsRedirectUri(string $uri): bool
	{
		return RedirectUri::matches($uri, $this->redirectUris);
	}

	public function getClientId(): string
	{
		return $this->clientId;
	}

	public function getName(): string
	{
		return $this->name;
	}

	/**
	 * @return list<string>
	 */
	public function getRedirectUris(): array
	{
		return $this->redirectUris;
	}

	public function getAuthMethod(): string
	{
		return $this->authMethod;
	}

	public function getCreatedAt(): \DateTimeImmutable
	{
		return $this->createdAt;
	}
}
