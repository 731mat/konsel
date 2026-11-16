<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exception;

final class NotFoundException extends DomainException
{
	public function __construct(string $message = 'Hledaný záznam neexistuje.', ?\Throwable $previous = null)
	{
		parent::__construct($message, 0, $previous);
	}
}
