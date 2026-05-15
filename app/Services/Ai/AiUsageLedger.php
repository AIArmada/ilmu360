<?php

namespace App\Services\Ai;

use App\Models\AiUsageLog;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\AudioGenerated;
use Laravel\Ai\Events\EmbeddingsGenerated;
use Laravel\Ai\Events\ImageGenerated;
use Laravel\Ai\Events\Reranked;
use Laravel\Ai\Events\TranscriptionGenerated;

class AiUsageLedger
{
    public function __construct(protected AiCostResolver $aiCostResolver) {}

    public function record(
        AgentPrompted|AgentStreamed|ImageGenerated|TranscriptionGenerated|EmbeddingsGenerated|Reranked|AudioGenerated $event
    ): void {
        if (! (bool) config('ai.usage_tracking.enabled', true)) {
            return;
        }

        if ($event instanceof AgentPrompted) {
            $payload = $this->payloadFromAgentPrompted($event);
        } elseif ($event instanceof ImageGenerated) {
            $payload = $this->payloadFromImageGenerated($event);
        } elseif ($event instanceof TranscriptionGenerated) {
            $payload = $this->payloadFromTranscriptionGenerated($event);
        } elseif ($event instanceof EmbeddingsGenerated) {
            $payload = $this->payloadFromEmbeddingsGenerated($event);
        } elseif ($event instanceof Reranked) {
            $payload = $this->payloadFromReranked($event);
        } else {
            $payload = $this->payloadFromAudioGenerated($event);
        }

        AiUsageLog::query()->create($payload);
    }

    /**
     * @return array<string, mixed>
     */
    protected function payloadFromAgentPrompted(AgentPrompted $event): array
    {
        $usage = $event->response->usage;
        $usagePayload = $usage->toArray();

        return $this->buildPayload(
            invocationId: $event->invocationId,
            operation: $event instanceof AgentStreamed ? 'agent_stream' : 'agent_prompt',
            provider: $this->normalizeProvider($event->response->meta->provider, null),
            model: $this->normalizeModel($event->response->meta->model, $event->prompt->model),
            tokenData: [
                'input_tokens' => $usage->promptTokens,
                'output_tokens' => $usage->completionTokens,
                'cache_write_input_tokens' => $usage->cacheWriteInputTokens,
                'cache_read_input_tokens' => $usage->cacheReadInputTokens,
                'reasoning_tokens' => $usage->reasoningTokens,
            ],
            usagePayload: $usagePayload,
            meta: [
                'event_class' => $event::class,
                'attachments_count' => $event->prompt->attachments->count(),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function payloadFromImageGenerated(ImageGenerated $event): array
    {
        $usage = $event->response->usage;
        $usagePayload = $usage->toArray();

        return $this->buildPayload(
            invocationId: $event->invocationId,
            operation: 'image_generation',
            provider: $this->normalizeProvider($event->response->meta->provider, $event->provider->name()),
            model: $this->normalizeModel($event->response->meta->model, $event->model),
            tokenData: [
                'input_tokens' => $usage->promptTokens,
                'output_tokens' => $usage->completionTokens,
                'cache_write_input_tokens' => $usage->cacheWriteInputTokens,
                'cache_read_input_tokens' => $usage->cacheReadInputTokens,
                'reasoning_tokens' => $usage->reasoningTokens,
            ],
            usagePayload: $usagePayload,
            meta: [
                'event_class' => $event::class,
                'images_count' => $event->response->count(),
                'requested_size' => $event->prompt->size,
                'requested_quality' => $event->prompt->quality,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function payloadFromTranscriptionGenerated(TranscriptionGenerated $event): array
    {
        $usage = $event->response->usage;
        $usagePayload = $usage->toArray();

        return $this->buildPayload(
            invocationId: $event->invocationId,
            operation: 'transcription_generation',
            provider: $this->normalizeProvider($event->response->meta->provider, $event->provider->name()),
            model: $this->normalizeModel($event->response->meta->model, $event->model),
            tokenData: [
                'input_tokens' => $usage->promptTokens,
                'output_tokens' => $usage->completionTokens,
                'cache_write_input_tokens' => $usage->cacheWriteInputTokens,
                'cache_read_input_tokens' => $usage->cacheReadInputTokens,
                'reasoning_tokens' => $usage->reasoningTokens,
            ],
            usagePayload: $usagePayload,
            meta: [
                'event_class' => $event::class,
                'language' => $event->prompt->language,
                'diarize' => $event->prompt->diarize,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function payloadFromEmbeddingsGenerated(EmbeddingsGenerated $event): array
    {
        $usagePayload = [
            'prompt_tokens' => $event->response->tokens,
            'completion_tokens' => 0,
        ];

        return $this->buildPayload(
            invocationId: $event->invocationId,
            operation: 'embeddings_generation',
            provider: $this->normalizeProvider($event->response->meta->provider, $event->provider->name()),
            model: $this->normalizeModel($event->response->meta->model, $event->model),
            tokenData: [
                'input_tokens' => $event->response->tokens,
                'output_tokens' => 0,
                'cache_write_input_tokens' => 0,
                'cache_read_input_tokens' => 0,
                'reasoning_tokens' => 0,
            ],
            usagePayload: $usagePayload,
            meta: [
                'event_class' => $event::class,
                'inputs_count' => count($event->prompt->inputs),
                'dimensions' => $event->prompt->dimensions,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function payloadFromReranked(Reranked $event): array
    {
        return $this->buildPayload(
            invocationId: $event->invocationId,
            operation: 'reranking',
            provider: $this->normalizeProvider($event->response->meta->provider, $event->provider->name()),
            model: $this->normalizeModel($event->response->meta->model, $event->model),
            tokenData: [
                'input_tokens' => null,
                'output_tokens' => null,
                'cache_write_input_tokens' => null,
                'cache_read_input_tokens' => null,
                'reasoning_tokens' => null,
            ],
            usagePayload: [],
            meta: [
                'event_class' => $event::class,
                'documents_count' => count($event->prompt->documents),
                'results_count' => $event->response->count(),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function payloadFromAudioGenerated(AudioGenerated $event): array
    {
        return $this->buildPayload(
            invocationId: $event->invocationId,
            operation: 'audio_generation',
            provider: $this->normalizeProvider($event->response->meta->provider, $event->provider->name()),
            model: $this->normalizeModel($event->response->meta->model, $event->model),
            tokenData: [
                'input_tokens' => null,
                'output_tokens' => null,
                'cache_write_input_tokens' => null,
                'cache_read_input_tokens' => null,
                'reasoning_tokens' => null,
            ],
            usagePayload: [],
            meta: [
                'event_class' => $event::class,
                'voice' => $event->prompt->voice,
                'mime' => $event->response->mimeType(),
            ],
        );
    }

    /**
     * @param  array{
     *     input_tokens: int|null,
     *     output_tokens: int|null,
     *     cache_write_input_tokens: int|null,
     *     cache_read_input_tokens: int|null,
     *     reasoning_tokens: int|null
     * }  $tokenData
     * @param  array<string, mixed>  $usagePayload
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    protected function buildPayload(
        string $invocationId,
        string $operation,
        ?string $provider,
        ?string $model,
        array $tokenData,
        array $usagePayload,
        array $meta,
    ): array {
        $normalizedTokenData = $this->normalizeTokenData($tokenData);
        $totalTokens = $this->calculateTotalTokens($normalizedTokenData);
        $tier = $this->extractTierFromModel($model);
        $costResolution = $this->aiCostResolver->resolve(
            operation: $operation,
            provider: $provider,
            model: $model,
            tokenData: $normalizedTokenData,
            usagePayload: $usagePayload,
            context: array_merge($meta, ['tier' => $tier]),
        );

        if (is_string($costResolution['source'])) {
            $meta['cost_source'] = $costResolution['source'];
        }

        if (is_string($costResolution['reason'])) {
            $meta['cost_unavailable_reason'] = $costResolution['reason'];
        }

        if (is_array($costResolution['pricing_snapshot'])) {
            $meta['pricing_snapshot'] = $costResolution['pricing_snapshot'];
        }

        if (is_string($costResolution['pricing_id'])) {
            $meta['pricing_id'] = $costResolution['pricing_id'];
        }

        $meta['detected_tier'] = $costResolution['tier'];

        $meta['has_usage_data'] = collect($normalizedTokenData)->contains(
            fn (mixed $value): bool => is_int($value)
        );

        $meta['usage_payload'] = $usagePayload;

        return [
            'invocation_id' => $invocationId,
            'operation' => $operation,
            'provider' => $provider,
            'model' => $model,
            'input_tokens' => $normalizedTokenData['input_tokens'],
            'output_tokens' => $normalizedTokenData['output_tokens'],
            'cache_write_input_tokens' => $normalizedTokenData['cache_write_input_tokens'],
            'cache_read_input_tokens' => $normalizedTokenData['cache_read_input_tokens'],
            'reasoning_tokens' => $normalizedTokenData['reasoning_tokens'],
            'total_tokens' => $totalTokens,
            'cost_usd' => $costResolution['cost_usd'],
            'currency' => $this->currency(),
            'user_id' => $this->resolveUserId(),
            'meta' => $meta,
        ];
    }

    /**
     * @param  array{
     *     input_tokens: int|null,
     *     output_tokens: int|null,
     *     cache_write_input_tokens: int|null,
     *     cache_read_input_tokens: int|null,
     *     reasoning_tokens: int|null
     * }  $tokenData
     * @return array{
     *     input_tokens: int|null,
     *     output_tokens: int|null,
     *     cache_write_input_tokens: int|null,
     *     cache_read_input_tokens: int|null,
     *     reasoning_tokens: int|null
     * }
     */
    protected function normalizeTokenData(array $tokenData): array
    {
        return [
            'input_tokens' => $this->normalizeTokenValue($tokenData['input_tokens']),
            'output_tokens' => $this->normalizeTokenValue($tokenData['output_tokens']),
            'cache_write_input_tokens' => $this->normalizeTokenValue($tokenData['cache_write_input_tokens']),
            'cache_read_input_tokens' => $this->normalizeTokenValue($tokenData['cache_read_input_tokens']),
            'reasoning_tokens' => $this->normalizeTokenValue($tokenData['reasoning_tokens']),
        ];
    }

    protected function normalizeTokenValue(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return max((int) $value, 0);
    }

    /**
     * @param  array{
     *     input_tokens: int|null,
     *     output_tokens: int|null,
     *     cache_write_input_tokens: int|null,
     *     cache_read_input_tokens: int|null,
     *     reasoning_tokens: int|null
     * }  $tokenData
     */
    protected function calculateTotalTokens(array $tokenData): ?int
    {
        $presentTokens = collect($tokenData)
            ->filter(fn (mixed $value): bool => is_int($value));

        if ($presentTokens->isEmpty()) {
            return null;
        }

        return $presentTokens->sum();
    }

    protected function normalizeProvider(?string $providerFromMeta, ?string $fallbackProvider): ?string
    {
        $provider = $providerFromMeta ?? $fallbackProvider;

        if (! is_string($provider) || $provider === '') {
            return null;
        }

        return strtolower(trim($provider));
    }

    protected function normalizeModel(?string $modelFromMeta, ?string $fallbackModel): ?string
    {
        $model = $modelFromMeta ?? $fallbackModel;

        if (! is_string($model) || $model === '') {
            return null;
        }

        return trim($model);
    }

    protected function currency(): string
    {
        $configuredCurrency = config('ai.usage_tracking.currency', 'USD');

        if (! is_string($configuredCurrency) || $configuredCurrency === '') {
            return 'USD';
        }

        return strtoupper(trim($configuredCurrency));
    }

    protected function resolveUserId(): ?string
    {
        $userId = auth()->id();

        if (! is_string($userId) || $userId === '') {
            return null;
        }

        return $userId;
    }

    protected function extractTierFromModel(?string $model): ?string
    {
        if (! is_string($model) || $model === '') {
            return null;
        }

        $tierCandidate = str($model)->afterLast(':')->toString();

        if ($tierCandidate === $model || str_contains($tierCandidate, '/')) {
            return null;
        }

        if (preg_match('/^[a-zA-Z0-9._-]{1,40}$/', $tierCandidate) !== 1) {
            return null;
        }

        return strtolower($tierCandidate);
    }
}
