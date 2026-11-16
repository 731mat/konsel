<?php

declare(strict_types=1);

namespace App\Model\Shared\Exception;

final class InvalidStateException extends DomainException
{
	public function __construct(string $message = 'Operaci nelze v aktuálním stavu provést.', ?\Throwable $previous = null)
	{
		parent::__construct($message, 0, $previous);
	}
}
