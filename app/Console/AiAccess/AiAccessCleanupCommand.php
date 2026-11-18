<?php

declare(strict_types=1);

namespace App\Console\AiAccess;

use App\Model\AiAccess\AiAccessFacade;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'ai-access:cleanup', description: 'Smaže prošlé kódy OAuth, odvolaná a vypršelá připojení AI a nepoužité registrace aplikací (do cronu)')]
final class AiAccessCleanupCommand extends Command
{
	public function __construct(
		private readonly AiAccessFacade $aiAccess,
	) {
		parent::__construct();
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$output->writeln(sprintf('Smazáno záznamů: %d', $this->aiAccess->cleanup()));

		return self::SUCCESS;
	}
}
