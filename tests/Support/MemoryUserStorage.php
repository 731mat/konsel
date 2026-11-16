<?php

declare(strict_types=1);

namespace Tests\Support;

use Nette\Security\IIdentity;
use Nette\Security\UserStorage;

/**
 * Přihlášení uživatele v testech bez session.
 */
final class MemoryUserStorage implements UserStorage
{
	private bool $authenticated = false;

	private ?IIdentity $identity = null;

	public function saveAuthentication(IIdentity $identity): void
	{
		$this->authenticated = true;
		$this->identity = $identity;
	}

	public function clearAuthentication(bool $clearIdentity): void
	{
		$this->authenticated = false;
		if ($clearIdentity) {
			$this->identity = null;
		}
	}

	public function getState(): array
	{
		return [$this->authenticated, $this->identity, null];
	}

	public function setExpiration(?string $expire, bool $clearIdentity): void {}
}
