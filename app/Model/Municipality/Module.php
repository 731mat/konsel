<?php

declare(strict_types=1);

namespace App\Model\Municipality;

/**
 * Volitelné části aplikace, které si obec může vypnout (např. při postupném zavádění).
 * Vypnutý modul zmizí z menu i obrazovek, jeho data zůstanou a po zapnutí se vrátí.
 */
enum Module: string
{
	case ResolutionTasks = 'resolution_tasks';
	case Plans = 'plans';

	public function label(): string
	{
		return match ($this) {
			self::ResolutionTasks => 'Úkoly z usnesení',
			self::Plans => 'Plány',
		};
	}

	public function description(): string
	{
		return match ($this) {
			self::ResolutionTasks => 'Úkoly s odpovědnou osobou a termínem u přijatých usnesení, přehled úkolů po termínu.',
			self::Plans => 'Dlouhodobé plány obce (např. nová školka) v jednom vlákně přes více zasedání, propojení s body programu, a stránka pro občany s podněty a zveřejněnými plány.',
		};
	}

	public function icon(): string
	{
		return match ($this) {
			self::ResolutionTasks => 'check2-square',
			self::Plans => 'kanban',
		};
	}
}
