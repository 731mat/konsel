<?php

declare(strict_types=1);

namespace App\AdminModule\AiConnection;

final class TokenFormData
{
	public string $name;

	public string $scope;

	public int $validDays;
}
