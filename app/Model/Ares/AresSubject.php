<?php

declare(strict_types=1);

namespace App\Model\Ares;

/**
 * Ekonomický subjekt z ARES (firma, obec, spolek) – údaje pro hlavičku obce nebo text bodu programu.
 */
final readonly class AresSubject
{
	public function __construct(
		public string $ico,
		public string $name,
		public ?string $street,
		public ?string $city,
		public ?string $postalCode,
		public string $address,
		public ?string $dic = null,
	) {}

	/**
	 * Jak se subjekt uvádí v textu: „Silnice Morava s.r.o., se sídlem Hlavní 1, 747 74 Neplachovice, IČO 12345678“.
	 */
	public function toSentence(): string
	{
		return "{$this->name}, se sídlem {$this->address}, IČO {$this->ico}";
	}

	/**
	 * @return array{ico: string, name: string, street: ?string, city: ?string, postalCode: ?string, address: string, dic: ?string, sentence: string}
	 */
	public function toArray(): array
	{
		return [
			'ico' => $this->ico,
			'name' => $this->name,
			'street' => $this->street,
			'city' => $this->city,
			'postalCode' => $this->postalCode,
			'address' => $this->address,
			'dic' => $this->dic,
			'sentence' => $this->toSentence(),
		];
	}
}
