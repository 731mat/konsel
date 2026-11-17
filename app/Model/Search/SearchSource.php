<?php

declare(strict_types=1);

namespace App\Model\Search;

/**
 * Zdroj všech hledaných entit pro přeindexování.
 */
interface SearchSource
{
	/**
	 * @return iterable<object> entity všech tříd SearchDocumentFactory::IndexedClasses (v konzoli všech obcí)
	 */
	public function all(): iterable;
}
