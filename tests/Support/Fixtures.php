<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Municipality\Command\CreateMunicipality;
use App\Application\Municipality\MunicipalityFacade;
use App\Application\User\Command\CreateUser;
use App\Application\User\UserFacade;
use App\Domain\Municipality\Municipality;
use App\Domain\User\Role;
use App\Domain\User\User;
use Nette\DI\Container;

/**
 * Pomocník pro zakládání testovacích dat přes fasády (stejnou cestou jako aplikace).
 */
final readonly class Fixtures
{
	public const string Password = 'tajne-heslo-123';

	public function __construct(
		private Container $container,
	) {}

	public function municipality(string $name = 'Neplachovice'): Municipality
	{
		return $this->container->getByType(MunicipalityFacade::class)->create(new CreateMunicipality($name));
	}

	public function user(
		Municipality $municipality,
		string $email = 'jana@neplachovice.cz',
		Role $role = Role::Editor,
	): User {
		return $this->container->getByType(UserFacade::class)->create(new CreateUser(
			email: $email,
			name: 'Jana Nováková',
			role: $role,
			password: self::Password,
			municipalityId: $municipality->getId(),
		));
	}
}
