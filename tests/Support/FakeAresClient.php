<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Model\Ares\AresClient;
use App\Model\Ares\AresException;
use App\Model\Ares\AresSubject;
use App\Model\Shared\ValueObject\Ico;

/**
 * ARES pro testy – nikdy nevolá síť. Zná obec Neplachovice a jednu firmu, umí simulovat výpadek.
 */
final class FakeAresClient implements AresClient
{
	public bool $unavailable = false;

	/**
	 * @var list<AresSubject>
	 */
	public array $subjects;

	public function __construct()
	{
		$this->subjects = [
			new AresSubject('00300535', 'Obec Neplachovice', 'Na Návsi 16', 'Neplachovice', '747 74', 'Na Návsi 16, 74774 Neplachovice', 'CZ00300535'),
			new AresSubject('25596641', 'Silnice Morava s.r.o.', 'Průmyslová 5', 'Opava', '746 01', 'Průmyslová 5, 74601 Opava'),
		];
	}

	public function findByIco(Ico $ico): ?AresSubject
	{
		if ($this->unavailable) {
			throw new AresException('ARES je mimo provoz.');
		}

		foreach ($this->subjects as $subject) {
			if ($subject->ico === $ico->value) {
				return $subject;
			}
		}

		return null;
	}
}
