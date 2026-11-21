<?php

declare(strict_types=1);

namespace App\Features\Moderation;

use App\Features\Moderation\Api\ModerationActionHistory;
use App\Features\Moderation\Api\RecordModerationAction;
use App\Features\Moderation\Persistence\ModerationActionStore;
use App\Platform\FeatureModule;
use App\Features\Moderation\Contributors\ModerationAccountLifecycleContributor;
use App\Shared\Auth\AccountLifecycleContributor;

/**
 * Moderation owns recorded moderation actions and their account attribution.
 *
 * Event moderation transitions and cleanups call the published Api; the store
 * is the only class declared as a writer of the moderation_actions table.
 */
class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->tag([ModerationAccountLifecycleContributor::class], AccountLifecycleContributor::TAG);
        $this->app->bind(ModerationActionHistory::class, ModerationActionStore::class);
        $this->app->bind(RecordModerationAction::class, ModerationActionStore::class);
    }
}
