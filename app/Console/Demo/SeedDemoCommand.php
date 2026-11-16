<?php

declare(strict_types=1);

namespace App\Console\Demo;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:seed-demo', description: 'Nahraje demo data do prázdné databáze')]
final class SeedDemoCommand extends Command
{
	public function __construct(
		private readonly DemoSeeder $seeder,
		private readonly bool $productionMode,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->addOption('force', null, InputOption::VALUE_NONE, 'Povolit i v produkčním režimu');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		if ($this->productionMode && $input->getOption('force') !== true) {
			$io->error('Demo data se v produkci nenahrávají. Použijte --force, pokud opravdu víte, co děláte.');

			return self::FAILURE;
		}

		$io->listing($this->seeder->seed());
		$io->success('Demo data nahrána.');

		return self::SUCCESS;
	}
}
