<?php

declare(strict_types=1);

namespace App\Features\Referrals;

use App\Features\Referrals\Actions\ResolveReferralInviteLandingAction;
use App\Features\Referrals\Api\ReferralInvitations;
use App\Features\Referrals\Api\ReferralOutcomeWorkflow;
use App\Features\Referrals\Services\ReferralOutcomeService;
use App\Features\Referrals\Support\Telemetry\ReferralsSignalSchemaContributor;
use App\Platform\FeatureModule;
use App\Platform\Telemetry\SignalSchemaRegistry;

/** Referral business orchestration; package owns all programme, invite and reward tables. */
final class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(ReferralInvitations::class, ResolveReferralInviteLandingAction::class);
        $this->app->bind(ReferralOutcomeWorkflow::class, ReferralOutcomeService::class);
    }

    public function boot(): void
    {
        parent::boot();

        $this->app->make(SignalSchemaRegistry::class)->register(new ReferralsSignalSchemaContributor);
    }
}
