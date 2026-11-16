<?php

declare(strict_types=1);

namespace App\Model\Ai;

use App\Model\Municipality\Municipality;

interface AiSettingsRepository
{
	public function findByMunicipality(Municipality $municipality): ?AiSettings;

	public function add(AiSettings $settings): void;
}
