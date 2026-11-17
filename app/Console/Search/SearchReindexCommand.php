<?php

declare(strict_types=1);

namespace App\Console\Search;

use App\Model\Search\SearchFacade;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'search:reindex', description: 'Postaví fulltextový index znovu z dat všech obcí (po nasazení nebo změně indexace)')]
final class SearchReindexCommand extends Command
{
	public function __construct(
		private readonly SearchFacade $search,
	) {
		parent::__construct();
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$output->writeln(sprintf('Zaindexováno záznamů: %d', $this->search->reindex()));

		return self::SUCCESS;
	}
}
