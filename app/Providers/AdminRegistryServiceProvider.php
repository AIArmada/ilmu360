<?php

declare(strict_types=1);

namespace App\Providers;

use App\Platform\Admin\FilamentResourceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the platform admin registry before feature providers register, so
 * features can contribute Filament resources ahead of panel construction.
 */
class AdminRegistryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FilamentResourceRegistry::class);
    }
}
