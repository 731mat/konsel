<?php

declare(strict_types=1);

namespace App\Console\User;

use App\Application\Municipality\MunicipalityFacade;
use App\Application\Security\PasswordPolicy;
use App\Application\User\Command\CreateUser;
use App\Application\User\UserFacade;
use App\Domain\Shared\Exception\DomainException;
use App\Domain\User\Role;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'user:create', description: 'Založí uživatele úřadu')]
final class CreateUserCommand extends Command
{
	public function __construct(
		private readonly UserFacade $userFacade,
		private readonly MunicipalityFacade $municipalityFacade,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$roles = implode(', ', array_map(static fn(Role $role): string => $role->value, Role::cases()));
		$this
			->addArgument('email', InputArgument::REQUIRED, 'E-mail (přihlašovací jméno)')
			->addArgument('name', InputArgument::REQUIRED, 'Celé jméno')
			->addOption('role', 'r', InputOption::VALUE_REQUIRED, "Role ({$roles})", Role::Editor->value)
			->addOption('municipality', 'm', InputOption::VALUE_REQUIRED, 'Zkratka obce (u superadmina se nevyplňuje)')
			->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'Heslo (výchozí: vygeneruje se)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$role = Role::tryFrom(self::stringOption($input, 'role') ?? '');
		if ($role === null) {
			$io->error('Neznámá role.');

			return self::FAILURE;
		}

		$municipalityId = null;
		$slug = self::stringOption($input, 'municipality');
		if ($slug !== null) {
			$municipality = $this->municipalityFacade->findBySlug($slug);
			if ($municipality === null) {
				$io->error("Obec se zkratkou „{$slug}“ neexistuje.");

				return self::FAILURE;
			}

			$municipalityId = $municipality->getId();
		}

		$password = self::stringOption($input, 'password');
		$generated = $password === null;
		$password ??= PasswordPolicy::generate();

		try {
			$user = $this->userFacade->create(new CreateUser(
				email: self::stringArgument($input, 'email'),
				name: self::stringArgument($input, 'name'),
				role: $role,
				password: $password,
				municipalityId: $municipalityId,
				mustChangePassword: $generated,
			));
		} catch (DomainException $e) {
			$io->error($e->getMessage());

			return self::FAILURE;
		}

		$io->success(sprintf('Uživatel %s (%s) založen.', $user->getEmail(), $user->getRole()->label()));
		if ($generated) {
			$io->writeln("Dočasné heslo: <info>{$password}</info> (při prvním přihlášení bude vyžadována změna)");
		}

		return self::SUCCESS;
	}

	private static function stringOption(InputInterface $input, string $name): ?string
	{
		$value = $input->getOption($name);

		return is_string($value) && $value !== '' ? $value : null;
	}

	private static function stringArgument(InputInterface $input, string $name): string
	{
		$value = $input->getArgument($name);

		return is_string($value) ? $value : '';
	}
}
