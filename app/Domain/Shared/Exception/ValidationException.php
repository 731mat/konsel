<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exception;

/**
 * Porušení doménového pravidla vstupu. Volitelně nese název pole (pro chybu ve formuláři).
 */
final class ValidationException extends DomainException
{
	public function __construct(
		string $message,
		public readonly ?string $field = null,
		?\Throwable $previous = null,
	) {
		parent::__construct($message, 0, $previous);
	}
}
