<?php

declare(strict_types=1);

namespace App\Console\Municipality;

use App\Model\Municipality\Command\CreateMunicipality;
use App\Model\Municipality\MunicipalityFacade;
use App\Model\Shared\Exception\ValidationException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'municipality:create', description: 'Založí novou obec')]
final class CreateMunicipalityCommand extends Command
{
	public function __construct(
		private readonly MunicipalityFacade $facade,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this
			->addArgument('name', InputArgument::REQUIRED, 'Název obce, např. "Neplachovice"')
			->addOption('ico', null, InputOption::VALUE_REQUIRED, 'IČO obce')
			->addOption('slug', null, InputOption::VALUE_REQUIRED, 'Zkratka do URL (výchozí: z názvu)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$name = $input->getArgument('name');
		$ico = $input->getOption('ico');
		$slug = $input->getOption('slug');

		try {
			$municipality = $this->facade->create(new CreateMunicipality(
				name: is_string($name) ? $name : '',
				ico: is_string($ico) ? $ico : null,
				slug: is_string($slug) ? $slug : null,
			));
		} catch (ValidationException $e) {
			$io->error($e->getMessage());

			return self::FAILURE;
		}

		$io->success(sprintf(
			'Obec „%s“ založena (id %d, zkratka %s).',
			$municipality->getName(),
			$municipality->getId(),
			$municipality->getSlug(),
		));

		return self::SUCCESS;
	}
}
