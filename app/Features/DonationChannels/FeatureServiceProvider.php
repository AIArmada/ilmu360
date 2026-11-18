<?php

declare(strict_types=1);

namespace App\Features\DonationChannels;

use App\Features\DonationChannels\EntryPoints\Filament\Resources\DonationChannels\DonationChannelResource;
use App\Platform\Admin\FilamentResourceRegistry;

use App\Features\DonationChannels\Api\DonationChannelLookup;
use App\Features\DonationChannels\Api\DonationChannelWorkflow;
use App\Features\DonationChannels\Api\DonationQrMedia;
use App\Features\DonationChannels\Queries\EloquentDonationChannelLookup;
use App\Features\DonationChannels\Services\DonationChannelWorkflowService;
use App\Features\DonationChannels\Services\DonationQrMediaService;
use App\Platform\FeatureModule;

class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(DonationChannelWorkflow::class, DonationChannelWorkflowService::class);
        $this->app->bind(DonationQrMedia::class, DonationQrMediaService::class);
        $this->app->bind(DonationChannelLookup::class, EloquentDonationChannelLookup::class);

        $this->app->make(FilamentResourceRegistry::class)->register(DonationChannelResource::class);
    }
}
