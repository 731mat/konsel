<?php

declare(strict_types=1);

namespace App\Model\People;

use App\Model\Municipality\Municipality;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\IntegerId;
use App\Model\Shared\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * Členství osoby v orgánu s funkcí a platností [validFrom, validTo]. Vzniká jen přes Body::addMember().
 */
#[ORM\Entity]
#[ORM\Table(name: 'membership')]
final class Membership implements TenantOwned
{
	use IntegerId;

	#[ORM\ManyToOne(targetEntity: Municipality::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private Municipality $municipality;

	#[ORM\ManyToOne(targetEntity: Body::class, inversedBy: 'memberships')]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private Body $body;

	#[ORM\ManyToOne(targetEntity: Person::class)]
	#[ORM\JoinColumn(nullable: false)]
	private Person $person;

	#[ORM\Column(name: 'member_function', length: 20, enumType: MemberFunction::class)]
	private MemberFunction $function;

	#[ORM\Column(type: 'date_immutable')]
	private \DateTimeImmutable $validFrom;

	#[ORM\Column(type: 'date_immutable', nullable: true)]
	private ?\DateTimeImmutable $validTo;

	/**
	 * @internal použijte Body::addMember()
	 */
	public function __construct(Body $body, Person $person, MemberFunction $function, \DateTimeImmutable $validFrom, ?\DateTimeImmutable $validTo)
	{
		$this->municipality = $body->getMunicipality();
		$this->body = $body;
		$this->person = $person;
		$this->function = $function;
		$this->validFrom = $validFrom;
		$this->validTo = $validTo;
	}

	/**
	 * @internal použijte Body::endMembership()
	 */
	public function endOn(\DateTimeImmutable $validTo): void
	{
		if ($validTo < $this->validFrom) {
			throw new ValidationException('Konec členství nemůže být před jeho začátkem.', 'validTo');
		}

		$this->validTo = $validTo;
	}

	public function isActiveAt(\DateTimeImmutable $date): bool
	{
		return DateRange::contains($this->validFrom, $this->validTo, $date);
	}

	public function overlaps(\DateTimeImmutable $from, ?\DateTimeImmutable $to): bool
	{
		return DateRange::overlap($this->validFrom, $this->validTo, $from, $to);
	}

	public function getMunicipality(): Municipality
	{
		return $this->municipality;
	}

	public function getBody(): Body
	{
		return $this->body;
	}

	public function getPerson(): Person
	{
		return $this->person;
	}

	public function getFunction(): MemberFunction
	{
		return $this->function;
	}

	public function getValidFrom(): \DateTimeImmutable
	{
		return $this->validFrom;
	}

	public function getValidTo(): ?\DateTimeImmutable
	{
		return $this->validTo;
	}
}
