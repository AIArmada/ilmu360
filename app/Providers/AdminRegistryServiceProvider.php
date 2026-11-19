<?php

declare(strict_types=1);

namespace App\Providers;

use App\Platform\Admin\FilamentPageRegistry;
use App\Platform\Admin\FilamentResourceRegistry;
use App\Platform\Admin\FilamentWidgetRegistry;
use App\Platform\Api\Admin\AdminMutationHookRegistry;
use App\Platform\Api\Admin\AdminResourceHookRegistry;
use App\Platform\Api\Member\MemberResourceHookRegistry;
use App\Platform\Auditing\EntryPoints\Filament\Resources\Audits\AuditResource;
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
        $this->app->singleton(FilamentPageRegistry::class);
        $this->app->singleton(FilamentWidgetRegistry::class);
        $this->app->singleton(AdminMutationHookRegistry::class);
        $this->app->singleton(MemberResourceHookRegistry::class);
        $this->app->singleton(AdminResourceHookRegistry::class);

        $this->app->make(FilamentResourceRegistry::class)->register(AuditResource::class);
    }
}
