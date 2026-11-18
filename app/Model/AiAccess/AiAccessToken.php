<?php

declare(strict_types=1);

namespace App\Model\AiAccess;

use App\Model\Municipality\Municipality;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\IntegerId;
use App\Model\Shared\TenantOwned;
use App\Model\User\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * Připojení AI asistenta k účtu uživatele (server MCP). AI jedná jménem uživatele – s jeho rolí,
 * v jeho obci a jen v povoleném rozsahu. V DB jsou jen hashe tokenů.
 *
 * Osobní token platí, dokud nevyprší nebo není odvolán. Připojená aplikace (OAuth) má krátký přístupový
 * token a obnovovací token; obnovením se oba vymění (starý obnovovací token přestane platit).
 */
#[ORM\Entity]
#[ORM\Table(name: 'ai_access_token')]
final class AiAccessToken implements TenantOwned
{
	use IntegerId;

	public const int OAuthAccessSeconds = 3600;

	public const int OAuthRefreshDays = 90;

	/**
	 * Naposledy použito se zapisuje nejvýš jednou za minutu (MCP posílá hodně požadavků za sebou).
	 */
	private const int UseRecordingSeconds = 60;

	#[ORM\ManyToOne(targetEntity: Municipality::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private Municipality $municipality;

	#[ORM\ManyToOne(targetEntity: User::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private User $user;

	#[ORM\ManyToOne(targetEntity: OAuthClient::class)]
	#[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
	private ?OAuthClient $client;

	#[ORM\Column(length: 20, enumType: AccessTokenKind::class)]
	private AccessTokenKind $kind;

	#[ORM\Column(length: 120)]
	private string $name;

	#[ORM\Column(length: 10, enumType: AccessScope::class)]
	private AccessScope $scope;

	#[ORM\Column(length: 64, unique: true)]
	private string $tokenHash;

	#[ORM\Column(length: 64, unique: true, nullable: true)]
	private ?string $refreshTokenHash;

	/**
	 * Začátek osobního tokenu pro rozpoznání v seznamu (např. „ksl_Ab3d“).
	 */
	#[ORM\Column(length: 12, nullable: true)]
	private ?string $hint;

	#[ORM\Column]
	private \DateTimeImmutable $createdAt;

	#[ORM\Column]
	private \DateTimeImmutable $expiresAt;

	#[ORM\Column(nullable: true)]
	private ?\DateTimeImmutable $refreshExpiresAt;

	#[ORM\Column(nullable: true)]
	private ?\DateTimeImmutable $lastUsedAt = null;

	#[ORM\Column(nullable: true)]
	private ?\DateTimeImmutable $revokedAt = null;

	private function __construct(
		User $user,
		?OAuthClient $client,
		AccessTokenKind $kind,
		string $name,
		AccessScope $scope,
		string $tokenHash,
		?string $refreshTokenHash,
		?string $hint,
		\DateTimeImmutable $createdAt,
		\DateTimeImmutable $expiresAt,
		?\DateTimeImmutable $refreshExpiresAt,
	) {
		$this->municipality = self::municipalityOf($user);
		$name = trim($name);
		if ($name === '' || mb_strlen($name) > 120) {
			throw new ValidationException('Zadejte název připojení (nejvýše 120 znaků).', 'name');
		}

		$this->user = $user;
		$this->client = $client;
		$this->kind = $kind;
		$this->name = $name;
		$this->scope = $scope;
		$this->tokenHash = $tokenHash;
		$this->refreshTokenHash = $refreshTokenHash;
		$this->hint = $hint;
		$this->createdAt = $createdAt;
		$this->expiresAt = $expiresAt;
		$this->refreshExpiresAt = $refreshExpiresAt;
	}

	/**
	 * @param int $validDays 1–365
	 * @throws ValidationException
	 */
	public static function personal(User $user, string $name, AccessScope $scope, #[\SensitiveParameter] string $token, int $validDays, \DateTimeImmutable $now): self
	{
		if ($validDays < 1 || $validDays > 365) {
			throw new ValidationException('Platnost tokenu musí být 1 až 365 dní.', 'validDays');
		}

		return new self($user, null, AccessTokenKind::Personal, $name, $scope, Secret::hash($token), null, substr($token, 0, 8), $now, $now->modify("+{$validDays} days"), null);
	}

	/**
	 * @throws ValidationException uživatel bez obce
	 */
	public static function oauth(User $user, OAuthClient $client, AccessScope $scope, #[\SensitiveParameter] string $accessToken, #[\SensitiveParameter] string $refreshToken, \DateTimeImmutable $now): self
	{
		return new self(
			$user,
			$client,
			AccessTokenKind::OAuth,
			$client->getName(),
			$scope,
			Secret::hash($accessToken),
			Secret::hash($refreshToken),
			null,
			$now,
			$now->modify('+' . self::OAuthAccessSeconds . ' seconds'),
			$now->modify('+' . self::OAuthRefreshDays . ' days'),
		);
	}

	/**
	 * @throws ValidationException
	 */
	public static function municipalityOf(User $user): Municipality
	{
		return $user->getMunicipality() ?? throw new ValidationException('Připojení AI je dostupné jen pro účty obce, ne pro správce systému.');
	}

	/**
	 * Přístupový token platí (odvolání, vypršení).
	 */
	public function isUsableAt(\DateTimeImmutable $now): bool
	{
		return $this->revokedAt === null && $now < $this->expiresAt;
	}

	/**
	 * Připojení trvá – přístupový token platí, nebo ho jde obnovit.
	 */
	public function isActiveAt(\DateTimeImmutable $now): bool
	{
		return $this->revokedAt === null && ($now < $this->expiresAt || ($this->refreshExpiresAt !== null && $now < $this->refreshExpiresAt));
	}

	/**
	 * Obnovení (OAuth refresh) – vydá nový pár, starý obnovovací token tím přestane platit.
	 *
	 * @throws OAuthException
	 */
	public function rotate(OAuthClient $client, #[\SensitiveParameter] string $accessToken, #[\SensitiveParameter] string $refreshToken, \DateTimeImmutable $now): void
	{
		if ($this->client !== $client) {
			throw new OAuthException('invalid_grant', 'Refresh token was issued to another client.');
		}

		if ($this->revokedAt !== null || $this->refreshExpiresAt === null || $now >= $this->refreshExpiresAt) {
			throw new OAuthException('invalid_grant', 'Refresh token is expired or revoked.');
		}

		$this->tokenHash = Secret::hash($accessToken);
		$this->refreshTokenHash = Secret::hash($refreshToken);
		$this->expiresAt = $now->modify('+' . self::OAuthAccessSeconds . ' seconds');
		$this->refreshExpiresAt = $now->modify('+' . self::OAuthRefreshDays . ' days');
	}

	public function recordUse(\DateTimeImmutable $now): void
	{
		if ($this->lastUsedAt === null || $now->getTimestamp() - $this->lastUsedAt->getTimestamp() >= self::UseRecordingSeconds) {
			$this->lastUsedAt = $now;
		}
	}

	public function revoke(\DateTimeImmutable $now): void
	{
		$this->revokedAt ??= $now;
	}

	public function allows(AccessScope $scope): bool
	{
		return $scope === AccessScope::Read || $this->scope === AccessScope::Write;
	}

	public function getMunicipality(): Municipality
	{
		return $this->municipality;
	}

	public function getUser(): User
	{
		return $this->user;
	}

	public function getClient(): ?OAuthClient
	{
		return $this->client;
	}

	public function getKind(): AccessTokenKind
	{
		return $this->kind;
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function getScope(): AccessScope
	{
		return $this->scope;
	}

	public function getHint(): ?string
	{
		return $this->hint;
	}

	public function getCreatedAt(): \DateTimeImmutable
	{
		return $this->createdAt;
	}

	/**
	 * Konec platnosti připojení (u aplikace konec obnovovacího tokenu).
	 */
	public function getValidUntil(): \DateTimeImmutable
	{
		return $this->refreshExpiresAt ?? $this->expiresAt;
	}

	public function getLastUsedAt(): ?\DateTimeImmutable
	{
		return $this->lastUsedAt;
	}

	public function getRevokedAt(): ?\DateTimeImmutable
	{
		return $this->revokedAt;
	}
}
