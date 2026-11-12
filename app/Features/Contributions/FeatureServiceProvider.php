<?php

declare(strict_types=1);

namespace App\Features\Contributions;

use App\Features\Contributions\Api\ContributionRequestReader;
use App\Features\Contributions\Queries\EloquentContributionRequestReader;
use App\Platform\FeatureModule;

class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(ContributionRequestReader::class, EloquentContributionRequestReader::class);
    }
}
