<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Enable Blaze
    |--------------------------------------------------------------------------
    |
    | When set to false, Blaze skips optimization and components render
    | through the default Blade pipeline.
    |
    */

    'enabled' => env('BLAZE_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Debug Mode
    |--------------------------------------------------------------------------
    |
    | Enables Blaze debug tooling (overlay + profiler routes).
    | Keep disabled in production environments.
    |
    */

    'debug' => env('BLAZE_DEBUG', false),
];
