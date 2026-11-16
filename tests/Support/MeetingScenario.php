<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Model\Meeting\AgendaFacade;
use App\Model\Meeting\AgendaItem;
use App\Model\Meeting\Command\SaveAgendaItem;
use App\Model\Meeting\Command\SaveMeeting;
use App\Model\Meeting\Meeting;
use App\Model\Meeting\MeetingFacade;
use App\Model\Meeting\MeetingType;
use App\Model\Municipality\Municipality;
use App\Model\People\BodyType;
use App\Model\People\Command\SaveBody;
use App\Model\People\PeopleFacade;
use Nette\DI\Container;

/**
 * Obec se zastupitelstvem, jedním zasedáním a bodem programu – společný základ testů nad programem.
 */
final readonly class MeetingScenario
{
	public function __construct(
		public Municipality $municipality,
		public Meeting $meeting,
		public AgendaItem $item,
	) {}

	public static function create(Container $container, ?Municipality $municipality = null): self
	{
		$municipality ??= (new Fixtures($container))->municipality();
		$people = $container->getByType(PeopleFacade::class);
		$term = $people->createTerm($municipality->getId(), '2026–2030', new \DateTimeImmutable('2026-10-03'));
		$council = $people->createBody($term->getId(), new SaveBody(BodyType::Council, "Zastupitelstvo obce {$municipality->getName()}", 5));
		$meeting = $container->getByType(MeetingFacade::class)->create($council->getId(), new SaveMeeting(MeetingType::Regular, new \DateTimeImmutable('2026-12-09 18:00'), 'Obecní dům'));
		$item = $container->getByType(AgendaFacade::class)->addItem($meeting->getId(), new SaveAgendaItem('Rozpočet na rok 2027'));

		return new self($municipality, $meeting, $item);
	}

	/**
	 * Další zasedání téhož orgánu s jedním bodem.
	 */
	public function another(Container $container, string $scheduledAt = '2027-01-20 18:00'): self
	{
		$meeting = $container->getByType(MeetingFacade::class)->create($this->meeting->getBody()->getId(), new SaveMeeting(MeetingType::Regular, new \DateTimeImmutable($scheduledAt), 'Obecní dům'));
		$item = $container->getByType(AgendaFacade::class)->addItem($meeting->getId(), new SaveAgendaItem('Různé'));

		return new self($this->municipality, $meeting, $item);
	}
}
