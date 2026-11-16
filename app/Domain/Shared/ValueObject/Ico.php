<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObject;

use App\Domain\Shared\Exception\ValidationException;

/**
 * Identifikační číslo osoby (IČO) – 8 číslic s kontrolním součtem modulo 11.
 */
final readonly class Ico implements \Stringable
{
	public string $value;

	public function __construct(string $value)
	{
		$normalized = preg_replace('~\s+~', '', $value) ?? '';

		if (preg_match('~^\d{1,8}$~', $normalized) !== 1) {
			throw new ValidationException('IČO musí obsahovat nejvýše 8 číslic.', 'ico');
		}

		$normalized = str_pad($normalized, 8, '0', STR_PAD_LEFT);

		if (!self::hasValidChecksum($normalized)) {
			throw new ValidationException('IČO nemá platný kontrolní součet.', 'ico');
		}

		$this->value = $normalized;
	}

	public static function tryFrom(?string $value): ?self
	{
		return $value === null || trim($value) === '' ? null : new self($value);
	}

	public function equals(self $other): bool
	{
		return $this->value === $other->value;
	}

	public function __toString(): string
	{
		return $this->value;
	}

	private static function hasValidChecksum(string $ico): bool
	{
		$sum = 0;
		for ($i = 0; $i < 7; $i++) {
			$sum += (int) $ico[$i] * (8 - $i);
		}

		$expected = match ($sum % 11) {
			0 => 1,
			1 => 0,
			default => 11 - $sum % 11,
		};

		return (int) $ico[7] === $expected;
	}
}
