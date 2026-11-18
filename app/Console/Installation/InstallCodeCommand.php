<?php

declare(strict_types=1);

namespace App\Console\Installation;

use App\Model\Installation\InstallationFacade;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'install:code', description: 'Vypíše instalační kód pro webového průvodce instalací (jen dokud instalace neproběhla)')]
final class InstallCodeCommand extends Command
{
	public function __construct(
		private readonly InstallationFacade $installation,
		private readonly string $appUrl,
	) {
		parent::__construct();
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		if (!$this->installation->isRequired()) {
			$output->writeln('Instalace už proběhla.', OutputInterface::VERBOSITY_VERBOSE);

			return self::SUCCESS;
		}

		$output->writeln(sprintf('Instalace: otevřete %s/instalace a zadejte instalační kód %s', rtrim($this->appUrl, '/'), $this->installation->code()));

		return self::SUCCESS;
	}
}
