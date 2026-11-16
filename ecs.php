<?php

declare(strict_types=1);

use PhpCsFixer\Fixer\ClassNotation\VisibilityRequiredFixer;
use PhpCsFixer\Fixer\FunctionNotation\VoidReturnFixer;
use PhpCsFixer\Fixer\Import\NoUnusedImportsFixer;
use PhpCsFixer\Fixer\Import\OrderedImportsFixer;
use PhpCsFixer\Fixer\Strict\DeclareStrictTypesFixer;
use PhpCsFixer\Fixer\Strict\StrictComparisonFixer;
use PhpCsFixer\Fixer\Strict\StrictParamFixer;
use Symplify\EasyCodingStandard\Config\ECSConfig;
use Symplify\EasyCodingStandard\ValueObject\Option;

return ECSConfig::configure()
	->withPaths([
		__DIR__ . '/app',
		__DIR__ . '/bin',
		__DIR__ . '/migrations',
		__DIR__ . '/tests',
		__DIR__ . '/www',
	])
	->withRootFiles()
	->withFileExtensions(['php', 'phpt'])
	->withSpacing(Option::INDENTATION_TAB, "\n")
	->withPreparedSets(perCs: true, common: true, cleanCode: true)
	->withRules([
		DeclareStrictTypesFixer::class,
		NoUnusedImportsFixer::class,
		StrictComparisonFixer::class,
		StrictParamFixer::class,
		VoidReturnFixer::class,
		VisibilityRequiredFixer::class,
	])
	->withConfiguredRule(OrderedImportsFixer::class, [
		'imports_order' => ['class', 'function', 'const'],
		'sort_algorithm' => 'alpha',
	])
	->withSkip([
		__DIR__ . '/temp',
		__DIR__ . '/tests/**/output',
	]);
