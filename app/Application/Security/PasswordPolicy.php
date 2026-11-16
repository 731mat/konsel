<?php

declare(strict_types=1);

namespace App\Application\Security;

use App\Domain\Shared\Exception\ValidationException;

final class PasswordPolicy
{
	public const int MinLength = 10;

	/**
	 * @throws ValidationException
	 */
	public static function validate(string $password): void
	{
		if (mb_strlen($password) < self::MinLength) {
			throw new ValidationException(sprintf('Heslo musí mít alespoň %d znaků.', self::MinLength), 'password');
		}

		if (mb_strlen($password) > 200) {
			throw new ValidationException('Heslo je příliš dlouhé.', 'password');
		}
	}

	public static function generate(int $length = 16): string
	{
		$alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$password = '';
		for ($i = 0; $i < $length; $i++) {
			$password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
		}

		return $password;
	}
}
