<?php

declare(strict_types=1);

namespace App\Model\Ares;

use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\ValueObject\Ico;

/**
 * Vyhledání subjektu v ARES podle IČO – pro údaje obce v nastavení a pro text bodu programu.
 */
final readonly class AresFacade
{
	public function __construct(
		private AresClient $client,
	) {}

	/**
	 * @throws ValidationException neplatné IČO, subjekt neexistuje nebo ARES neodpovídá
	 */
	public function lookup(string $ico): AresSubject
	{
		$valid = new Ico($ico);
		try {
			$subject = $this->client->findByIco($valid);
		} catch (AresException) {
			throw new ValidationException('ARES teď neodpovídá. Zkuste to za chvíli, nebo údaje vyplňte ručně.', 'ico');
		}

		return $subject ?? throw new ValidationException("V ARES není žádný subjekt s IČO {$valid->value}.", 'ico');
	}
}
