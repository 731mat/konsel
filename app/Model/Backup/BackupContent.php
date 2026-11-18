<?php

declare(strict_types=1);

namespace App\Model\Backup;

enum BackupContent: string
{
	case Full = 'full';
	case Database = 'db';

	public function label(): string
	{
		return match ($this) {
			self::Full => 'databáze + soubory',
			self::Database => 'jen databáze',
		};
	}

	public function description(): string
	{
		return match ($this) {
			self::Full => 'Kompletní záloha: databáze a všechny nahrané soubory (přílohy, záznamy zasedání, archiv dokumentů, loga).',
			self::Database => 'Menší a rychlejší: jen data (zasedání, usnesení, uživatelé…), bez nahraných souborů.',
		};
	}
}
