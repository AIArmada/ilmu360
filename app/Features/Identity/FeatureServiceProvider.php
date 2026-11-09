<?php

declare(strict_types=1);

namespace App\Features\Identity;

use App\Features\Identity\Api\AccountLifecycle;
use App\Features\Identity\Contributors\ReportsAccountLifecycleContributor;
use App\Features\Identity\Contributors\SavedSearchesAccountLifecycleContributor;
use App\Features\Identity\Services\AccountLifecycleCoordinator;
use App\Platform\Auth\IdentityAccountLifecycleContributor;
use App\Platform\FeatureModule;
use App\Shared\Auth\AccountLifecycleContributor;
use Illuminate\Contracts\Foundation\Application;

class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->tag([
            IdentityAccountLifecycleContributor::class,
            ReportsAccountLifecycleContributor::class,
            SavedSearchesAccountLifecycleContributor::class,
        ], AccountLifecycleContributor::TAG);

        $this->app->bind(AccountLifecycle::class, static fn (Application $app): AccountLifecycleCoordinator => new AccountLifecycleCoordinator(
            $app->tagged(AccountLifecycleContributor::TAG),
        ));
    }
}
