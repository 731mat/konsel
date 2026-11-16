<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\TransactionManager;
use App\Model\Shared\ValueObject\Email;
use App\Model\User\LoginThrottle;
use App\Model\User\User;
use App\Model\User\UserRepository;
use Nette\Http\IRequest;
use Nette\Security\AuthenticationException;
use Nette\Security\IdentityHandler;
use Nette\Security\IIdentity;
use Nette\Security\Passwords;
use Nette\Security\SimpleIdentity;
use Psr\Clock\ClockInterface;

/**
 * Přihlášení uživatele úřadu e-mailem a heslem.
 * Identita se při každém requestu obnoví z DB (IdentityHandler) – změna role nebo deaktivace platí okamžitě.
 */
final readonly class Authenticator implements \Nette\Security\Authenticator, IdentityHandler
{
	/**
	 * Hash pro vyrovnání času odpovědi u neexistujícího účtu (ochrana proti zjišťování e-mailů).
	 */
	private const string DummyHash = '$2y$12$8/wh1o1SBL8eD9Oe7Jm6oeE0WgDPYehbkjTOH1Sx50PejD8bPxiKa';

	public function __construct(
		private UserRepository $users,
		private LoginThrottle $throttle,
		private TransactionManager $transaction,
		private Passwords $passwords,
		private IRequest $httpRequest,
		private ClockInterface $clock,
	) {}

	public function authenticate(string $username, string $password): IIdentity
	{
		$ip = $this->httpRequest->getRemoteAddress() ?? 'cli';
		$emailString = mb_strtolower(trim($username));

		if ($this->throttle->isBlocked($emailString, $ip)) {
			throw new AuthenticationException('Příliš mnoho neúspěšných pokusů o přihlášení. Zkuste to znovu za 15 minut.', self::NotApproved);
		}

		try {
			$user = $this->users->findByEmail(new Email($emailString));
		} catch (ValidationException) {
			$user = null;
		}

		if ($user === null) {
			$this->passwords->verify($password, self::DummyHash);
			$this->recordAttempt($emailString, $ip, false);
			throw new AuthenticationException('Nesprávný e-mail nebo heslo.', self::IdentityNotFound);
		}

		if (!$this->passwords->verify($password, $user->getPasswordHash())) {
			$this->recordAttempt($emailString, $ip, false);
			throw new AuthenticationException('Nesprávný e-mail nebo heslo.', self::InvalidCredential);
		}

		if (!$user->isActive()) {
			$this->recordAttempt($emailString, $ip, false);
			throw new AuthenticationException('Účet je deaktivovaný. Obraťte se na správce obce.', self::NotApproved);
		}

		$this->transaction->run(function () use ($user, $password, $emailString, $ip): void {
			if ($this->passwords->needsRehash($user->getPasswordHash())) {
				$user->rehashPassword($this->passwords->hash($password));
			}

			$user->recordLogin($this->clock->now());
			$this->throttle->record($emailString, $ip, true);
		});

		return $this->createIdentity($user);
	}

	public function sleepIdentity(IIdentity $identity): IIdentity
	{
		return new SimpleIdentity($identity->getId());
	}

	public function wakeupIdentity(IIdentity $identity): ?IIdentity
	{
		$id = $identity->getId();
		$user = is_int($id) ? $this->users->find($id) : null;

		return $user !== null && $user->isActive() ? $this->createIdentity($user) : null;
	}

	private function createIdentity(User $user): SimpleIdentity
	{
		return new SimpleIdentity($user->getId(), [$user->getRole()->value], [
			'name' => $user->getName(),
			'email' => $user->getEmail()->value,
			'municipalityId' => $user->getMunicipality()?->getId(),
			'mustChangePassword' => $user->mustChangePassword(),
		]);
	}

	private function recordAttempt(string $email, string $ip, bool $successful): void
	{
		$this->transaction->run(fn() => $this->throttle->record($email, $ip, $successful));
	}
}
