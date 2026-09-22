<?php

declare(strict_types=1);

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'declare_strict_types' => true,
        'protected_to_private' => false,
        'php_unit_test_case_static_method_calls' => ['call_type' => 'this'],
    ])
    ->setRiskyAllowed(true)
    ->setFinder((new PhpCsFixer\Finder())->in([__DIR__.'/src', __DIR__.'/tests'])->append([__FILE__, __DIR__.'/castor.php']));
