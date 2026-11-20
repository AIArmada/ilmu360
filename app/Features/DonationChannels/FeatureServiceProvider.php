<?php

declare(strict_types=1);

namespace App\Features\DonationChannels;

use App\Features\DonationChannels\Api\DonationChannelLookup;
use App\Features\DonationChannels\Api\DonationChannelWorkflow;
use App\Features\DonationChannels\Api\DonationQrMedia;
use App\Features\DonationChannels\Api\Filament\DonationChannelAdminComponents;
use App\Features\DonationChannels\EntryPoints\Admin\Resources\DonationChannelMutationHooks;
use App\Features\DonationChannels\EntryPoints\Api\Admin\DonationChannelResourceDefinition;
use App\Features\DonationChannels\EntryPoints\Filament\Resources\DonationChannels\DonationChannelAdminComponentsAdapter;
use App\Features\DonationChannels\EntryPoints\Filament\Resources\DonationChannels\DonationChannelResource;
use App\Features\DonationChannels\Queries\EloquentDonationChannelLookup;
use App\Features\DonationChannels\Services\DonationChannelWorkflowService;
use App\Features\DonationChannels\Services\DonationQrMediaService;
use App\Platform\Admin\FilamentResourceRegistry;
use App\Platform\Api\Admin\AdminMutationHookRegistry;
use App\Platform\Api\Resources\ResourceDefinitionRegistry;
use App\Platform\FeatureModule;

class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(DonationChannelAdminComponents::class, DonationChannelAdminComponentsAdapter::class);
        $this->app->bind(DonationChannelWorkflow::class, DonationChannelWorkflowService::class);
        $this->app->bind(DonationQrMedia::class, DonationQrMediaService::class);
        $this->app->bind(DonationChannelLookup::class, EloquentDonationChannelLookup::class);

        $this->app->make(FilamentResourceRegistry::class)->register(DonationChannelResource::class);

        $this->app->make(AdminMutationHookRegistry::class)->register($this->app->make(DonationChannelMutationHooks::class));
    }

    public function boot(): void
    {
        parent::boot();

        $this->app->make(ResourceDefinitionRegistry::class)->register($this->app->make(DonationChannelResourceDefinition::class));
    }
}
