<?php

declare(strict_types=1);

namespace App\Model\People;

use App\Model\Shared\Exception\ValidationException;

/**
 * Maska čísla usnesení, např. „{n}/{yyyy}“, „ZO/{meeting}/{n}/{yy}“, „R-{n:3}/{yyyy}“.
 *
 * Proměnné: {n} pořadové číslo, {n:3} doplněné nulami na 3 místa, {yyyy} rok, {yy} rok dvojmístně,
 * {meeting} číslo zasedání, {item} číslo bodu programu, {body} zkratka orgánu (ZO, RO, FV…).
 */
final readonly class NumberingMask implements \Stringable
{
	public const int MaxLength = 100;

	private const string TokenPattern = '~\{([a-z]+)(?::(\d+))?\}~';

	private const array Tokens = ['n', 'yyyy', 'yy', 'meeting', 'item', 'body'];

	public string $value;

	/**
	 * @throws ValidationException
	 */
	public function __construct(string $mask)
	{
		$mask = trim($mask);
		if ($mask === '' || mb_strlen($mask) > self::MaxLength) {
			throw new ValidationException('Maska číslování musí mít 1 až ' . self::MaxLength . ' znaků.', 'numberingMask');
		}

		$sequence = 0;
		preg_match_all(self::TokenPattern, $mask, $matches, PREG_SET_ORDER);
		foreach ($matches as $match) {
			[$token, $name] = $match;
			$width = $match[2] ?? null;
			if (!in_array($name, self::Tokens, true)) {
				throw new ValidationException("Neznámá proměnná {$token}. Použít lze {n}, {n:3}, {yyyy}, {yy}, {meeting}, {item} a {body}.", 'numberingMask');
			}

			if ($width !== null && ($name !== 'n' || (int) $width < 1 || (int) $width > 9)) {
				throw new ValidationException("Doplnění nulami jde jen u pořadového čísla, a to na 1 až 9 míst ({n:3}), ne {$token}.", 'numberingMask');
			}

			$sequence += $name === 'n' ? 1 : 0;
		}

		$rest = (string) preg_replace(self::TokenPattern, '', $mask);
		if (str_contains($rest, '{') || str_contains($rest, '}')) {
			throw new ValidationException('Maska obsahuje neuzavřenou nebo neplatnou proměnnou ve složených závorkách (proměnné se píší malými písmeny).', 'numberingMask');
		}

		if ($sequence !== 1) {
			throw new ValidationException('Maska číslování musí obsahovat pořadové číslo {n} právě jednou.', 'numberingMask');
		}

		$this->value = $mask;
	}

	public function format(int $sequence, \DateTimeInterface $date, int $meetingNumber, int $itemNumber, string $bodyCode): string
	{
		return (string) preg_replace_callback(self::TokenPattern, static fn(array $m): string => match ($m[1]) {
			'n' => isset($m[2]) ? str_pad((string) $sequence, (int) $m[2], '0', STR_PAD_LEFT) : (string) $sequence,
			'yyyy' => $date->format('Y'),
			'yy' => $date->format('y'),
			'meeting' => (string) $meetingNumber,
			'item' => (string) $itemNumber,
			'body' => $bodyCode,
			default => $m[0],
		}, $this->value);
	}

	/**
	 * Ukázka pro náhled v nastavení (7. usnesení, 3. zasedání, bod 4, prosinec 2026).
	 */
	public function example(string $bodyCode = 'ZO'): string
	{
		return $this->format(7, new \DateTimeImmutable('2026-12-09'), 3, 4, $bodyCode);
	}

	public function __toString(): string
	{
		return $this->value;
	}
}
