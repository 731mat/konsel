<?php

declare(strict_types=1);

namespace App\ApiModule\Health;

use App\Core\Doctrine\DatabaseHealth;
use Nette\Application\UI\Presenter;
use Nette\Http\IResponse;

/**
 * GET /api/health – stav aplikace pro monitoring (bez přihlášení, bez citlivých údajů).
 */
final class HealthPresenter extends Presenter
{
	public function __construct(
		private readonly DatabaseHealth $databaseHealth,
	) {
		parent::__construct();
	}

	public function actionDefault(): void
	{
		$database = $this->databaseHealth->isAvailable();
		$this->getHttpResponse()->setCode($database ? IResponse::S200_OK : IResponse::S503_ServiceUnavailable);
		$this->sendJson([
			'status' => $database ? 'ok' : 'error',
			'database' => $database,
		]);
	}
}
