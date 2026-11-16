<?php

declare(strict_types=1);

namespace App\Presentation\Accessory\Menu;

interface AdminMenuFactory
{
	public function create(): AdminMenu;
}
