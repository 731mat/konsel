<?php

declare(strict_types=1);

namespace App\Model\Shared\ValueObject;

use App\Model\Shared\Exception\ValidationException;

/**
 * E-mailová adresa normalizovaná na malá písmena.
 */
final readonly class Email implements \Stringable
{
	public string $value;

	public function __construct(string $value)
	{
		$normalized = mb_strtolower(trim($value));

		if (mb_strlen($normalized) > 180 || filter_var($normalized, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE) === false) {
			throw new ValidationException("Neplatná e-mailová adresa „{$value}“.", 'email');
		}

		$this->value = $normalized;
	}

	public function equals(self $other): bool
	{
		return $this->value === $other->value;
	}

	public function __toString(): string
	{
		return $this->value;
	}
}
