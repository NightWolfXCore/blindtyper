<?php

declare(strict_types=1);

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'binary_operator_spaces' => ['default' => 'single_space'],
        'cast_spaces' => ['space' => 'single'],
        'concat_space' => ['spacing' => 'one'],
        'class_attributes_separation' => ['elements' => ['method' => 'one']],
        'method_argument_space' => ['on_multiline' => 'ensure_fully_multiline'],
        'no_multiple_statements_per_line' => true,
        'no_unused_imports' => true,
        'single_import_per_statement' => true,
        'space_after_semicolon' => true,
        'trim_array_spaces' => true,
        'whitespace_after_comma_in_array' => true,
    ])
    ->setCacheFile(__DIR__ . '/storage/php-cs-fixer.cache')
    ->setFinder(PhpCsFixer\Finder::create()->in([
        __DIR__ . '/src', __DIR__ . '/public', __DIR__ . '/config',
        __DIR__ . '/bin', __DIR__ . '/tests',
    ]));
