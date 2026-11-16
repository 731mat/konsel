<?php

declare(strict_types=1);

namespace App\Model\People;

use App\Model\Municipality\Municipality;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\IntegerId;
use App\Model\Shared\TenantOwned;
use App\Model\Shared\Timestampable;
use App\Model\Shared\TimestampableTrait;
use App\Model\Shared\ValueObject\Email;
use Doctrine\ORM\Mapping as ORM;

/**
 * Fyzická osoba – zastupitel, radní, člen výboru. Nemusí mít účet v systému.
 */
#[ORM\Entity]
#[ORM\Table(name: 'person')]
final class Person implements TenantOwned, Timestampable
{
	use IntegerId;
	use TimestampableTrait;

	#[ORM\ManyToOne(targetEntity: Municipality::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private Municipality $municipality;

	#[ORM\Column(length: 50, nullable: true)]
	private ?string $titleBefore = null;

	#[ORM\Column(length: 100)]
	private string $firstName;

	#[ORM\Column(length: 100)]
	private string $lastName;

	#[ORM\Column(length: 50, nullable: true)]
	private ?string $titleAfter = null;

	#[ORM\Column(length: 180, nullable: true)]
	private ?string $email = null;

	#[ORM\Column(length: 50, nullable: true)]
	private ?string $phone = null;

	public function __construct(Municipality $municipality, PersonName $name, ?Email $email = null, ?string $phone = null)
	{
		$this->municipality = $municipality;
		$this->changeName($name);
		$this->changeContact($email, $phone);
	}

	public function changeName(PersonName $name): void
	{
		$this->titleBefore = $name->titleBefore;
		$this->firstName = $name->firstName;
		$this->lastName = $name->lastName;
		$this->titleAfter = $name->titleAfter;
	}

	public function changeContact(?Email $email, ?string $phone): void
	{
		$phone = $phone === null ? null : trim($phone);
		if ($phone !== null && mb_strlen($phone) > 50) {
			throw new ValidationException('Telefon může mít nejvýše 50 znaků.', 'phone');
		}

		$this->email = $email?->value;
		$this->phone = $phone === '' ? null : $phone;
	}

	public function getMunicipality(): Municipality
	{
		return $this->municipality;
	}

	public function getName(): PersonName
	{
		return new PersonName($this->firstName, $this->lastName, $this->titleBefore, $this->titleAfter);
	}

	public function getFullName(): string
	{
		return $this->getName()->full();
	}

	public function getEmail(): ?Email
	{
		return $this->email === null ? null : new Email($this->email);
	}

	public function getPhone(): ?string
	{
		return $this->phone;
	}
}
