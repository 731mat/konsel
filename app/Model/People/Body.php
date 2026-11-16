<?php

declare(strict_types=1);

namespace App\Model\People;

use App\Model\Municipality\Municipality;
use App\Model\Shared\Exception\InvalidStateException;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\IntegerId;
use App\Model\Shared\TenantOwned;
use App\Model\Shared\Timestampable;
use App\Model\Shared\TimestampableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Orgán obce (zastupitelstvo, rada, výbor) v jednom volebním období.
 * Agregát: členství se mění jen přes metody orgánu, které hlídají pravidla.
 */
#[ORM\Entity]
#[ORM\Table(name: 'body')]
final class Body implements TenantOwned, Timestampable
{
	use IntegerId;
	use TimestampableTrait;

	public const string DefaultNumberingMask = '{n}/{yyyy}';

	#[ORM\ManyToOne(targetEntity: Municipality::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private Municipality $municipality;

	#[ORM\ManyToOne(targetEntity: Term::class)]
	#[ORM\JoinColumn(nullable: false)]
	private Term $term;

	#[ORM\Column(length: 20, enumType: BodyType::class)]
	private BodyType $type;

	#[ORM\Column(length: 200)]
	private string $name;

	#[ORM\Column]
	private int $seatCount;

	#[ORM\Column(length: 100)]
	private string $numberingMask = self::DefaultNumberingMask;

	/**
	 * @var Collection<int, Membership>
	 */
	#[ORM\OneToMany(targetEntity: Membership::class, mappedBy: 'body', cascade: ['persist', 'remove'], orphanRemoval: true)]
	#[ORM\OrderBy([
		'validFrom' => 'ASC',
	])]
	private Collection $memberships;

	public function __construct(Term $term, BodyType $type, string $name, int $seatCount)
	{
		$this->municipality = $term->getMunicipality();
		$this->term = $term;
		$this->type = $type;
		$this->memberships = new ArrayCollection();
		$this->rename($name);
		$this->changeSeatCount($seatCount);
	}

	public function rename(string $name): void
	{
		$name = trim($name);
		if ($name === '' || mb_strlen($name) > 200) {
			throw new ValidationException('Název orgánu musí mít 1 až 200 znaků.', 'name');
		}

		$this->name = $name;
	}

	public function changeSeatCount(int $seatCount): void
	{
		if ($seatCount < 1 || $seatCount > 99) {
			throw new ValidationException('Počet členů musí být 1 až 99.', 'seatCount');
		}

		foreach ($this->memberships as $membership) {
			if ($this->countActiveAt($membership->getValidFrom()) > $seatCount) {
				throw new ValidationException('Orgán už má více členů, než je nový počet míst.', 'seatCount');
			}
		}

		$this->seatCount = $seatCount;
	}

	public function changeNumberingMask(string $mask): void
	{
		$mask = trim($mask);
		if ($mask === '' || mb_strlen($mask) > 100 || !str_contains($mask, '{n')) {
			throw new ValidationException('Maska číslování musí obsahovat pořadové číslo {n}.', 'numberingMask');
		}

		$this->numberingMask = $mask;
	}

	/**
	 * @throws ValidationException
	 */
	public function addMember(
		Person $person,
		MemberFunction $function,
		\DateTimeImmutable $validFrom,
		?\DateTimeImmutable $validTo = null,
	): Membership {
		$validFrom = $validFrom->setTime(0, 0);
		$validTo = $validTo?->setTime(0, 0);

		if ($person->getMunicipality() !== $this->municipality) {
			throw new ValidationException('Osoba patří k jiné obci.', 'person');
		}

		if (!in_array($function, $this->type->allowedFunctions(), true)) {
			throw new ValidationException("Funkci „{$function->label()}“ nelze v orgánu typu {$this->type->label()} zastávat.", 'function');
		}

		if ($validTo !== null && $validTo < $validFrom) {
			throw new ValidationException('Konec členství nemůže být před jeho začátkem.', 'validTo');
		}

		if (!$this->term->contains($validFrom)) {
			throw new ValidationException('Začátek členství musí spadat do volebního období orgánu.', 'validFrom');
		}

		foreach ($this->memberships as $existing) {
			if (!$existing->overlaps($validFrom, $validTo)) {
				continue;
			}

			if ($existing->getPerson() === $person) {
				throw new ValidationException("{$person->getFullName()} už je v tomto období členem orgánu.", 'person');
			}

			if ($function->isUnique() && $existing->getFunction() === $function) {
				throw new ValidationException("Funkci „{$function->label()}“ už v tomto období zastává {$existing->getPerson()->getFullName()}.", 'function');
			}
		}

		$this->assertSeatAvailable($validFrom, $validTo);

		$membership = new Membership($this, $person, $function, $validFrom, $validTo);
		$this->memberships->add($membership);

		return $membership;
	}

	public function endMembership(Membership $membership, \DateTimeImmutable $validTo): void
	{
		$this->assertOwns($membership);
		$membership->endOn($validTo->setTime(0, 0));
	}

	/**
	 * Smazání chybně zadaného členství (ne ukončení – to je endMembership).
	 */
	public function removeMembership(Membership $membership): void
	{
		$this->assertOwns($membership);
		$this->memberships->removeElement($membership);
	}

	/**
	 * Členové platní k danému dni, seřazení podle funkce a příjmení.
	 *
	 * @return list<Membership>
	 */
	public function getMembersAt(\DateTimeImmutable $date): array
	{
		$members = array_values(array_filter(
			$this->memberships->toArray(),
			static fn(Membership $membership): bool => $membership->isActiveAt($date),
		));

		usort($members, static fn(Membership $a, Membership $b): int => [$a->getFunction()->rank(), $a->getPerson()->getName()->sortable()]
			<=> [$b->getFunction()->rank(), $b->getPerson()->getName()->sortable()]);

		return $members;
	}

	public function findMembershipOf(Person $person, \DateTimeImmutable $date): ?Membership
	{
		foreach ($this->memberships as $membership) {
			if ($membership->getPerson() === $person && $membership->isActiveAt($date)) {
				return $membership;
			}
		}

		return null;
	}

	/**
	 * @return list<Membership>
	 */
	public function getMemberships(): array
	{
		return array_values($this->memberships->toArray());
	}

	public function getMunicipality(): Municipality
	{
		return $this->municipality;
	}

	public function getTerm(): Term
	{
		return $this->term;
	}

	public function getType(): BodyType
	{
		return $this->type;
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function getSeatCount(): int
	{
		return $this->seatCount;
	}

	public function getNumberingMask(): string
	{
		return $this->numberingMask;
	}

	private function countActiveAt(\DateTimeImmutable $date): int
	{
		return count(array_filter(
			$this->memberships->toArray(),
			static fn(Membership $membership): bool => $membership->isActiveAt($date),
		));
	}

	/**
	 * Maximální souběh členství nastává v den začátku nového členství nebo v den začátku
	 * některého existujícího členství uvnitř intervalu.
	 */
	private function assertSeatAvailable(\DateTimeImmutable $validFrom, ?\DateTimeImmutable $validTo): void
	{
		$checkpoints = [$validFrom];
		foreach ($this->memberships as $existing) {
			if (DateRange::contains($validFrom, $validTo, $existing->getValidFrom())) {
				$checkpoints[] = $existing->getValidFrom();
			}
		}

		foreach ($checkpoints as $day) {
			if ($this->countActiveAt($day) >= $this->seatCount) {
				throw new ValidationException("Orgán má k {$day->format('j. n. Y')} obsazena všechna místa ({$this->seatCount}). Nejdřív ukončete členství odcházejícího člena.", 'validFrom');
			}
		}
	}

	private function assertOwns(Membership $membership): void
	{
		if ($membership->getBody() !== $this) {
			throw new InvalidStateException('Členství nepatří k tomuto orgánu.');
		}
	}
}
