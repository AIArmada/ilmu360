<?php

declare(strict_types=1);

namespace App\Features\SlugRedirects;

use AIArmada\CommerceSupport\Contracts\SlugRedirectRecorder;
use App\Features\SlugRedirects\Api\SlugRedirectAdministration;
use App\Features\SlugRedirects\Api\SlugRedirectCommands;
use App\Features\SlugRedirects\Api\SlugRedirectLookup;
use App\Features\SlugRedirects\EntryPoints\Filament\Resources\SlugRedirects\SlugRedirectResource;
use App\Features\SlugRedirects\Integration\CanonicalSlugRedirectRecorder;
use App\Features\SlugRedirects\Models\SlugRedirect;
use App\Features\SlugRedirects\Policies\SlugRedirectPolicy;
use App\Features\SlugRedirects\Queries\DatabaseSlugRedirectLookup;
use App\Features\SlugRedirects\Services\SlugRedirectAdministrationService;
use App\Features\SlugRedirects\Services\SlugRedirectCommandsService;
use App\Platform\Admin\FilamentResourceRegistry;
use App\Platform\FeatureModule;
use Illuminate\Support\Facades\Gate;

final class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(SlugRedirectCommands::class, SlugRedirectCommandsService::class);
        $this->app->bind(SlugRedirectAdministration::class, SlugRedirectAdministrationService::class);
        $this->app->bind(SlugRedirectLookup::class, DatabaseSlugRedirectLookup::class);
        $this->app->bind(SlugRedirectRecorder::class, CanonicalSlugRedirectRecorder::class);
        $this->app->make(FilamentResourceRegistry::class)->register(SlugRedirectResource::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(SlugRedirect::class, SlugRedirectPolicy::class);
    }
}
