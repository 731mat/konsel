<?php

declare(strict_types=1);

namespace App\AdminModule\Audit;

use App\AdminModule\SecuredPresenter;
use App\Core\Security\Allowed;
use App\Core\Security\Resource;
use App\Model\Audit\AuditEntityType;
use App\Model\Audit\AuditFacade;
use App\Model\Audit\AuditFilter;
use Psr\Clock\ClockInterface;

/**
 * Přehled, kdo co v aplikaci změnil – s filtrem podle druhu, uživatele, období a textu.
 */
#[Allowed(Resource::Audit)]
final class AuditPresenter extends SecuredPresenter
{
	/**
	 * Období filtru: klíč → počet dní (null = vše).
	 */
	public const array Periods = [
		'7' => 'posledních 7 dní',
		'30' => 'posledních 30 dní',
		'365' => 'poslední rok',
		'' => 'celá historie',
	];

	public function __construct(
		private readonly AuditFacade $auditFacade,
		private readonly ClockInterface $clock,
	) {
		parent::__construct();
	}

	public function renderDefault(?string $type = null, ?string $user = null, ?string $period = '30', ?string $q = null, int $page = 1): void
	{
		$municipalityId = $this->requireMunicipality()->getId();
		$selectedType = AuditEntityType::tryFrom((string) $type);
		$actors = $this->auditFacade->findActors($municipalityId);
		$selectedUser = ctype_digit((string) $user) && isset($actors[(int) $user]) ? (int) $user : null;
		$period = array_key_exists((string) $period, self::Periods) ? (string) $period : '30';
		$since = $period === '' ? null : $this->clock->now()->modify("-{$period} days");

		[$entries, $total] = $this->auditFacade->find($municipalityId, new AuditFilter($selectedType, $selectedUser, $since, $q), $page);

		$this->template->entries = $entries;
		$this->template->total = $total;
		$this->template->page = max(1, $page);
		$this->template->pages = max(1, (int) ceil($total / AuditFacade::PageSize));
		$this->template->types = AuditEntityType::cases();
		$this->template->selectedType = $selectedType;
		$this->template->actors = $actors;
		$this->template->selectedUser = $selectedUser;
		$this->template->periods = self::Periods;
		$this->template->selectedPeriod = $period;
		$this->template->q = trim((string) $q);
	}
}
