<?php

declare(strict_types=1);

namespace App\Model\Shared;

use App\Model\Municipality\Municipality;

/**
 * Entita patřící jedné obci. Konvence: asociace se musí jmenovat `municipality`
 * (podle ní TenantFilter skládá podmínku).
 */
interface TenantOwned
{
	public function getMunicipality(): Municipality;
}
