<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use RectorLaravel\Set\LaravelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/bootstrap',
        __DIR__.'/config',
        __DIR__.'/database',
        __DIR__.'/routes',
        __DIR__.'/tests',
    ])
    ->withSkip([
        __DIR__.'/bootstrap/cache',
        __DIR__.'/storage',
        __DIR__.'/vendor',
        __DIR__.'/node_modules',
    ])
    ->withBootstrapFiles([
        __DIR__.'/vendor/autoload.php',
        __DIR__.'/bootstrap/app.php',
    ])
    ->withParallel(timeoutSeconds: 600)
    ->withSets([
        LaravelSetList::LARAVEL_120,
    ])
    ->withPreparedSets(codeQuality: true, deadCode: true)
    ->withPhpSets();
