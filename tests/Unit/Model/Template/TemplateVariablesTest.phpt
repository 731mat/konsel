<?php

declare(strict_types=1);

use App\Model\Meeting\Meeting;
use App\Model\Meeting\MeetingType;
use App\Model\Municipality\Municipality;
use App\Model\People\Body;
use App\Model\People\BodyType;
use App\Model\People\Term;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Template\DefaultMeetingTemplates;
use App\Model\Template\DefaultResolutionTemplates;
use App\Model\Template\MeetingTemplate;
use App\Model\Template\MeetingTemplateItem;
use App\Model\Template\ResolutionTemplate;
use App\Model\Template\TemplateVariables;
use Tester\Assert;

require __DIR__ . '/../../../bootstrap.php';

function templateMeeting(): Meeting
{
	$municipality = new Municipality('Neplachovice', 'neplachovice');
	$term = new Term($municipality, '2026–2030', new DateTimeImmutable('2026-10-01'));

	return new Meeting(new Body($term, BodyType::Council, 'Zastupitelstvo obce Neplachovice', 9), 4, MeetingType::Regular, new DateTimeImmutable('2026-12-09 18:00'), 'Obecní dům');
}

test('variables are substituted, unknown placeholders stay', function (): void {
	$text = '{organ} schvaluje program {cislo_zasedani}. zasedání dne {datum_zasedani} ({obec}, {rok}) {neznama}';

	Assert::same(
		'Zastupitelstvo obce Neplachovice schvaluje program 4. zasedání dne 9. 12. 2026 (Neplachovice, 2026) {neznama}',
		TemplateVariables::apply($text, templateMeeting()),
	);
});

test('default libraries are valid entities', function (): void {
	$municipality = new Municipality('Neplachovice', 'neplachovice');
	foreach (DefaultResolutionTemplates::all() as [$category, $title, $text]) {
		Assert::noError(fn() => new ResolutionTemplate($municipality, $title, $category, $text));
	}

	$names = [];
	foreach (DefaultMeetingTemplates::all() as [$name, $type, $items, $note]) {
		$template = new MeetingTemplate($municipality, $name, $type, $items, $note);
		$names[] = $template->getName();
		Assert::same(count($items), count($template->getItems()));
	}

	Assert::count(3, $names);
	Assert::true(count(DefaultResolutionTemplates::all()) >= 15);
});

test('template validation', function (): void {
	$municipality = new Municipality('Neplachovice', 'neplachovice');

	$e = Assert::exception(fn() => new ResolutionTemplate($municipality, 'X', ' ', 'Text'), ValidationException::class);
	Assert::same('category', $e?->field);
	$e = Assert::exception(fn() => new MeetingTemplate($municipality, '', MeetingType::Regular, [new MeetingTemplateItem('A')]), ValidationException::class);
	Assert::same('name', $e?->field);
});
