<?php

declare(strict_types=1);

namespace App\Features\Identity;

use App\Features\Identity\Api\AccountLifecycle;
use App\Features\Identity\Services\AccountLifecycleCoordinator;
use App\Platform\FeatureModule;
use App\Shared\Auth\AccountLifecycleContributor;
use Illuminate\Contracts\Foundation\Application;

class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(AccountLifecycle::class, static fn (Application $app): AccountLifecycleCoordinator => new AccountLifecycleCoordinator(
            $app->tagged(AccountLifecycleContributor::TAG),
        ));
    }
}
