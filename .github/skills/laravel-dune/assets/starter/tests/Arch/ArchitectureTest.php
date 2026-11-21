<?php

declare(strict_types=1);

arch('strict types across Platform and Feature modules')
    ->expect(['App\Platform', 'App\Features', 'App\Host', 'App\Shared'])
    ->toUseStrictTypes();

arch('no debug helpers or imperative sleep hacks in application code')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'sleep', 'usleep', 'env'])
    ->not->toBeUsedIn('App');

arch('Platform core never depends on product Features or Host')
    ->expect('App\Platform')
    ->not->toUse(['App\Features', 'App\Host']);

arch('Host never depends on concrete Features')
    ->expect('App\Host')
    ->not->toUse('App\Features');

arch('Shared contracts stay pure and framework-free')
    ->expect('App\Shared\Contracts')
    ->not->toUse(['App\Features', 'App\Host', 'Illuminate']);
