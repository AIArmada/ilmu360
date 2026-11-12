<?php

declare(strict_types=1);

use Pest\Rector\Set\PestSetList;
use Rector\Config\RectorConfig;
use RectorLaravel\Rector\Class_\FillablePropertyToFillableAttributeRector;
use RectorLaravel\Rector\Class_\HiddenPropertyToHiddenAttributeRector;
use RectorLaravel\Rector\Class_\TablePropertyToTableAttributeRector;
use RectorLaravel\Rector\Class_\WithoutIncrementingPropertyToWithoutIncrementingAttributeRector;
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
        // Keep literal model table/fillable declarations: the ownership
        // scanner and project conventions depend on explicit $table values.
        TablePropertyToTableAttributeRector::class,
        FillablePropertyToFillableAttributeRector::class,
        HiddenPropertyToHiddenAttributeRector::class,
        WithoutIncrementingPropertyToWithoutIncrementingAttributeRector::class,
    ])
    ->withBootstrapFiles([
        __DIR__.'/vendor/autoload.php',
        __DIR__.'/bootstrap/app.php',
    ])
    ->withParallel(timeoutSeconds: 600)
    ->withSets([
        LaravelSetList::LARAVEL_130,
        PestSetList::CODING_STYLE,
    ])
    ->withPreparedSets(codeQuality: true, deadCode: true)
    ->withPhpSets();
