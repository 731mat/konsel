<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Model\Municipality\Module;

/**
 * Presenter nebo jeho metoda patří k volitelnému modulu obce. Je-li modul vypnutý, vrací 404
 * (kontroluje SecuredPresenter::checkRequirements(), v portálu a veřejné části BasePresenter::requireModule()).
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class RequiresModule
{
	public function __construct(
		public Module $module,
	) {}
}
