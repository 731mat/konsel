<?php

declare(strict_types=1);

namespace App\Console\Security;

use App\Core\Security\SecretBox;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:encryption-key', description: 'Vygeneruje šifrovací klíč aplikace pro config/local.neon')]
final class EncryptionKeyCommand extends Command
{
	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$output->writeln('Vložte do config/local.neon (klíč nikdy necommitujte, při ztrátě nepůjde dešifrovat uložené API klíče):');
		$output->writeln('');
		$output->writeln('parameters:');
		$output->writeln("\tencryptionKey: '" . SecretBox::generateKey() . "'");

		return self::SUCCESS;
	}
}
