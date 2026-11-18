<?php

declare(strict_types=1);

namespace App\Features\Engagement;

use App\Features\Engagement\Actions\DeleteEventEngagementAction;
use App\Features\Engagement\Api\EventEngagementDeletion;
use App\Features\Engagement\Api\EventGoingCommands;
use App\Features\Engagement\Api\EventSaveCommands;
use App\Features\Engagement\Api\SubjectFollowCommands;
use App\Features\Engagement\Contributors\EngagementAccountLifecycleContributor;
use App\Features\Engagement\Services\EventGoingCommandsService;
use App\Features\Engagement\Services\EventSaveCommandsService;
use App\Features\Engagement\Services\SubjectFollowCommandsService;
use App\Platform\FeatureModule;
use App\Shared\Auth\AccountLifecycleContributor;

/**
 * Engagement is the future owner of follow, save and going behaviour over the
 * package engagement tables. Those tables and the package
 * DefaultEngagementManager are package-canonical, so this manifest claims
 * nothing (`owns: {}`) and adds no columns or parallel stores.
 *
 * D4a added the read-side seams only: `EventEngagement`/`SubjectFollows`
 * expose immutable scalar state. Save writers remain at the root until migrated. D4b added the
 * (`App\Actions\Engagement\SaveEventAction`/`UnsaveEventAction`), D4c the
 * going writers (`MarkEventGoingAction`/`RemoveEventGoingAction`) now owned here plus the
 * shared `GoingWindow` eligibility, and D4d the follow writers
 * (`FollowSubjectAction`/`UnfollowSubjectAction`). D4e routes every save,
 * going and follow share outcome through the root `EngagementShareOutcomes`
 * recorder; the package manager remains the canonical writer and callers
 * never reproduce its orchestration. Going commands are exposed through the
 * EventGoingCommands feature API; remaining save/follow writers are separate slices.
 * Event deletion cleanup is Engagement-owned through `EventEngagementDeletion`;
 * it deletes active bookmarks and going responses through package models,
 * without claiming their package tables.
 * Account deletion/restoration state contributes through the shared Identity
 * lifecycle coordinator; its contributor is registered by this feature.
 *
 * HTTP/Livewire entrypoints (the API controllers, card components and page
 * traits that call the named engagement actions) remain root adapters.
 * Share outcome reversal remains ShareTracking's contract; D4 composes it from
 * the engagement actions rather than moving it in.
 */
class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(EventEngagementDeletion::class, DeleteEventEngagementAction::class);
        $this->app->bind(EventGoingCommands::class, EventGoingCommandsService::class);
        $this->app->bind(EventSaveCommands::class, EventSaveCommandsService::class);
        $this->app->bind(SubjectFollowCommands::class, SubjectFollowCommandsService::class);

        $this->app->tag([
            EngagementAccountLifecycleContributor::class,
        ], AccountLifecycleContributor::TAG);
    }

}
