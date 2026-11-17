<?php

declare(strict_types=1);

namespace App\Model\Project;

use App\Model\People\Person;
use App\Model\Shared\Exception\ValidationException;

/**
 * Upravitelné údaje plánu (validované).
 */
final readonly class ProjectDetails
{
	public string $title;

	public ?string $description;

	/**
	 * @throws ValidationException
	 */
	public function __construct(
		string $title,
		?string $description = null,
		public ProjectPriority $priority = ProjectPriority::Normal,
		public ?Person $responsible = null,
		public ?int $estimatedCost = null,
	) {
		$title = trim((string) preg_replace('~\s+~u', ' ', $title));
		if ($title === '' || mb_strlen($title) > 200) {
			throw new ValidationException('Název plánu musí mít 1 až 200 znaků.', 'title');
		}

		$description = trim(str_replace(["\r\n", "\r"], "\n", (string) $description));
		if (mb_strlen($description) > 20_000) {
			throw new ValidationException('Popis je příliš dlouhý.', 'description');
		}

		if ($estimatedCost !== null && $estimatedCost < 0) {
			throw new ValidationException('Náklady nemohou být záporné.', 'estimatedCost');
		}

		$this->title = $title;
		$this->description = $description === '' ? null : $description;
	}
}
