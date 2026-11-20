<?php

declare(strict_types=1);

namespace App\Features\Community;

use App\Features\Community\EntryPoints\Api\Admin\CommunityReleaseResourceDefinition;
use App\Features\Community\EntryPoints\Console\PublishCommunityReleaseCommand;
use App\Features\Community\EntryPoints\Filament\Resources\CommunityReleases\CommunityReleaseResource;
use App\Features\Community\Models\CommunityRelease;
use App\Features\Community\Policies\CommunityReleasePolicy;
use App\Platform\Admin\FilamentResourceRegistry;
use App\Platform\Api\Resources\ResourceDefinitionRegistry;
use App\Platform\FeatureModule;
use Illuminate\Support\Facades\Gate;

final class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->commands([PublishCommunityReleaseCommand::class]);
        $this->app->make(FilamentResourceRegistry::class)->register(CommunityReleaseResource::class);
    }

    public function boot(): void
    {
        $this->app->make(ResourceDefinitionRegistry::class)->register($this->app->make(CommunityReleaseResourceDefinition::class));
        parent::boot();

        Gate::policy(CommunityRelease::class, CommunityReleasePolicy::class);
    }
}
