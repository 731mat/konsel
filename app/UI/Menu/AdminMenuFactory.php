<?php

declare(strict_types=1);

namespace App\UI\Menu;

interface AdminMenuFactory
{
	public function create(): AdminMenu;
}
