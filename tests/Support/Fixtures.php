<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Model\Municipality\Command\CreateMunicipality;
use App\Model\Municipality\Municipality;
use App\Model\Municipality\MunicipalityFacade;
use App\Model\User\Command\CreateUser;
use App\Model\User\Role;
use App\Model\User\User;
use App\Model\User\UserFacade;
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
