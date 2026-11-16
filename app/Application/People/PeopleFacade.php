<?php

declare(strict_types=1);

namespace App\Application\People;

use App\Application\People\Command\AddMember;
use App\Application\People\Command\SaveBody;
use App\Application\People\Command\SavePerson;
use App\Application\Shared\TransactionManager;
use App\Domain\Meeting\MeetingRepository;
use App\Domain\Municipality\MunicipalityRepository;
use App\Domain\People\Body;
use App\Domain\People\BodyRepository;
use App\Domain\People\BodyType;
use App\Domain\People\Membership;
use App\Domain\People\Person;
use App\Domain\People\PersonName;
use App\Domain\People\PersonRepository;
use App\Domain\People\Term;
use App\Domain\People\TermRepository;
use App\Domain\Shared\Exception\InvalidStateException;
use App\Domain\Shared\Exception\NotFoundException;
use App\Domain\Shared\Exception\ValidationException;
use App\Domain\Shared\ValueObject\Email;
use Psr\Clock\ClockInterface;

/**
 * Osoby, volební období, orgány a členství.
 */
final readonly class PeopleFacade
{
	public function __construct(
		private MunicipalityRepository $municipalities,
		private PersonRepository $persons,
		private TermRepository $terms,
		private BodyRepository $bodies,
		private MeetingRepository $meetings,
		private TransactionManager $transaction,
		private ClockInterface $clock,
	) {}

	// --- Osoby ---

	public function createPerson(int $municipalityId, SavePerson $command): Person
	{
		[$name, $email] = self::personData($command);

		return $this->transaction->run(function () use ($municipalityId, $command, $name, $email): Person {
			$person = new Person($this->municipalities->get($municipalityId), $name, $email, $command->phone);
			$this->persons->add($person);

			return $person;
		});
	}

	public function updatePerson(int $personId, SavePerson $command): void
	{
		[$name, $email] = self::personData($command);

		$this->transaction->run(function () use ($personId, $command, $name, $email): void {
			$person = $this->persons->get($personId);
			$person->changeName($name);
			$person->changeContact($email, $command->phone);
		});
	}

	/**
	 * @throws InvalidStateException osoba má (měla) členství nebo funkci u zasedání – smazání by zničilo historii
	 */
	public function deletePerson(int $personId): void
	{
		$this->transaction->run(function () use ($personId): void {
			$person = $this->persons->get($personId);
			if ($this->persons->hasMemberships($person)) {
				throw new InvalidStateException("{$person->getFullName()} je nebo byl(a) členem orgánu – osobu nelze smazat.");
			}

			if ($this->meetings->hasOfficial($person)) {
				throw new InvalidStateException("{$person->getFullName()} je uveden(a) u zasedání jako předsedající, zapisovatel nebo ověřovatel – osobu nelze smazat.");
			}

			$this->persons->remove($person);
		});
	}

	public function getPerson(int $personId): Person
	{
		return $this->persons->get($personId);
	}

	/**
	 * @return list<Person>
	 */
	public function findPersons(int $municipalityId): array
	{
		return $this->persons->findByMunicipality($this->municipalities->get($municipalityId));
	}

	// --- Volební období ---

	public function createTerm(int $municipalityId, string $name, \DateTimeImmutable $startsOn, ?\DateTimeImmutable $endsOn = null): Term
	{
		return $this->transaction->run(function () use ($municipalityId, $name, $startsOn, $endsOn): Term {
			$municipality = $this->municipalities->get($municipalityId);
			$term = new Term($municipality, $name, $startsOn, $endsOn);

			foreach ($this->terms->findByMunicipality($municipality) as $existing) {
				if ($existing->overlaps($term)) {
					throw new ValidationException("Volební období se překrývá s obdobím {$existing->getName()}.", 'startsOn');
				}
			}

			$this->terms->add($term);

			return $term;
		});
	}

	public function findCurrentTerm(int $municipalityId): ?Term
	{
		return $this->terms->findAt($this->municipalities->get($municipalityId), $this->clock->now());
	}

	/**
	 * @return list<Term>
	 */
	public function findTerms(int $municipalityId): array
	{
		return $this->terms->findByMunicipality($this->municipalities->get($municipalityId));
	}

	public function getTerm(int $termId): Term
	{
		return $this->terms->get($termId);
	}

	// --- Orgány ---

	public function createBody(int $termId, SaveBody $command): Body
	{
		return $this->transaction->run(function () use ($termId, $command): Body {
			$term = $this->terms->get($termId);

			if ($command->type !== BodyType::Committee && $this->bodies->findByTermAndType($term, $command->type) !== null) {
				throw new ValidationException("{$command->type->label()} už v tomto volebním období existuje.", 'type');
			}

			$body = new Body($term, $command->type, $command->name, $command->seatCount);
			if ($command->numberingMask !== null) {
				$body->changeNumberingMask($command->numberingMask);
			}

			$this->bodies->add($body);

			return $body;
		});
	}

	public function updateBody(int $bodyId, SaveBody $command): void
	{
		$this->transaction->run(function () use ($bodyId, $command): void {
			$body = $this->bodies->get($bodyId);
			if ($body->getType() !== $command->type) {
				throw new ValidationException('Typ orgánu nelze změnit.', 'type');
			}

			$body->rename($command->name);
			$body->changeSeatCount($command->seatCount);
			if ($command->numberingMask !== null) {
				$body->changeNumberingMask($command->numberingMask);
			}
		});
	}

	public function getBody(int $bodyId): Body
	{
		return $this->bodies->get($bodyId);
	}

	/**
	 * @return list<Body>
	 */
	public function findBodies(int $termId): array
	{
		return $this->bodies->findByTerm($this->terms->get($termId));
	}

	// --- Členství ---

	public function addMember(int $bodyId, AddMember $command): Membership
	{
		return $this->transaction->run(function () use ($bodyId, $command): Membership {
			$body = $this->bodies->get($bodyId);

			return $body->addMember($this->persons->get($command->personId), $command->function, $command->validFrom, $command->validTo);
		});
	}

	public function endMembership(int $bodyId, int $membershipId, \DateTimeImmutable $validTo): void
	{
		$this->transaction->run(function () use ($bodyId, $membershipId, $validTo): void {
			$body = $this->bodies->get($bodyId);
			$body->endMembership(self::findMembership($body, $membershipId), $validTo);
		});
	}

	public function removeMembership(int $bodyId, int $membershipId): void
	{
		$this->transaction->run(function () use ($bodyId, $membershipId): void {
			$body = $this->bodies->get($bodyId);
			$body->removeMembership(self::findMembership($body, $membershipId));
		});
	}

	/**
	 * Upozornění na neobvyklé složení orgánů (nebrání uložení).
	 *
	 * @return list<string>
	 */
	public function getStructureWarnings(int $termId): array
	{
		$bodies = $this->findBodies($termId);
		$warnings = [];
		$council = null;
		$board = null;
		foreach ($bodies as $body) {
			$council = $body->getType() === BodyType::Council ? $body : $council;
			$board = $body->getType() === BodyType::Board ? $body : $board;
			if ($body->getType() !== BodyType::Board && $body->getSeatCount() % 2 === 0) {
				$warnings[] = "{$body->getName()}: počet členů ({$body->getSeatCount()}) by měl být lichý.";
			}
		}

		if ($council === null) {
			$warnings[] = 'V tomto volebním období chybí zastupitelstvo.';
		} elseif ($board !== null && $council->getSeatCount() < 15) {
			$warnings[] = 'Rada se volí jen v obcích s alespoň 15 zastupiteli (§ 99 zákona o obcích).';
		}

		if ($board !== null && ($board->getSeatCount() < 5 || $board->getSeatCount() % 2 === 0)) {
			$warnings[] = 'Rada má mít lichý počet členů, nejméně 5.';
		}

		$today = $this->clock->now();
		foreach ($bodies as $body) {
			$active = count($body->getMembersAt($today));
			if ($active < $body->getSeatCount() && $body->getTerm()->contains($today)) {
				$warnings[] = "{$body->getName()}: obsazeno {$active} z {$body->getSeatCount()} míst.";
			}
		}

		return $warnings;
	}

	/**
	 * @return array{PersonName, ?Email}
	 */
	private static function personData(SavePerson $command): array
	{
		return [
			new PersonName($command->firstName, $command->lastName, $command->titleBefore, $command->titleAfter),
			$command->email === null || trim($command->email) === '' ? null : new Email($command->email),
		];
	}

	private static function findMembership(Body $body, int $membershipId): Membership
	{
		foreach ($body->getMemberships() as $membership) {
			if ($membership->getId() === $membershipId) {
				return $membership;
			}
		}

		throw new NotFoundException('Členství neexistuje.');
	}
}
