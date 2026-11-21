<?php

declare(strict_types=1);

arch('strict types across package source')
    ->expect('__PACKAGE_NAMESPACE__')
    ->toUseStrictTypes();

arch('no debugging helpers, env(), or imperative sleep in package source')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'sleep', 'usleep', 'env'])
    ->each->not->toBeUsedIn('__PACKAGE_NAMESPACE__');

arch('package source never imports host application code')
    ->expect('__PACKAGE_NAMESPACE__')
    ->not->toUse('App');

arch('public Contracts and Data stay decoupled from Actions, Models, and Drivers')
    ->expect(['__PACKAGE_NAMESPACE__\Contracts', '__PACKAGE_NAMESPACE__\Data'])
    ->not->toUse([
        '__PACKAGE_NAMESPACE__\Actions',
        '__PACKAGE_NAMESPACE__\Models',
        '__PACKAGE_NAMESPACE__\Drivers',
        '__PACKAGE_NAMESPACE__\Features',
        'Illuminate\Database\Eloquent',
        'Illuminate\Http',
    ]);
