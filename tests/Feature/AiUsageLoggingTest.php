<?php

use App\Models\AiModelPricing;
use App\Models\AiUsageLog;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Gateway\Gateway;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\EmbeddingsGenerated;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\EmbeddingsResponse;

it('records token usage and cost for prompted agents using pricing catalog', function () {
    config()->set('ai.usage_tracking.enabled', true);

    $pricing = AiModelPricing::query()->create([
        'provider' => 'openai',
        'model_pattern' => 'gpt-test',
        'operation' => 'agent_prompt',
        'currency' => 'USD',
        'input_per_million' => 2.0,
        'output_per_million' => 6.0,
        'cache_write_input_per_million' => 1.0,
        'cache_read_input_per_million' => 0.5,
        'reasoning_per_million' => 3.0,
        'is_active' => true,
        'priority' => 10,
    ]);

    $agent = Mockery::mock(Agent::class);
    $provider = Mockery::mock(TextProvider::class);

    $prompt = new AgentPrompt(
        agent: $agent,
        prompt: 'Extract all details from this event poster.',
        attachments: [],
        provider: $provider,
        model: 'gpt-test',
    );

    $response = new AgentResponse(
        invocationId: 'inv-agent-1',
        text: 'Structured output',
        usage: new Usage(
            promptTokens: 1000,
            completionTokens: 500,
            cacheWriteInputTokens: 100,
            cacheReadInputTokens: 50,
            reasoningTokens: 25,
        ),
        meta: new Meta(provider: 'openai', model: 'gpt-test'),
    );

    event(new AgentPrompted(
        invocationId: 'inv-agent-1',
        prompt: $prompt,
        response: $response,
    ));

    $usageLog = AiUsageLog::query()->sole();

    expect($usageLog->operation)->toBe('agent_prompt')
        ->and($usageLog->provider)->toBe('openai')
        ->and($usageLog->model)->toBe('gpt-test')
        ->and($usageLog->input_tokens)->toBe(1000)
        ->and($usageLog->output_tokens)->toBe(500)
        ->and($usageLog->cache_write_input_tokens)->toBe(100)
        ->and($usageLog->cache_read_input_tokens)->toBe(50)
        ->and($usageLog->reasoning_tokens)->toBe(25)
        ->and($usageLog->total_tokens)->toBe(1675)
        ->and($usageLog->cost_usd)->toBe('0.00520000')
        ->and($usageLog->meta)->toMatchArray([
            'event_class' => AgentPrompted::class,
            'attachments_count' => 0,
            'cost_source' => 'pricing_catalog',
            'pricing_id' => $pricing->id,
            'has_usage_data' => true,
        ]);
});

it('prefers tier-specific pricing when model includes a tier suffix', function () {
    config()->set('ai.usage_tracking.enabled', true);

    $defaultPricing = AiModelPricing::query()->create([
        'provider' => 'openrouter',
        'model_pattern' => 'deepseek/deepseek-chat-v3-0324*',
        'operation' => 'agent_prompt',
        'tier' => '*',
        'currency' => 'USD',
        'input_per_million' => 5.0,
        'output_per_million' => 10.0,
        'is_active' => true,
        'priority' => 50,
    ]);

    $freeTierPricing = AiModelPricing::query()->create([
        'provider' => 'openrouter',
        'model_pattern' => 'deepseek/deepseek-chat-v3-0324*',
        'operation' => 'agent_prompt',
        'tier' => 'free',
        'currency' => 'USD',
        'input_per_million' => 0.8,
        'output_per_million' => 1.2,
        'is_active' => true,
        'priority' => 10,
    ]);

    $agent = Mockery::mock(Agent::class);
    $provider = Mockery::mock(TextProvider::class);

    $prompt = new AgentPrompt(
        agent: $agent,
        prompt: 'Summarize this event',
        attachments: [],
        provider: $provider,
        model: 'deepseek/deepseek-chat-v3-0324:free',
    );

    $response = new AgentResponse(
        invocationId: 'inv-tier-1',
        text: 'Summary',
        usage: new Usage(
            promptTokens: 1000,
            completionTokens: 500,
        ),
        meta: new Meta(provider: 'openrouter', model: 'deepseek/deepseek-chat-v3-0324:free'),
    );

    event(new AgentPrompted(
        invocationId: 'inv-tier-1',
        prompt: $prompt,
        response: $response,
    ));

    $usageLog = AiUsageLog::query()->sole();

    expect($usageLog->cost_usd)->toBe('0.00140000')
        ->and($usageLog->meta)->toMatchArray([
            'cost_source' => 'pricing_catalog',
            'detected_tier' => 'free',
            'pricing_id' => $freeTierPricing->id,
        ])
        ->and($usageLog->meta['pricing_id'])->not->toBe($defaultPricing->id);
});

it('records embedding usage even when pricing is not configured', function () {
    config()->set('ai.usage_tracking.enabled', true);

    $gateway = Mockery::mock(Gateway::class);
    $eventsDispatcher = app('events');

    $provider = new class($gateway, ['name' => 'openai', 'driver' => 'openai', 'key' => 'test-key'], $eventsDispatcher) extends Provider {};

    $prompt = new EmbeddingsPrompt(
        inputs: ['majlis ilmu', 'kelas daurah'],
        dimensions: 1536,
        provider: Mockery::mock(EmbeddingProvider::class),
        model: 'text-embedding-test',
    );

    $response = new EmbeddingsResponse(
        embeddings: [[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]],
        tokens: 321,
        meta: new Meta(provider: 'openai', model: 'text-embedding-test'),
    );

    event(new EmbeddingsGenerated(
        invocationId: 'inv-embed-1',
        provider: $provider,
        model: 'text-embedding-test',
        prompt: $prompt,
        response: $response,
    ));

    $usageLog = AiUsageLog::query()->sole();

    expect($usageLog->operation)->toBe('embeddings_generation')
        ->and($usageLog->provider)->toBe('openai')
        ->and($usageLog->model)->toBe('text-embedding-test')
        ->and($usageLog->input_tokens)->toBe(321)
        ->and($usageLog->output_tokens)->toBe(0)
        ->and($usageLog->total_tokens)->toBe(321)
        ->and($usageLog->cost_usd)->toBeNull()
        ->and($usageLog->meta)->toMatchArray([
            'event_class' => EmbeddingsGenerated::class,
            'inputs_count' => 2,
            'dimensions' => 1536,
            'cost_unavailable_reason' => 'pricing_not_configured',
            'detected_tier' => null,
            'has_usage_data' => true,
        ]);
});

it('does not record usage logs when ai usage tracking is disabled', function () {
    config()->set('ai.usage_tracking.enabled', false);

    $agent = Mockery::mock(Agent::class);
    $provider = Mockery::mock(TextProvider::class);

    $prompt = new AgentPrompt(
        agent: $agent,
        prompt: 'Summarize this event data.',
        attachments: [],
        provider: $provider,
        model: 'gpt-test',
    );

    $response = new AgentResponse(
        invocationId: 'inv-agent-2',
        text: 'Summary',
        usage: new Usage(promptTokens: 50, completionTokens: 20),
        meta: new Meta(provider: 'openai', model: 'gpt-test'),
    );

    event(new AgentPrompted(
        invocationId: 'inv-agent-2',
        prompt: $prompt,
        response: $response,
    ));

    expect(AiUsageLog::query()->count())->toBe(0);
});
