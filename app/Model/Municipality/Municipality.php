<?php

declare(strict_types=1);

namespace App\Model\Municipality;

use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\IntegerId;
use App\Model\Shared\Timestampable;
use App\Model\Shared\TimestampableTrait;
use App\Model\Shared\ValueObject\Email;
use App\Model\Shared\ValueObject\HexColor;
use App\Model\Shared\ValueObject\Ico;
use App\Model\Shared\ValueObject\StoredFile;
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

	#[ORM\Column(length: 200)]
	private string $officialName;

	#[ORM\Column(length: 200, nullable: true)]
	private ?string $street = null;

	#[ORM\Column(length: 100, nullable: true)]
	private ?string $city = null;

	#[ORM\Column(length: 6, nullable: true)]
	private ?string $postalCode = null;

	#[ORM\Column(length: 180, nullable: true)]
	private ?string $email = null;

	#[ORM\Column(length: 50, nullable: true)]
	private ?string $phone = null;

	#[ORM\Column(length: 255, nullable: true)]
	private ?string $website = null;

	#[ORM\Column(nullable: true)]
	private ?int $population = null;

	#[ORM\Column(type: 'stored_file', nullable: true)]
	private ?StoredFile $logo = null;

	/**
	 * Barvy obce v aplikaci (null = výchozí barvy Konšela).
	 */
	#[ORM\Column(length: 7, nullable: true)]
	private ?string $primaryColor = null;

	#[ORM\Column(length: 7, nullable: true)]
	private ?string $accentColor = null;

	public function __construct(string $name, string $slug, ?Ico $ico = null)
	{
		$this->name = self::validateName($name);
		$this->slug = self::validateSlug($slug);
		$this->ico = $ico?->value;
		$this->officialName = 'Obec ' . $this->name;
	}

	public function updateProfile(MunicipalityProfile $profile): void
	{
		$this->officialName = $profile->officialName;
		$this->street = $profile->street;
		$this->city = $profile->city;
		$this->postalCode = $profile->postalCode;
		$this->email = $profile->email?->value;
		$this->phone = $profile->phone;
		$this->website = $profile->website;
		$this->population = $profile->population;
	}

	public function getProfile(): MunicipalityProfile
	{
		return new MunicipalityProfile(
			officialName: $this->officialName,
			street: $this->street,
			city: $this->city,
			postalCode: $this->postalCode,
			email: $this->email === null ? null : new Email($this->email),
			phone: $this->phone,
			website: $this->website,
			population: $this->population,
		);
	}

	public function changeLogo(?StoredFile $logo): void
	{
		$this->logo = $logo;
	}

	public function getLogo(): ?StoredFile
	{
		return $this->logo;
	}

	/**
	 * @param ?BrandColors $colors null = vrátit výchozí barvy
	 */
	public function changeBrandColors(?BrandColors $colors): void
	{
		$custom = $colors !== null && !$colors->isDefault();
		$this->primaryColor = $custom ? $colors->primary->value : null;
		$this->accentColor = $custom ? $colors->accent->value : null;
	}

	public function getBrandColors(): BrandColors
	{
		if ($this->primaryColor === null || $this->accentColor === null) {
			return BrandColors::default();
		}

		return new BrandColors(new HexColor($this->primaryColor), new HexColor($this->accentColor));
	}

	public function hasCustomBrandColors(): bool
	{
		return $this->primaryColor !== null;
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
