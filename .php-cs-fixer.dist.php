<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in([
        __DIR__ . '/app',
        __DIR__ . '/system',
        __DIR__ . '/tests',
    ])
    ->exclude(['Views'])
    ->name('*.php')
    ->append([__DIR__ . '/index.php']);

return (new Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => false,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
        'no_trailing_whitespace' => true,
        'blank_line_before_statement' => ['statements' => ['return']],
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__ . '/storage/.php-cs-fixer.cache');
