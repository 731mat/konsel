<?php

declare(strict_types=1);

use App\Core\Security\AuthorizatorFactory;
use App\Core\Security\Privilege as P;
use App\Core\Security\Resource as R;
use App\Model\User\Role;
use Tester\Assert;

require __DIR__ . '/../../../bootstrap.php';

/**
 * Očekávaná matice – musí odpovídat doc/permissions.md.
 *
 * @return array<string, array<string, list<P>>> role => resource => allowed privileges
 */
function expectedMatrix(): array
{
	$all = P::cases();
	$view = [P::View];
	$viewEdit = [P::View, P::Edit];
	$viewEditDelete = [P::View, P::Edit, P::Delete];

	$reader = [
		R::Dashboard->value => $view,
		R::Meeting->value => $view,
		R::Agenda->value => $view,
		R::Minutes->value => $view,
		R::Resolution->value => $view,
		R::Attachment->value => $view,
		R::Document->value => $view,
		R::Template->value => $view,
		R::Member->value => $view,
	];

	$editor = [
		R::Dashboard->value => $view,
		R::Meeting->value => $viewEdit,
		R::Agenda->value => $viewEditDelete,
		R::Minutes->value => $viewEdit,
		R::Resolution->value => $viewEdit,
		R::Attachment->value => $viewEditDelete,
		R::Document->value => $viewEdit,
		R::Template->value => $viewEdit,
		R::Member->value => $view,
	];

	$admin = [];
	$superAdmin = [];
	foreach (R::cases() as $resource) {
		$admin[$resource->value] = $resource === R::System ? [] : $all;
		$superAdmin[$resource->value] = $all;
	}

	return [
		Role::Reader->value => $reader,
		Role::Editor->value => $editor,
		Role::Admin->value => $admin,
		Role::SuperAdmin->value => $superAdmin,
	];
}

test('permission matrix matches specification for every role, resource and privilege', function (): void {
	$acl = AuthorizatorFactory::create();

	foreach (expectedMatrix() as $role => $resources) {
		foreach (R::cases() as $resource) {
			$allowed = $resources[$resource->value] ?? [];
			foreach (P::cases() as $privilege) {
				Assert::same(
					in_array($privilege, $allowed, true),
					$acl->isAllowed($role, $resource->value, $privilege->value),
					"{$role} × {$resource->value} × {$privilege->value}",
				);
			}
		}
	}
});
