<?php

declare(strict_types=1);

namespace App\Features\SavedSearches;

use App\Features\SavedSearches\Api\SavedSearchCommands as SavedSearchCommandsContract;
use App\Features\SavedSearches\Api\SavedSearchReader;
use App\Features\SavedSearches\Api\SavedSearchSnapshot;
use App\Features\SavedSearches\Models\SavedSearch;
use App\Features\SavedSearches\Policies\SavedSearchPolicy;
use App\Features\SavedSearches\Queries\DatabaseSavedSearchReader;
use App\Features\SavedSearches\Services\SavedSearchCommands;
use App\Platform\FeatureModule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(SavedSearchReader::class, DatabaseSavedSearchReader::class);
        $this->app->bind(SavedSearchCommandsContract::class, SavedSearchCommands::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(SavedSearch::class, SavedSearchPolicy::class);
        Gate::policy(SavedSearchSnapshot::class, SavedSearchPolicy::class);

        Route::bind('savedSearch', static function (string $id): SavedSearchSnapshot {
            return app(SavedSearchReader::class)->find($id)
                ?? throw (new ModelNotFoundException)->setModel(SavedSearch::class, [$id]);
        });
    }
}
