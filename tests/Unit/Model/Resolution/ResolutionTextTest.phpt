<?php

declare(strict_types=1);

use App\Model\Resolution\ResolutionText;
use Tester\Assert;

require __DIR__ . '/../../../bootstrap.php';

test('lead verb is split off in the style „Schvaluje: …“ / „Bere na vědomí – …“', function (): void {
	Assert::same(['Schvaluje', 'program jednání 2. zasedání ZO'], ResolutionText::split('Schvaluje – program jednání 2. zasedání ZO'));
	Assert::same(['Bere na vědomí', 'zápis kontrolního výboru'], ResolutionText::split('Bere na vědomí: zápis kontrolního výboru'));
	Assert::same(['Schvaluje', 'člena školské rady'], ResolutionText::split('schvaluje: člena školské rady'));
	Assert::same(['Pověřuje', 'starostu obce k podpisu'], ResolutionText::split('Pověřuje starostu obce k podpisu'));
	Assert::same([null, 'Zastupitelstvo obce Neplachovice schvaluje rozpočet.'], ResolutionText::split('Zastupitelstvo obce Neplachovice schvaluje rozpočet.'));
});
