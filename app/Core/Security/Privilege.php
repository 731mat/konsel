<?php

declare(strict_types=1);

namespace App\Core\Security;

enum Privilege: string
{
	case View = 'view';
	case Edit = 'edit';
	case Delete = 'delete';

	/**
	 * Správa nad rámec editace (např. znovuotevření schváleného zasedání, přečíslování).
	 */
	case Manage = 'manage';
}
