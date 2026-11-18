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
	case AiConnections = 'ai_connections';

	public function label(): string
	{
		return match ($this) {
			self::ResolutionTasks => 'Úkoly z usnesení',
			self::Plans => 'Plány',
			self::AiConnections => 'Připojení AI asistentů (MCP)',
		};
	}

	public function description(): string
	{
		return match ($this) {
			self::ResolutionTasks => 'Úkoly s odpovědnou osobou a termínem u přijatých usnesení, přehled úkolů po termínu.',
			self::Plans => 'Dlouhodobé plány obce (např. nová školka) v jednom vlákně přes více zasedání, propojení s body programu, a stránka pro občany s podněty a zveřejněnými plány.',
			self::AiConnections => 'Uživatelé si mohou ke svému účtu připojit AI asistenta (Claude, ChatGPT…) přes server MCP. Asistent vidí a smí jen to, co uživatel, a změny se zapisují do historie.',
		};
	}

	public function icon(): string
	{
		return match ($this) {
			self::ResolutionTasks => 'check2-square',
			self::Plans => 'kanban',
			self::AiConnections => 'robot',
		};
	}
}
