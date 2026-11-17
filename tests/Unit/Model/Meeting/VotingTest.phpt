<?php

declare(strict_types=1);

use App\Model\Meeting\AgendaItem;
use App\Model\Meeting\AgendaItemContent;
use App\Model\Meeting\Meeting;
use App\Model\Meeting\MeetingType;
use App\Model\Meeting\Quorum;
use App\Model\Meeting\VoteChoice;
use App\Model\Meeting\VotingResult;
use App\Model\Meeting\VotingRound;
use App\Model\Meeting\VotingRule;
use App\Model\Meeting\VotingType;
use App\Model\Municipality\Municipality;
use App\Model\People\Body;
use App\Model\People\BodyType;
use App\Model\People\Person;
use App\Model\People\PersonName;
use App\Model\People\Term;
use App\Model\Shared\Exception\InvalidStateException;
use App\Model\Shared\Exception\ValidationException;
use Symfony\Component\Clock\MockClock;
use Tester\Assert;

require __DIR__ . '/../../../bootstrap.php';

/**
 * Probíhající zasedání devítičlenného zastupitelstva s jedním bodem a 9 osobami.
 *
 * @return array{Meeting, AgendaItem, list<Person>, MockClock}
 */
function votingScene(bool $start = true): array
{
	$municipality = new Municipality('Neplachovice', 'neplachovice');
	$council = new Body(new Term($municipality, '2026–2030', new DateTimeImmutable('2026-10-01')), BodyType::Council, 'Zastupitelstvo obce Neplachovice', 9);
	$meeting = new Meeting($council, 3, MeetingType::Regular, new DateTimeImmutable('2026-12-09 18:00'), 'Obecní dům');
	$item = $meeting->addAgendaItem(new AgendaItemContent('Rozpočet 2027', null, 'Zastupitelstvo schvaluje rozpočet.'));
	$people = array_map(static fn(int $i): Person => new Person($municipality, new PersonName('Člen', "Č{$i}")), range(1, 9));
	$clock = new MockClock('2026-12-09 18:00', 'Europe/Prague');
	if ($start) {
		$meeting->share($clock);
		$meeting->start($clock);
	}

	return [$meeting, $item, $people, $clock];
}

/**
 * @param list<Person> $present
 * @param array<int, VoteChoice> $choices index osoby → volba (ostatní pro)
 */
function vote(VotingRound $round, array $present, array $choices = []): void
{
	foreach ($present as $i => $person) {
		$round->cast($person, $choices[$i] ?? VoteChoice::For, true);
	}
}

test('majority of all members decides, not majority of those present', function (): void {
	$rule = new VotingRule(9);
	Assert::same(5, $rule->required());
	Assert::same(VotingResult::Adopted, $rule->evaluate(5));
	Assert::same(VotingResult::Rejected, $rule->evaluate(4), '4:4 remíza i 4 pro při 5 přítomných nestačí');
	Assert::same(VotingResult::Rejected, (new VotingRule(10))->evaluate(5), 'u sudého počtu nestačí polovina');
	Assert::same(VotingResult::Adopted, (new VotingRule(10))->evaluate(6));
	Assert::exception(fn() => new VotingRule(0), InvalidStateException::class);
});

test('voting needs a started meeting with quorum', function (): void {
	[, $item, , $clock] = votingScene(start: false);
	Assert::exception(fn() => new VotingRound($item, VotingType::Proposal, 'Text', null, new Quorum(9, 9), [], $clock->now()), InvalidStateException::class, '%a%zahajte%a%');

	[, $item, , $clock] = votingScene();
	Assert::exception(fn() => new VotingRound($item, VotingType::Proposal, 'Text', null, new Quorum(9, 4), [], $clock->now()), InvalidStateException::class, '%a%není usnášeníschopný%a%');
	$e = Assert::exception(fn() => new VotingRound($item, VotingType::Proposal, '  ', null, new Quorum(9, 9), [], $clock->now()), ValidationException::class);
	Assert::same('proposalText', $e?->field);
	$e = Assert::exception(fn() => new VotingRound($item, VotingType::Election, null, null, new Quorum(9, 9), [], $clock->now()), ValidationException::class);
	Assert::same('candidateId', $e?->field);
});

test('tie and absent members: 4 for, 4 against of 8 present is rejected; not voting counts as not for', function (): void {
	[, $item, $people, $clock] = votingScene();
	$present = array_slice($people, 0, 8);
	$round = new VotingRound($item, VotingType::Proposal, 'Zastupitelstvo schvaluje rozpočet.', null, new Quorum(9, 8), [], $clock->now());
	vote($round, $present, [
		4 => VoteChoice::Against,
		5 => VoteChoice::Against,
		6 => VoteChoice::Against,
		7 => VoteChoice::Against,
	]);

	Assert::exception(fn() => $round->cast($people[8], VoteChoice::For, false), ValidationException::class, '%a%nebyl v okamžiku hlasování přítomen%a%');
	Assert::same(VotingResult::Rejected, $round->close(new VotingRule(9), $present, $clock->now()));
	Assert::same([4, 4, 0, 0], [$round->count(VoteChoice::For), $round->count(VoteChoice::Against), $round->count(VoteChoice::Abstain), $round->count(VoteChoice::NotVoting)]);
	Assert::exception(fn() => $round->cast($people[0], VoteChoice::For, true), InvalidStateException::class, '%a%ukončené%a%');

	$second = new VotingRound($item, VotingType::Proposal, 'Upravený rozpočet.', null, new Quorum(9, 8), [$round], $clock->now());
	vote($second, array_slice($present, 0, 5));
	$second->cast($present[0], VoteChoice::NotVoting, true);
	Assert::same(VotingResult::Rejected, $second->close(new VotingRule(9), $present, $clock->now()), 'jen 4 pro, zbytek nehlasoval');
	Assert::same(4, $second->count(VoteChoice::NotVoting));
	Assert::same(2, $second->getSequence());
});

test('counter-proposal adopted first: the original proposal is not voted on any more', function (): void {
	[, $item, $people, $clock] = votingScene();
	$counter = new VotingRound($item, VotingType::CounterProposal, 'Zastupitelstvo schvaluje rozpočet s rezervou 200 tis. Kč.', null, new Quorum(9, 9), [], $clock->now());
	Assert::exception(fn() => new VotingRound($item, VotingType::Proposal, 'Původní návrh', null, new Quorum(9, 9), [$counter], $clock->now()), InvalidStateException::class, '%a%už probíhá%a%');

	vote($counter, $people, [
		0 => VoteChoice::Against,
		1 => VoteChoice::Abstain,
	]);
	Assert::same(VotingResult::Adopted, $counter->close(new VotingRule(9), $people, $clock->now()));

	Assert::exception(fn() => new VotingRound($item, VotingType::Proposal, 'Původní návrh', null, new Quorum(9, 9), [$counter], $clock->now()), InvalidStateException::class, '%a%už bylo rozhodnuto%a%');
	Assert::noError(fn() => new VotingRound($item, VotingType::Election, null, $people[0], new Quorum(9, 9), [$counter], $clock->now()));
});

test('rejected counter-proposal lets the original proposal be voted; closed round can be corrected', function (): void {
	[$meeting, $item, $people, $clock] = votingScene();
	$counter = new VotingRound($item, VotingType::CounterProposal, 'Protinávrh', null, new Quorum(9, 9), [], $clock->now());
	vote($counter, array_slice($people, 0, 3));
	Assert::same(VotingResult::Rejected, $counter->close(new VotingRule(9), $people, $clock->now()));

	$proposal = new VotingRound($item, VotingType::Proposal, 'Původní návrh', null, new Quorum(9, 9), [$counter], $clock->now());
	vote($proposal, $people);
	Assert::same(VotingResult::Adopted, $proposal->close(new VotingRule(9), $people, $clock->now()));

	$proposal->reopen();
	Assert::true($proposal->isOpen());
	Assert::null($proposal->getResult());
	$proposal->cast($people[0], VoteChoice::Against, true);
	Assert::same(VotingResult::Adopted, $proposal->close(new VotingRule(9), $people, $clock->now()));
	Assert::same(8, $proposal->count(VoteChoice::For));

	$meeting->close($clock);
	$meeting->approveMinutes();
	Assert::exception(fn() => $proposal->reopen(), InvalidStateException::class, '%a%ověřen%a%');
});
