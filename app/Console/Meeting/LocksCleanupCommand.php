<?php

declare(strict_types=1);

namespace App\Console\Meeting;

use App\Model\Meeting\AgendaLockFacade;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'locks:cleanup', description: 'Smaže vypršelé zámky bodů (obrazovka průběhu), vhodné do cronu')]
final class LocksCleanupCommand extends Command
{
	public function __construct(
		private readonly AgendaLockFacade $locks,
	) {
		parent::__construct();
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$output->writeln(sprintf('Smazáno vypršelých zámků: %d', $this->locks->cleanup()));

		return self::SUCCESS;
	}
}
