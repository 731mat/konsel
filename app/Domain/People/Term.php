<?php

declare(strict_types=1);

namespace App\Domain\People;

use App\Domain\Municipality\Municipality;
use App\Domain\Shared\Exception\ValidationException;
use App\Domain\Shared\IntegerId;
use App\Domain\Shared\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * Volební období, např. 2026–2030.
 */
#[ORM\Entity]
#[ORM\Table(name: 'term')]
final class Term implements TenantOwned
{
	use IntegerId;

	#[ORM\ManyToOne(targetEntity: Municipality::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private Municipality $municipality;

	#[ORM\Column(length: 50)]
	private string $name;

	#[ORM\Column(type: 'date_immutable')]
	private \DateTimeImmutable $startsOn;

	#[ORM\Column(type: 'date_immutable', nullable: true)]
	private ?\DateTimeImmutable $endsOn;

	public function __construct(Municipality $municipality, string $name, \DateTimeImmutable $startsOn, ?\DateTimeImmutable $endsOn = null)
	{
		$this->municipality = $municipality;
		$this->rename($name);
		$this->changePeriod($startsOn, $endsOn);
	}

	public function rename(string $name): void
	{
		$name = trim($name);
		if ($name === '' || mb_strlen($name) > 50) {
			throw new ValidationException('Název volebního období musí mít 1 až 50 znaků.', 'name');
		}

		$this->name = $name;
	}

	public function changePeriod(\DateTimeImmutable $startsOn, ?\DateTimeImmutable $endsOn): void
	{
		$startsOn = $startsOn->setTime(0, 0);
		$endsOn = $endsOn?->setTime(0, 0);
		if ($endsOn !== null && $endsOn < $startsOn) {
			throw new ValidationException('Konec volebního období nemůže být před jeho začátkem.', 'endsOn');
		}

		$this->startsOn = $startsOn;
		$this->endsOn = $endsOn;
	}

	public function overlaps(self $other): bool
	{
		return DateRange::overlap($this->startsOn, $this->endsOn, $other->startsOn, $other->endsOn);
	}

	public function contains(\DateTimeImmutable $date): bool
	{
		return DateRange::contains($this->startsOn, $this->endsOn, $date);
	}

	public function getMunicipality(): Municipality
	{
		return $this->municipality;
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function getStartsOn(): \DateTimeImmutable
	{
		return $this->startsOn;
	}

	public function getEndsOn(): ?\DateTimeImmutable
	{
		return $this->endsOn;
	}
}
