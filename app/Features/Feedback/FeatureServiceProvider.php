<?php

declare(strict_types=1);

namespace App\Features\Feedback;

use App\Features\Feedback\EntryPoints\Filament\Resources\AppFeedback\AppFeedbackResource;
use App\Features\Feedback\Models\AppFeedback;
use App\Features\Feedback\Policies\AppFeedbackPolicy;
use App\Platform\Admin\FilamentResourceRegistry;
use App\Platform\FeatureModule;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;

class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->make(FilamentResourceRegistry::class)->register(AppFeedbackResource::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(AppFeedback::class, AppFeedbackPolicy::class);
        Relation::morphMap(['app_feedback' => AppFeedback::class]);
    }
}
