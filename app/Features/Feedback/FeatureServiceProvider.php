<?php

declare(strict_types=1);

namespace App\Features\Feedback;

use App\Features\Feedback\Api\AppFeedbackReader;
use App\Features\Feedback\EntryPoints\Filament\Resources\AppFeedback\AppFeedbackResource;
use App\Features\Feedback\Models\AppFeedback;
use App\Features\Feedback\Policies\AppFeedbackPolicy;
use App\Features\Feedback\Queries\EloquentAppFeedbackReader;
use App\Features\Feedback\Support\Telemetry\FeedbackSignalSchemaContributor;
use App\Platform\Admin\FilamentResourceRegistry;
use App\Platform\FeatureModule;
use App\Platform\Telemetry\SignalSchemaRegistry;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;

class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->singleton(AppFeedbackReader::class, EloquentAppFeedbackReader::class);

        $this->app->make(FilamentResourceRegistry::class)->register(AppFeedbackResource::class);
    }

    public function boot(): void
    {
        parent::boot();

        $this->app->make(SignalSchemaRegistry::class)->register(new FeedbackSignalSchemaContributor);

        Gate::policy(AppFeedback::class, AppFeedbackPolicy::class);
        Relation::morphMap(['app_feedback' => AppFeedback::class]);
    }
}
