<?php

declare(strict_types=1);

namespace App\Features\Inspirations;

use App\Features\Inspirations\Api\InspirationAdminContract;
use App\Features\Inspirations\Api\InspirationReader;
use App\Features\Inspirations\EntryPoints\Api\Admin\InspirationResourceDefinition;
use App\Features\Inspirations\EntryPoints\Filament\Resources\Inspirations\InspirationResource;
use App\Features\Inspirations\Models\Inspiration;
use App\Features\Inspirations\Policies\InspirationPolicy;
use App\Features\Inspirations\Queries\DatabaseInspirationReader;
use App\Features\Inspirations\Services\InspirationAdmin;
use App\Platform\Admin\FilamentResourceRegistry;
use App\Platform\Api\Resources\ResourceDefinitionRegistry;
use App\Platform\FeatureModule;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;

class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(InspirationReader::class, DatabaseInspirationReader::class);
        $this->app->bind(InspirationAdminContract::class, InspirationAdmin::class);

        $this->app->make(FilamentResourceRegistry::class)->register(InspirationResource::class);
    }

    public function boot(): void
    {
        $this->app->make(ResourceDefinitionRegistry::class)->register($this->app->make(InspirationResourceDefinition::class));
        parent::boot();

        Gate::policy(Inspiration::class, InspirationPolicy::class);
        Relation::morphMap(['inspiration' => Inspiration::class]);
    }
}
