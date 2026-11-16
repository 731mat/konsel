<?php

declare(strict_types=1);

namespace App\Application\Municipality;

use App\Application\Municipality\Command\CreateMunicipality;
use App\Application\Shared\TransactionManager;
use App\Domain\Municipality\Municipality;
use App\Domain\Municipality\MunicipalityRepository;
use App\Domain\Shared\Exception\ValidationException;
use App\Domain\Shared\ValueObject\Ico;
use Nette\Utils\Strings;

final readonly class MunicipalityFacade
{
	public function __construct(
		private MunicipalityRepository $municipalities,
		private TransactionManager $transaction,
	) {}

	/**
	 * @throws ValidationException
	 */
	public function create(CreateMunicipality $command): Municipality
	{
		return $this->transaction->run(function () use ($command): Municipality {
			$slug = $command->slug ?? Strings::webalize($command->name);
			$ico = Ico::tryFrom($command->ico);

			if ($this->municipalities->findBySlug($slug) !== null) {
				throw new ValidationException("Obec se zkratkou „{$slug}“ už existuje.", 'slug');
			}

			if ($ico !== null && $this->municipalities->findByIco($ico) !== null) {
				throw new ValidationException("Obec s IČO {$ico} už existuje.", 'ico');
			}

			$municipality = new Municipality($command->name, $slug, $ico);
			$this->municipalities->add($municipality);

			return $municipality;
		});
	}

	/**
	 * @return list<Municipality>
	 */
	public function findAll(): array
	{
		return $this->municipalities->findAll();
	}
}
