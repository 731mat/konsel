<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Model\User\Role;
use Nette\Security\Permission;
use Nette\StaticClass;

/**
 * Matice oprávnění. Dokumentace: doc/permissions.md – při změně aktualizovat obojí.
 */
final class AuthorizatorFactory
{
	use StaticClass;

	public static function create(): Permission
	{
		$acl = new Permission();

		foreach (Role::cases() as $role) {
			$acl->addRole($role->value);
		}

		foreach (Resource::cases() as $resource) {
			$acl->addResource($resource->value);
		}

		// Čtenář: náhled na agendu zasedání
		$acl->allow(Role::Reader->value, [
			Resource::Dashboard->value,
			Resource::Meeting->value,
			Resource::Agenda->value,
			Resource::Minutes->value,
			Resource::Resolution->value,
			Resource::Attachment->value,
			Resource::Document->value,
			Resource::Template->value,
			Resource::Member->value,
			Resource::Project->value,
		], Privilege::View->value);

		// Editor (úředník, zapisovatel): příprava a vedení zasedání
		$acl->allow(Role::Editor->value, [
			Resource::Meeting->value,
			Resource::Agenda->value,
			Resource::Minutes->value,
			Resource::Resolution->value,
			Resource::Attachment->value,
			Resource::Document->value,
			Resource::Template->value,
			Resource::Project->value,
		], Privilege::Edit->value);
		$acl->allow(Role::Editor->value, [
			Resource::Agenda->value,
			Resource::Attachment->value,
		], Privilege::Delete->value);
		$acl->allow(Role::Editor->value, Resource::Dashboard->value, Privilege::View->value);
		$acl->allow(Role::Editor->value, [
			Resource::Meeting->value,
			Resource::Agenda->value,
			Resource::Minutes->value,
			Resource::Resolution->value,
			Resource::Attachment->value,
			Resource::Document->value,
			Resource::Template->value,
			Resource::Member->value,
			Resource::Project->value,
		], Privilege::View->value);

		// Správce obce: vše v rámci obce kromě správy systému
		$acl->allow(Role::Admin->value, Permission::All, Permission::All);
		$acl->deny(Role::Admin->value, Resource::System->value, Permission::All);

		// Správce systému: vše
		$acl->allow(Role::SuperAdmin->value, Permission::All, Permission::All);

		return $acl;
	}
}
