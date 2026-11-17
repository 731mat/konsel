<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Doctrine\Migration\SchemaMigration;
use Doctrine\DBAL\Schema\Schema;

/**
 * Komentáře se od teď ukládají jako formátovaný text (HTML). Dosavadní prostý text se převede:
 * escapování &, <, >, prázdný řádek → nový odstavec, nový řádek → <br>.
 */
final class Version20261117090000 extends SchemaMigration
{
	public function getDescription(): string
	{
		return 'Komentáře: prostý text → HTML (comment.body)';
	}

	public function up(Schema $schema): void
	{
		$body = $this->replaceChain('body', [
			['&', '&amp;'],
			['<', '&lt;'],
			['>', '&gt;'],
			["\n\n", '</p><p>'],
			["\n", '<br>'],
		]);

		$this->addSql(sprintf(
			"UPDATE comment SET body = %s WHERE body <> '' AND body NOT LIKE '<p>%%'",
			$this->platform->getConcatExpression("'<p>'", $body, "'</p>'"),
		));
	}

	public function down(Schema $schema): void
	{
		// jen jednoduché značky z převodu; formátování přidané editorem se ztratí
		$body = $this->replaceChain('body', [
			['</p><p>', "\n\n"],
			['<br>', "\n"],
			['<p>', ''],
			['</p>', ''],
			['&lt;', '<'],
			['&gt;', '>'],
			['&amp;', '&'],
		]);

		$this->addSql("UPDATE comment SET body = {$body}");
	}

	/**
	 * @param list<array{string, string}> $replacements
	 */
	private function replaceChain(string $column, array $replacements): string
	{
		$expression = $column;
		foreach ($replacements as [$search, $replace]) {
			$expression = sprintf('REPLACE(%s, %s, %s)', $expression, $this->connection->quote($search), $this->connection->quote($replace));
		}

		return $expression;
	}
}
