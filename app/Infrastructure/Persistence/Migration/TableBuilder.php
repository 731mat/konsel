<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;

/**
 * Platformově nezávislá definice tabulky pro migrace (nad nedeprekovaným DBAL editor API).
 * Názvy indexů a cizích klíčů generuje stejně jako Doctrine ORM, takže schema diff sedí.
 */
final class TableBuilder
{
	private const int MaxIdentifierLength = 63;

	/**
	 * @var list<Column>
	 */
	private array $columns = [];

	/**
	 * @var list<Index>
	 */
	private array $indexes = [];

	/**
	 * @var list<ForeignKeyConstraint>
	 */
	private array $foreignKeys = [];

	private ?PrimaryKeyConstraint $primaryKey = null;

	/**
	 * @param non-empty-string $name
	 */
	public function __construct(
		private readonly string $name,
	) {}

	/**
	 * @param non-empty-string $column
	 */
	public function id(string $column = 'id'): self
	{
		$this->columns[] = Column::editor()
			->setUnquotedName($column)
			->setTypeName(Types::INTEGER)
			->setAutoincrement(true)
			->create();
		$this->primaryKey = PrimaryKeyConstraint::editor()
			->setUnquotedColumnNames($column)
			->create();

		return $this;
	}

	/**
	 * @param non-empty-string $column
	 */
	public function integer(string $column, bool $nullable = false, ?int $default = null): self
	{
		return $this->column($column, Types::INTEGER, nullable: $nullable, default: $default);
	}

	/**
	 * @param non-empty-string $column
	 */
	public function string(string $column, int $length = 255, bool $nullable = false, ?string $default = null): self
	{
		return $this->column($column, Types::STRING, length: $length, nullable: $nullable, default: $default);
	}

	/**
	 * @param non-empty-string $column
	 */
	public function text(string $column, bool $nullable = false): self
	{
		return $this->column($column, Types::TEXT, nullable: $nullable);
	}

	/**
	 * @param non-empty-string $column
	 */
	public function boolean(string $column, bool $nullable = false, ?bool $default = null): self
	{
		return $this->column($column, Types::BOOLEAN, nullable: $nullable, default: $default);
	}

	/**
	 * @param non-empty-string $column
	 */
	public function datetime(string $column, bool $nullable = false): self
	{
		return $this->column($column, Types::DATETIME_IMMUTABLE, nullable: $nullable);
	}

	/**
	 * @param non-empty-string $column
	 */
	public function date(string $column, bool $nullable = false): self
	{
		return $this->column($column, Types::DATE_IMMUTABLE, nullable: $nullable);
	}

	/**
	 * @param non-empty-string $column
	 */
	public function json(string $column, bool $nullable = false): self
	{
		return $this->column($column, Types::JSON, nullable: $nullable);
	}

	/**
	 * Sloupce created_at a updated_at (TimestampableTrait).
	 */
	public function timestamps(): self
	{
		return $this->datetime('created_at')->datetime('updated_at');
	}

	/**
	 * Celočíselný sloupec s cizím klíčem a indexem (jako ManyToOne v Doctrine ORM).
	 *
	 * @param non-empty-string $column
	 * @param non-empty-string $referencedTable
	 * @param non-empty-string $referencedColumn
	 */
	public function foreignKey(
		string $column,
		string $referencedTable,
		bool $nullable = false,
		ReferentialAction $onDelete = ReferentialAction::NO_ACTION,
		string $referencedColumn = 'id',
	): self {
		$this->integer($column, nullable: $nullable);

		$editor = ForeignKeyConstraint::editor()
			->setUnquotedName($this->identifier('fk', [$column]))
			->setUnquotedReferencingColumnNames($column)
			->setUnquotedReferencedTableName($referencedTable)
			->setUnquotedReferencedColumnNames($referencedColumn);

		if ($onDelete !== ReferentialAction::NO_ACTION) {
			$editor->setOnDeleteAction($onDelete);
		}

		$this->foreignKeys[] = $editor->create();

		return $this->index($column);
	}

	/**
	 * @param non-empty-string $column
	 * @param non-empty-string ...$columns
	 */
	public function index(string $column, string ...$columns): self
	{
		return $this->addIndex(IndexType::REGULAR, 'idx', [$column, ...array_values($columns)]);
	}

	/**
	 * @param non-empty-string $column
	 * @param non-empty-string ...$columns
	 */
	public function unique(string $column, string ...$columns): self
	{
		return $this->addIndex(IndexType::UNIQUE, 'uniq', [$column, ...array_values($columns)]);
	}

	/**
	 * Index s explicitním názvem (odpovídá #[ORM\Index(name: …)]).
	 *
	 * @param non-empty-string $name
	 * @param non-empty-string $column
	 * @param non-empty-string ...$columns
	 */
	public function namedIndex(string $name, string $column, string ...$columns): self
	{
		$this->indexes[] = Index::editor()
			->setUnquotedName($name)
			->setType(IndexType::REGULAR)
			->setUnquotedColumnNames($column, ...array_values($columns))
			->create();

		return $this;
	}

	public function build(): Table
	{
		$editor = Table::editor()->setUnquotedName($this->name);

		if ($this->columns === []) {
			throw new \LogicException("Table {$this->name} has no columns.");
		}

		$editor->setColumns(...$this->columns);
		$editor->setIndexes(...$this->indexes);
		$editor->setForeignKeyConstraints(...$this->foreignKeys);
		$editor->setPrimaryKeyConstraint($this->primaryKey);

		return $editor->create();
	}

	/**
	 * @param non-empty-string $name
	 */
	private function column(
		string $name,
		string $type,
		?int $length = null,
		bool $nullable = false,
		mixed $default = null,
	): self {
		$editor = Column::editor()
			->setUnquotedName($name)
			->setTypeName($type)
			->setNotNull(!$nullable)
			->setDefaultValue($default);

		if ($length !== null) {
			$editor->setLength($length);
		}

		$this->columns[] = $editor->create();

		return $this;
	}

	/**
	 * @param non-empty-list<non-empty-string> $columns
	 */
	private function addIndex(IndexType $type, string $prefix, array $columns): self
	{
		$this->indexes[] = Index::editor()
			->setUnquotedName($this->identifier($prefix, $columns))
			->setType($type)
			->setUnquotedColumnNames(...$columns)
			->create();

		return $this;
	}

	/**
	 * Stejný algoritmus jako Doctrine\DBAL\Schema\AbstractAsset::_generateIdentifierName().
	 *
	 * @param list<non-empty-string> $columns
	 * @return non-empty-string
	 */
	private function identifier(string $prefix, array $columns): string
	{
		$hash = implode('', array_map(
			static fn(string $part): string => dechex(crc32($part)),
			[$this->name, ...$columns],
		));

		return strtoupper(substr($prefix . '_' . $hash, 0, self::MaxIdentifierLength));
	}
}
