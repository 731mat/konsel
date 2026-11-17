<?php

declare(strict_types=1);

namespace App\Model\People;

use App\Model\Shared\Exception\ValidationException;

/**
 * Maska čísla usnesení, např. „{n}/{yyyy}“, „ZO/{meeting}/{n}/{yy}“, „R-{n:3}/{yyyy}“.
 *
 * Proměnné: {n} pořadové číslo, {n:3} doplněné nulami na 3 místa, {yyyy} rok, {yy} rok dvojmístně,
 * {meeting} číslo zasedání, {item} číslo bodu programu, {body} zkratka orgánu (ZO, RO, FV…), {sub} číslo podbodu.
 * Část v hranatých závorkách se vypíše, jen když má všechny údaje: „{n}/{meeting}[/{sub}]“ → „26/2/3“, u bodu bez podbodů „22/2“.
 * Obsahuje-li maska {sub}, sdílejí všechna usnesení jednoho bodu (i jeho podbodů) stejné pořadové číslo {n}.
 */
final readonly class NumberingMask implements \Stringable
{
	public const int MaxLength = 100;

	private const string TokenPattern = '~\{([a-z]+)(?::(\d+))?\}~';

	private const array Tokens = ['n', 'yyyy', 'yy', 'meeting', 'item', 'body', 'sub'];

	private const string OptionalPattern = '~\[([^\[\]]*)\]~';

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
				throw new ValidationException("Neznámá proměnná {$token}. Použít lze {n}, {n:3}, {yyyy}, {yy}, {meeting}, {item}, {sub} a {body}.", 'numberingMask');
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

		$withoutOptional = (string) preg_replace(self::OptionalPattern, '', $mask);
		if (str_contains($withoutOptional, '[') || str_contains($withoutOptional, ']')) {
			throw new ValidationException('Hranaté závorky v masce musí být spárované a nesmí se vnořovat, např. „{n}/{meeting}[/{sub}]“.', 'numberingMask');
		}

		if ($sequence !== 1) {
			throw new ValidationException('Maska číslování musí obsahovat pořadové číslo {n} právě jednou.', 'numberingMask');
		}

		$this->value = $mask;
	}

	/**
	 * @param ?int $subNumber číslo podbodu (null = usnesení k hlavnímu bodu)
	 */
	public function format(int $sequence, \DateTimeInterface $date, int $meetingNumber, int $itemNumber, string $bodyCode, ?int $subNumber = null): string
	{
		// volitelná část jen s podbodem
		$mask = (string) preg_replace_callback(
			self::OptionalPattern,
			static fn(array $m): string => str_contains($m[1], '{sub}') && $subNumber === null ? '' : $m[1],
			$this->value,
		);

		return (string) preg_replace_callback(self::TokenPattern, static fn(array $m): string => match ($m[1]) {
			'n' => isset($m[2]) ? str_pad((string) $sequence, (int) $m[2], '0', STR_PAD_LEFT) : (string) $sequence,
			'yyyy' => $date->format('Y'),
			'yy' => $date->format('y'),
			'meeting' => (string) $meetingNumber,
			'item' => (string) $itemNumber,
			'body' => $bodyCode,
			'sub' => $subNumber === null ? '' : (string) $subNumber,
			default => $m[0],
		}, $mask);
	}

	/**
	 * Všechna usnesení bodu sdílejí pořadové číslo a liší se číslem podbodu.
	 */
	public function usesSub(): bool
	{
		return str_contains($this->value, '{sub}');
	}

	/**
	 * Ukázka pro náhled v nastavení (7. usnesení, 3. zasedání, bod 4, prosinec 2026; s podbodem 2).
	 */
	public function example(string $bodyCode = 'ZO'): string
	{
		$date = new \DateTimeImmutable('2026-12-09');

		return $this->usesSub()
			? $this->format(7, $date, 3, 4, $bodyCode, 2) . ' (bez podbodů ' . $this->format(7, $date, 3, 4, $bodyCode) . ')'
			: $this->format(7, $date, 3, 4, $bodyCode);
	}

	public function __toString(): string
	{
		return $this->value;
	}
}
