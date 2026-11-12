<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/examples', __DIR__ . '/tests', __DIR__ . '/tools', __DIR__ . '/bin'])
    ->exclude('.runtime')
    ->name('*.php')
    ->name('form-guard');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules(['@PSR12' => true, 'declare_strict_types' => false])
    ->setCacheFile(__DIR__ . '/build/php-cs-fixer.cache')
    ->setFinder($finder);
