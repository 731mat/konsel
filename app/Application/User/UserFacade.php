<?php

declare(strict_types=1);

namespace App\Application\User;

use App\Application\Security\PasswordPolicy;
use App\Application\Shared\TransactionManager;
use App\Application\User\Command\CreateUser;
use App\Domain\Municipality\MunicipalityRepository;
use App\Domain\Shared\Exception\ValidationException;
use App\Domain\Shared\ValueObject\Email;
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
}
