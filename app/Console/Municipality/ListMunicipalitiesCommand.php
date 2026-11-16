<?php

declare(strict_types=1);

namespace App\Console\Municipality;

use App\Application\Municipality\MunicipalityFacade;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'municipality:list', description: 'Vypíše založené obce')]
final class ListMunicipalitiesCommand extends Command
{
	public function __construct(
		private readonly MunicipalityFacade $facade,
	) {
		parent::__construct();
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$rows = [];
		foreach ($this->facade->findAll() as $municipality) {
			$rows[] = [$municipality->getId(), $municipality->getName(), $municipality->getSlug(), (string) $municipality->getIco()];
		}

		(new SymfonyStyle($input, $output))->table(['ID', 'Název', 'Zkratka', 'IČO'], $rows);

		return self::SUCCESS;
	}
}
