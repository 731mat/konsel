<?php

declare(strict_types=1);

namespace App\Model\User;

/**
 * Role uživatele úřadu. Oprávnění k nim definuje Authorizator (T06).
 */
enum Role: string
{
	/**
	 * Správce systému napříč obcemi (nepatří žádné obci).
	 */
	case SuperAdmin = 'superadmin';

	/**
	 * Správce obce: nastavení, uživatelé, vše editovat.
	 */
	case Admin = 'admin';

	/**
	 * Úředník / zapisovatel: zasedání, program, průběh, usnesení.
	 */
	case Editor = 'editor';

	/**
	 * Jen náhled.
	 */
	case Reader = 'reader';

	public function label(): string
	{
		return match ($this) {
			self::SuperAdmin => 'Správce systému',
			self::Admin => 'Správce obce',
			self::Editor => 'Editor',
			self::Reader => 'Čtenář',
		};
	}

	public function belongsToMunicipality(): bool
	{
		return $this !== self::SuperAdmin;
	}
}
