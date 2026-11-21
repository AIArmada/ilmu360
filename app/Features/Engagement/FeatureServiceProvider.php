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
 * Engagement owns bookmarks, follows and event responses. Lifecycle adapters
 * delegate persistence to Actions, and public command interfaces compose
 * the package-backed engagement capabilities.
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
