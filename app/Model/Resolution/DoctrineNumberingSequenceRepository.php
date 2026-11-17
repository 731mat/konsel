<?php

declare(strict_types=1);

namespace App\Model\Resolution;

use App\Model\People\Body;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Přidělení čísla jedním UPDATE … SET last_value = last_value + 1 (zámek řádku / zápisu) a přečtením
 * v téže transakci. Na SQLite transakce začínají BEGIN IMMEDIATE (SqliteImmediateConnection), takže
 * dvě souběžná přidělení se seřadí. První číslo řady založí INSERT; souběh hlídá unikátní index.
 */
final readonly class DoctrineNumberingSequenceRepository implements NumberingSequenceRepository
{
	public function __construct(
		private EntityManagerInterface $entityManager,
	) {}

	public function next(Body $body, string $scopeKey): int
	{
		return self::allocate($this->entityManager->getConnection(), $body->getMunicipality()->getId(), $body->getId(), $scopeKey);
	}

	public function release(Body $body, string $scopeKey, int $value): bool
	{
		return $this->entityManager->getConnection()->executeStatement(
			'UPDATE numbering_sequence SET last_value = last_value - 1 WHERE body_id = ? AND scope_key = ? AND last_value = ?',
			[$body->getId(), $scopeKey, $value],
		) === 1;
	}

	public function current(Body $body, string $scopeKey): int
	{
		$value = $this->entityManager->getConnection()->fetchOne(
			'SELECT last_value FROM numbering_sequence WHERE body_id = ? AND scope_key = ?',
			[$body->getId(), $scopeKey],
		);

		return is_numeric($value) ? (int) $value : 0;
	}

	public function set(Body $body, string $scopeKey, int $lastValue): void
	{
		$connection = $this->entityManager->getConnection();
		$updated = $connection->executeStatement(
			'UPDATE numbering_sequence SET last_value = ? WHERE body_id = ? AND scope_key = ?',
			[$lastValue, $body->getId(), $scopeKey],
		);
		if ($updated === 0) {
			$connection->insert('numbering_sequence', [
				'municipality_id' => $body->getMunicipality()->getId(),
				'body_id' => $body->getId(),
				'scope_key' => $scopeKey,
				'last_value' => $lastValue,
			]);
		}
	}

	/**
	 * Samotné přidělení nad DBAL spojením (používá i test souběhu ve dvou procesech).
	 */
	public static function allocate(Connection $connection, int $municipalityId, int $bodyId, string $scopeKey): int
	{
		$increment = static fn(): int => (int) $connection->executeStatement(
			'UPDATE numbering_sequence SET last_value = last_value + 1 WHERE body_id = ? AND scope_key = ?',
			[$bodyId, $scopeKey],
		);

		if ($increment() === 0) {
			try {
				$connection->insert('numbering_sequence', [
					'municipality_id' => $municipalityId,
					'body_id' => $bodyId,
					'scope_key' => $scopeKey,
					'last_value' => 1,
				]);

				return 1;
			} catch (UniqueConstraintViolationException) {
				$increment();
			}
		}

		$value = $connection->fetchOne('SELECT last_value FROM numbering_sequence WHERE body_id = ? AND scope_key = ?', [$bodyId, $scopeKey]);

		return is_numeric($value) ? (int) $value : throw new \LogicException('Numbering sequence row is missing.');
	}
}
