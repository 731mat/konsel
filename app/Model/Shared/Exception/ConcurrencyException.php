<?php

declare(strict_types=1);

namespace App\Model\Shared\Exception;

/**
 * Záznam mezitím uložil někdo jiný (optimistický zámek #[Version]). Nedědí z DomainException:
 * po selhání flush je EntityManager zavřený, request už nesmí číst z DB – presenter jen přesměruje.
 */
final class ConcurrencyException extends \RuntimeException
{
	public function __construct(?\Throwable $previous = null)
	{
		parent::__construct('Mezitím změnil stejné údaje někdo jiný. Stránka se načetla znovu, zkontrolujte prosím stav a akci zopakujte.', 0, $previous);
	}
}
