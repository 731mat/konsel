<?php

declare(strict_types=1);

namespace App\Model\Template;

use App\Model\Municipality\Municipality;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\IntegerId;
use App\Model\Shared\TenantOwned;
use App\Model\Shared\Timestampable;
use App\Model\Shared\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * Šablona usnesení s proměnnými ({organ}, {obec}, {cislo_zasedani}, {datum_zasedani}, {rok}) – viz TemplateVariables.
 */
#[ORM\Entity]
#[ORM\Table(name: 'resolution_template')]
final class ResolutionTemplate implements TenantOwned, Timestampable
{
	use IntegerId;
	use TimestampableTrait;

	#[ORM\ManyToOne(targetEntity: Municipality::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private Municipality $municipality;

	#[ORM\Column(length: 200)]
	private string $title;

	#[ORM\Column(length: 100)]
	private string $category;

	#[ORM\Column(type: 'text')]
	private string $text;

	public function __construct(Municipality $municipality, string $title, string $category, string $text)
	{
		$this->municipality = $municipality;
		$this->change($title, $category, $text);
	}

	/**
	 * @throws ValidationException
	 */
	public function change(string $title, string $category, string $text): void
	{
		$title = trim($title);
		$category = trim($category);
		$text = trim(str_replace(["\r\n", "\r"], "\n", $text));
		if ($title === '' || mb_strlen($title) > 200) {
			throw new ValidationException('Název šablony musí mít 1 až 200 znaků.', 'title');
		}

		if ($category === '' || mb_strlen($category) > 100) {
			throw new ValidationException('Kategorie musí mít 1 až 100 znaků.', 'category');
		}

		if ($text === '' || mb_strlen($text) > 20_000) {
			throw new ValidationException('Text šablony musí mít 1 až 20 000 znaků.', 'text');
		}

		$this->title = $title;
		$this->category = $category;
		$this->text = $text;
	}

	public function getMunicipality(): Municipality
	{
		return $this->municipality;
	}

	public function getTitle(): string
	{
		return $this->title;
	}

	public function getCategory(): string
	{
		return $this->category;
	}

	public function getText(): string
	{
		return $this->text;
	}
}
