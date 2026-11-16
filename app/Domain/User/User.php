<?php

declare(strict_types=1);

namespace App\Domain\User;

use App\Domain\Municipality\Municipality;
use App\Domain\Shared\Exception\ValidationException;
use App\Domain\Shared\IntegerId;
use App\Domain\Shared\Timestampable;
use App\Domain\Shared\TimestampableTrait;
use App\Domain\Shared\ValueObject\Email;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uživatel úřadu přihlašující se e-mailem a heslem. Zastupitelé uživateli nejsou (mají přístupové tokeny).
 */
#[ORM\Entity]
#[ORM\Table(name: 'app_user')]
final class User implements Timestampable
{
	use IntegerId;
	use TimestampableTrait;

	#[ORM\ManyToOne(targetEntity: Municipality::class)]
	#[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
	private ?Municipality $municipality;

	#[ORM\Column(length: 180, unique: true)]
	private string $email;

	#[ORM\Column(length: 255)]
	private string $passwordHash;

	#[ORM\Column(length: 150)]
	private string $name;

	#[ORM\Column(length: 20, enumType: Role::class)]
	private Role $role;

	#[ORM\Column]
	private bool $active = true;

	#[ORM\Column]
	private bool $mustChangePassword = false;

	#[ORM\Column(nullable: true)]
	private ?\DateTimeImmutable $lastLoginAt = null;

	public function __construct(?Municipality $municipality, Email $email, string $name, Role $role, string $passwordHash)
	{
		self::assertRoleMatchesMunicipality($role, $municipality);
		$this->municipality = $municipality;
		$this->email = $email->value;
		$this->name = self::validateName($name);
		$this->role = $role;
		$this->passwordHash = $passwordHash;
	}

	public function changePassword(string $passwordHash, bool $mustChangePassword = false): void
	{
		$this->passwordHash = $passwordHash;
		$this->mustChangePassword = $mustChangePassword;
	}

	/**
	 * Přepočet hashe při přihlášení (silnější algoritmus) – nemění příznak vynucené změny.
	 */
	public function rehashPassword(string $passwordHash): void
	{
		$this->passwordHash = $passwordHash;
	}

	public function recordLogin(\DateTimeImmutable $now): void
	{
		$this->lastLoginAt = $now;
	}

	public function changeRole(Role $role): void
	{
		self::assertRoleMatchesMunicipality($role, $this->municipality);
		$this->role = $role;
	}

	public function rename(string $name): void
	{
		$this->name = self::validateName($name);
	}

	public function changeEmail(Email $email): void
	{
		$this->email = $email->value;
	}

	public function activate(): void
	{
		$this->active = true;
	}

	public function deactivate(): void
	{
		$this->active = false;
	}

	public function getMunicipality(): ?Municipality
	{
		return $this->municipality;
	}

	public function getEmail(): Email
	{
		return new Email($this->email);
	}

	public function getPasswordHash(): string
	{
		return $this->passwordHash;
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function getRole(): Role
	{
		return $this->role;
	}

	public function isActive(): bool
	{
		return $this->active;
	}

	public function mustChangePassword(): bool
	{
		return $this->mustChangePassword;
	}

	public function getLastLoginAt(): ?\DateTimeImmutable
	{
		return $this->lastLoginAt;
	}

	private static function assertRoleMatchesMunicipality(Role $role, ?Municipality $municipality): void
	{
		if ($role->belongsToMunicipality() && $municipality === null) {
			throw new ValidationException('Uživatel s touto rolí musí patřit k obci.', 'role');
		}

		if (!$role->belongsToMunicipality() && $municipality !== null) {
			throw new ValidationException('Správce systému nepatří k žádné obci.', 'role');
		}
	}

	private static function validateName(string $name): string
	{
		$name = trim($name);
		if ($name === '' || mb_strlen($name) > 150) {
			throw new ValidationException('Jméno musí mít 1 až 150 znaků.', 'name');
		}

		return $name;
	}
}
