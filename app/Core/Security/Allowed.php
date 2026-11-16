<?php

declare(strict_types=1);

namespace App\Core\Security;

/**
 * Oprávnění potřebné pro presenter, action*, render* nebo handle* metodu.
 * Kontroluje SecuredPresenter::checkRequirements(); při nesplnění vrací 403.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final readonly class Allowed
{
	public function __construct(
		public Resource $resource,
		public Privilege $privilege = Privilege::View,
	) {}
}
