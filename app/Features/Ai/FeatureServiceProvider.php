<?php

declare(strict_types=1);

namespace App\Features\Ai;

use App\Features\Ai\Api\AiBudget;
use App\Features\Ai\Contributors\AiAccountLifecycleContributor;
use App\Features\Ai\EntryPoints\Filament\Resources\AiModelPricings\AiModelPricingResource;
use App\Features\Ai\EntryPoints\Filament\Resources\AiUsageLogs\AiUsageLogResource;
use App\Features\Ai\Listeners\RecordAiUsage;
use App\Features\Ai\Models\AiModelPricing;
use App\Features\Ai\Policies\AiModelPricingPolicy;
use App\Features\Ai\Services\AiBudgetPolicy;
use App\Platform\Admin\FilamentResourceRegistry;
use App\Platform\FeatureModule;
use App\Shared\Auth\AccountLifecycleContributor;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\AudioGenerated;
use Laravel\Ai\Events\EmbeddingsGenerated;
use Laravel\Ai\Events\ImageGenerated;
use Laravel\Ai\Events\Reranked;
use Laravel\Ai\Events\TranscriptionGenerated;

class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(AiBudget::class, AiBudgetPolicy::class);
        $this->app->tag([
            AiAccountLifecycleContributor::class,
        ], AccountLifecycleContributor::TAG);

        $registry = $this->app->make(FilamentResourceRegistry::class);
        $registry->register(AiModelPricingResource::class);
        $registry->register(AiUsageLogResource::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(AiModelPricing::class, AiModelPricingPolicy::class);
        Relation::morphMap([
            'ai_model_pricing' => AiModelPricing::class,
        ]);

        if (! $this->app->bound('ai.usage.listeners.registered')) {
            Event::listen(AgentPrompted::class, [RecordAiUsage::class, 'handle']);
            Event::listen(AgentStreamed::class, [RecordAiUsage::class, 'handle']);
            Event::listen(ImageGenerated::class, [RecordAiUsage::class, 'handle']);
            Event::listen(TranscriptionGenerated::class, [RecordAiUsage::class, 'handle']);
            Event::listen(EmbeddingsGenerated::class, [RecordAiUsage::class, 'handle']);
            Event::listen(Reranked::class, [RecordAiUsage::class, 'handle']);
            Event::listen(AudioGenerated::class, [RecordAiUsage::class, 'handle']);

            $this->app->instance('ai.usage.listeners.registered', true);
        }
    }
}
