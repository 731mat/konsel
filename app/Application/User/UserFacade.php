<?php

declare(strict_types=1);

namespace App\Application\User;

use App\Application\Security\PasswordPolicy;
use App\Application\Shared\TransactionManager;
use App\Application\User\Command\CreateUser;
use App\Application\User\Command\UpdateUser;
use App\Domain\Municipality\MunicipalityRepository;
use App\Domain\Shared\Exception\InvalidStateException;
use App\Domain\Shared\Exception\ValidationException;
use App\Domain\Shared\ValueObject\Email;
use App\Domain\User\Role;
use App\Domain\User\User;
use App\Domain\User\UserRepository;
use Nette\Security\Passwords;

final readonly class UserFacade
{
	public function __construct(
		private UserRepository $users,
		private MunicipalityRepository $municipalities,
		private TransactionManager $transaction,
		private Passwords $passwords,
	) {}

	/**
	 * @throws ValidationException
	 */
	public function create(CreateUser $command): User
	{
		PasswordPolicy::validate($command->password);
		$email = new Email($command->email);

		return $this->transaction->run(function () use ($command, $email): User {
			if ($this->users->findByEmail($email) !== null) {
				throw new ValidationException("Uživatel s e-mailem {$email} už existuje.", 'email');
			}

			$municipality = $command->municipalityId === null ? null : $this->municipalities->get($command->municipalityId);
			$user = new User($municipality, $email, $command->name, $command->role, $this->passwords->hash($command->password));
			if ($command->mustChangePassword) {
				$user->changePassword($user->getPasswordHash(), mustChangePassword: true);
			}

			$this->users->add($user);

			return $user;
		});
	}

	/**
	 * @throws ValidationException|InvalidStateException
	 */
	public function update(int $userId, UpdateUser $command, int $actingUserId): void
	{
		$email = new Email($command->email);

		$this->transaction->run(function () use ($userId, $command, $email, $actingUserId): void {
			$user = $this->users->get($userId);

			$other = $this->users->findByEmail($email);
			if ($other !== null && $other !== $user) {
				throw new ValidationException("Uživatel s e-mailem {$email} už existuje.", 'email');
			}

			if ($user->getRole() !== $command->role) {
				if ($userId === $actingUserId) {
					throw new InvalidStateException('Vlastní roli si změnit nemůžete.');
				}

				$this->assertNotLastAdmin($user);
			}

			$user->rename($command->name);
			$user->changeEmail($email);
			$user->changeRole($command->role);
		});
	}

	/**
	 * @throws InvalidStateException
	 */
	public function deactivate(int $userId, int $actingUserId): void
	{
		$this->transaction->run(function () use ($userId, $actingUserId): void {
			if ($userId === $actingUserId) {
				throw new InvalidStateException('Vlastní účet deaktivovat nemůžete.');
			}

			$user = $this->users->get($userId);
			$this->assertNotLastAdmin($user);
			$user->deactivate();
		});
	}

	public function activate(int $userId): void
	{
		$this->transaction->run(fn() => $this->users->get($userId)->activate());
	}

	/**
	 * Vygeneruje dočasné heslo (zobrazí se jednou) a vynutí jeho změnu při přihlášení.
	 */
	public function resetPassword(int $userId): string
	{
		$password = PasswordPolicy::generate();
		$this->transaction->run(function () use ($userId, $password): void {
			$this->users->get($userId)->changePassword($this->passwords->hash($password), mustChangePassword: true);
		});

		return $password;
	}

	/**
	 * @throws ValidationException
	 */
	public function changeOwnPassword(int $userId, string $currentPassword, string $newPassword): void
	{
		PasswordPolicy::validate($newPassword);

		$this->transaction->run(function () use ($userId, $currentPassword, $newPassword): void {
			$user = $this->users->get($userId);
			if (!$this->passwords->verify($currentPassword, $user->getPasswordHash())) {
				throw new ValidationException('Současné heslo není správné.', 'currentPassword');
			}

			if ($this->passwords->verify($newPassword, $user->getPasswordHash())) {
				throw new ValidationException('Nové heslo musí být jiné než současné.', 'password');
			}

			$user->changePassword($this->passwords->hash($newPassword));
		});
	}

	public function get(int $userId): User
	{
		return $this->users->get($userId);
	}

	/**
	 * @return list<User>
	 */
	public function findByMunicipality(int $municipalityId): array
	{
		return $this->users->findByMunicipality($this->municipalities->get($municipalityId));
	}

	private function assertNotLastAdmin(User $user): void
	{
		$municipality = $user->getMunicipality();
		if ($user->getRole() === Role::Admin && $user->isActive() && $municipality !== null && $this->users->countActiveAdmins($municipality) <= 1) {
			throw new InvalidStateException('Obec musí mít alespoň jednoho aktivního správce.');
		}
	}
}
