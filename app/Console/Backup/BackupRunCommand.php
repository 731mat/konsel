<?php

declare(strict_types=1);

namespace App\Console\Backup;

use App\Model\Backup\BackupContent;
use App\Model\Backup\BackupException;
use App\Model\Backup\BackupFacade;
use App\Model\Backup\BackupTrigger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'backup:run', description: 'Záloha databáze a souborů do var/backups (s --scheduled jen podle plánu v Nastavení → Zálohy – do cronu každou hodinu)')]
final class BackupRunCommand extends Command
{
	public function __construct(
		private readonly BackupFacade $backups,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->addOption('scheduled', null, InputOption::VALUE_NONE, 'Zálohovat, jen když je podle plánu na řadě');
		$this->addOption('db-only', null, InputOption::VALUE_NONE, 'Jen databáze, bez nahraných souborů');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		try {
			$file = $input->getOption('scheduled') === true
				? $this->backups->runScheduled()
				: $this->backups->run($input->getOption('db-only') === true ? BackupContent::Database : BackupContent::Full, BackupTrigger::Manual);
		} catch (BackupException $e) {
			$output->writeln("<error>Záloha selhala: {$e->getMessage()}</error>");

			return self::FAILURE;
		}

		$output->writeln($file === null
			? 'Záloha zatím není podle plánu na řadě.'
			: sprintf('Záloha vytvořena: %s (%s)', $file->name, BackupFacade::formatSize($file->size)));

		return self::SUCCESS;
	}
}
