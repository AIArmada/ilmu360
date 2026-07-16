<?php

use App\Models\AiUsageLog;
use App\Models\User;
use App\Services\Ai\AiBudgetPolicy;

it('allows low-cost AI work within the monthly budget', function () {
    config()->set('ai.budget.monthly_limit_usd', 100.0);
    config()->set('ai.budget.privileged_approval_threshold_usd', 10.0);

    $decision = app(AiBudgetPolicy::class)->decide('embeddings_generation', 'openai', 'text-embedding-3-small', 1.25);

    expect($decision->decision)->toBe('allow')
        ->and($decision->reasonCode)->toBe('within_budget');
});

it('requires privileged approval for expensive work', function () {
    $decision = app(AiBudgetPolicy::class)->decide('image_generation', 'openai', 'gpt-image-2', 12.0, User::factory()->create());

    expect($decision->decision)->toBe('privileged_approval')
        ->and($decision->reasonCode)->toBe('cost_requires_privileged_approval');
});

it('denies work that would exceed the monthly budget', function () {
    config()->set('ai.budget.monthly_limit_usd', 10.0);

    AiUsageLog::query()->create([
        'invocation_id' => 'budget-test-existing',
        'operation' => 'image_generation',
        'provider' => 'openai',
        'model' => 'gpt-image-2',
        'cost_usd' => 9.50,
        'currency' => 'USD',
    ]);

    $decision = app(AiBudgetPolicy::class)->decide('image_generation', 'openai', 'gpt-image-2', 1.0);

    expect($decision->decision)->toBe('deny')
        ->and($decision->reasonCode)->toBe('monthly_budget_exceeded');
});
