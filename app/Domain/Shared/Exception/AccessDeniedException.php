<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exception;

final class AccessDeniedException extends DomainException
{
	public function __construct(string $message = 'K této operaci nemáte oprávnění.', ?\Throwable $previous = null)
	{
		parent::__construct($message, 0, $previous);
	}
}
