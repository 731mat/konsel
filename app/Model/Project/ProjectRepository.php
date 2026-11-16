<?php

declare(strict_types=1);

namespace App\Model\Project;

use App\Model\Municipality\Municipality;
use App\Model\Shared\Exception\NotFoundException;

interface ProjectRepository
{
	/**
	 * @throws NotFoundException
	 */
	public function get(int $id): Project;

	/**
	 * @return list<Project> rozpracované podle priority, pak naposledy změněné
	 */
	public function findByMunicipality(Municipality $municipality, ?ProjectStatus $status = null, bool $onlyActive = false): array;

	/**
	 * @return list<Project> zveřejněné úkoly pro občany
	 */
	public function findPublic(Municipality $municipality): array;

	/**
	 * @return array<string, int> stav → počet
	 */
	public function countByStatus(Municipality $municipality): array;

	/**
	 * Počet podnětů z jedné IP adresy od daného okamžiku (ochrana proti spamu).
	 */
	public function countProposalsFromIp(string $ip, \DateTimeImmutable $since): int;

	public function add(Project $project): void;
}
