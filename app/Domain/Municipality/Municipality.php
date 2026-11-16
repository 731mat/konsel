<?php

declare(strict_types=1);

namespace App\Domain\Municipality;

use App\Domain\Shared\Exception\ValidationException;
use App\Domain\Shared\IntegerId;
use App\Domain\Shared\Timestampable;
use App\Domain\Shared\TimestampableTrait;
use App\Domain\Shared\ValueObject\Ico;
use Doctrine\ORM\Mapping as ORM;

/**
 * Obec, městys nebo město – kořen tenancy. Všechna data obce jsou na ni navázaná.
 */
#[ORM\Entity]
#[ORM\Table(name: 'municipality')]
final class Municipality implements Timestampable
{
	use IntegerId;
	use TimestampableTrait;

	private const string SlugPattern = '~^[a-z0-9]+(?:-[a-z0-9]+)*$~';

	#[ORM\Column(length: 200)]
	private string $name;

	#[ORM\Column(length: 100, unique: true)]
	private string $slug;

	#[ORM\Column(length: 8, unique: true, nullable: true)]
	private ?string $ico;

	public function __construct(string $name, string $slug, ?Ico $ico = null)
	{
		$this->name = self::validateName($name);
		$this->slug = self::validateSlug($slug);
		$this->ico = $ico?->value;
	}

	public function rename(string $name): void
	{
		$this->name = self::validateName($name);
	}

	public function changeIco(?Ico $ico): void
	{
		$this->ico = $ico?->value;
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function getSlug(): string
	{
		return $this->slug;
	}

	public function getIco(): ?Ico
	{
		return $this->ico === null ? null : new Ico($this->ico);
	}

	private static function validateName(string $name): string
	{
		$name = trim($name);
		if ($name === '' || mb_strlen($name) > 200) {
			throw new ValidationException('Název obce musí mít 1 až 200 znaků.', 'name');
		}

		return $name;
	}

	private static function validateSlug(string $slug): string
	{
		if (preg_match(self::SlugPattern, $slug) !== 1 || strlen($slug) > 100) {
			throw new ValidationException('Zkratka obce smí obsahovat jen malá písmena bez diakritiky, číslice a pomlčky.', 'slug');
		}

		return $slug;
	}
}
