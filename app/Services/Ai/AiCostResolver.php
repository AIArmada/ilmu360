<?php

namespace App\Services\Ai;

use App\Models\AiModelPricing;
use Illuminate\Support\Str;

class AiCostResolver
{
    /**
     * @param  array{
     *     input_tokens: int|null,
     *     output_tokens: int|null,
     *     cache_write_input_tokens: int|null,
     *     cache_read_input_tokens: int|null,
     *     reasoning_tokens: int|null
     * }  $tokenData
     * @param  array<string, mixed>  $usagePayload
     * @param  array<string, mixed>  $context
     * @return array{
     *     cost_usd: float|null,
     *     source: string|null,
     *     reason: string|null,
     *     tier: string|null,
     *     pricing_id: string|null,
     *     pricing_snapshot: array<string, mixed>|null
     * }
     */
    public function resolve(
        string $operation,
        ?string $provider,
        ?string $model,
        array $tokenData,
        array $usagePayload = [],
        array $context = [],
    ): array {
        $providerReportedCost = $this->extractProviderReportedCost($usagePayload, $context);

        if ($providerReportedCost !== null) {
            return [
                'cost_usd' => round($providerReportedCost, 8),
                'source' => 'provider_reported',
                'reason' => null,
                'tier' => $this->extractModelTier($model),
                'pricing_id' => null,
                'pricing_snapshot' => null,
            ];
        }

        $matchedPricing = $this->resolvePricingFromCatalog(
            operation: $operation,
            provider: $provider,
            model: $model,
            context: $context,
        );

        if ($matchedPricing instanceof AiModelPricing) {
            $cost = $this->calculateCostFromRates(
                rates: [
                    'input_per_million' => $matchedPricing->input_per_million,
                    'output_per_million' => $matchedPricing->output_per_million,
                    'cache_write_input_per_million' => $matchedPricing->cache_write_input_per_million,
                    'cache_read_input_per_million' => $matchedPricing->cache_read_input_per_million,
                    'reasoning_per_million' => $matchedPricing->reasoning_per_million,
                    'per_request' => $matchedPricing->per_request,
                    'per_image' => $matchedPricing->per_image,
                    'per_audio_second' => $matchedPricing->per_audio_second,
                ],
                operation: $operation,
                tokenData: $tokenData,
                context: $context,
            );

            if ($cost !== null) {
                return [
                    'cost_usd' => round($cost, 8),
                    'source' => 'pricing_catalog',
                    'reason' => null,
                    'tier' => $this->extractModelTier($model),
                    'pricing_id' => (string) $matchedPricing->id,
                    'pricing_snapshot' => [
                        'provider' => $matchedPricing->provider,
                        'model_pattern' => $matchedPricing->model_pattern,
                        'operation' => $matchedPricing->operation,
                        'tier' => $matchedPricing->tier,
                        'priority' => $matchedPricing->priority,
                    ],
                ];
            }
        }

        $fallbackPricing = $this->resolvePricingFromConfig($provider, $model);

        if (is_array($fallbackPricing)) {
            $fallbackCost = $this->calculateCostFromRates(
                rates: $fallbackPricing,
                operation: $operation,
                tokenData: $tokenData,
                context: $context,
            );

            if ($fallbackCost !== null) {
                return [
                    'cost_usd' => round($fallbackCost, 8),
                    'source' => 'pricing_config_fallback',
                    'reason' => null,
                    'tier' => $this->extractModelTier($model),
                    'pricing_id' => null,
                    'pricing_snapshot' => [
                        'provider' => $provider,
                        'model' => $model,
                        'source' => 'config.ai.usage_tracking.pricing',
                    ],
                ];
            }
        }

        return [
            'cost_usd' => null,
            'source' => null,
            'reason' => 'pricing_not_configured',
            'tier' => $this->extractModelTier($model),
            'pricing_id' => null,
            'pricing_snapshot' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $usagePayload
     * @param  array<string, mixed>  $context
     */
    protected function extractProviderReportedCost(array $usagePayload, array $context): ?float
    {
        $paths = [
            'cost',
            'total_cost',
            'usage.cost',
            'usage.total_cost',
            'billing.cost',
            'billing.total_cost',
            'meta.cost',
            'meta.total_cost',
        ];

        foreach ($paths as $path) {
            $value = data_get($usagePayload, $path);

            if (! is_numeric($value)) {
                $value = data_get($context, $path);
            }

            if (is_numeric($value)) {
                return max((float) $value, 0.0);
            }
        }

        return null;
    }

    /**
     * @param  array{
     *     input_per_million?: mixed,
     *     output_per_million?: mixed,
     *     cache_write_input_per_million?: mixed,
     *     cache_read_input_per_million?: mixed,
     *     reasoning_per_million?: mixed,
     *     per_request?: mixed,
     *     per_image?: mixed,
     *     per_audio_second?: mixed
     * }  $rates
     * @param  array{
     *     input_tokens: int|null,
     *     output_tokens: int|null,
     *     cache_write_input_tokens: int|null,
     *     cache_read_input_tokens: int|null,
     *     reasoning_tokens: int|null
     * }  $tokenData
     * @param  array<string, mixed>  $context
     */
    protected function calculateCostFromRates(
        array $rates,
        string $operation,
        array $tokenData,
        array $context = [],
    ): ?float {
        $hasTokenValues = collect($tokenData)->contains(
            fn (mixed $value): bool => is_int($value) && $value > 0
        );

        $rateKeysByTokenType = [
            'input_tokens' => 'input_per_million',
            'output_tokens' => 'output_per_million',
            'cache_write_input_tokens' => 'cache_write_input_per_million',
            'cache_read_input_tokens' => 'cache_read_input_per_million',
            'reasoning_tokens' => 'reasoning_per_million',
        ];

        $cost = 0.0;
        $usedAnyRate = false;

        foreach ($rateKeysByTokenType as $tokenType => $rateKey) {
            $tokens = $tokenData[$tokenType];

            if (! is_int($tokens) || $tokens <= 0) {
                continue;
            }

            $rate = $rates[$rateKey] ?? null;

            if (! is_numeric($rate)) {
                continue;
            }

            $cost += ($tokens / 1_000_000) * (float) $rate;
            $usedAnyRate = true;
        }

        if (is_numeric($rates['per_request'] ?? null)) {
            $cost += (float) $rates['per_request'];
            $usedAnyRate = true;
        }

        if ($operation === 'image_generation' && is_numeric($rates['per_image'] ?? null)) {
            $imageCount = data_get($context, 'images_count');

            if (is_numeric($imageCount) && (int) $imageCount > 0) {
                $cost += ((int) $imageCount) * (float) $rates['per_image'];
                $usedAnyRate = true;
            }
        }

        if ($operation === 'audio_generation' && is_numeric($rates['per_audio_second'] ?? null)) {
            $durationSeconds = data_get($context, 'audio_duration_seconds');

            if (is_numeric($durationSeconds) && (float) $durationSeconds > 0) {
                $cost += ((float) $durationSeconds) * (float) $rates['per_audio_second'];
                $usedAnyRate = true;
            }
        }

        if (! $usedAnyRate && ! $hasTokenValues) {
            return null;
        }

        if (! $usedAnyRate && $hasTokenValues) {
            return null;
        }

        return $cost;
    }

    protected function extractModelTier(?string $model): ?string
    {
        if (! is_string($model) || $model === '') {
            return null;
        }

        $lastSegment = Str::afterLast($model, ':');

        if ($lastSegment === $model) {
            return null;
        }

        if (str_contains($lastSegment, '/')) {
            return null;
        }

        if (preg_match('/^[a-zA-Z0-9._-]{1,40}$/', $lastSegment) !== 1) {
            return null;
        }

        return strtolower($lastSegment);
    }

    protected function modelWithoutTier(?string $model): ?string
    {
        if (! is_string($model) || $model === '') {
            return null;
        }

        $tier = $this->extractModelTier($model);

        if ($tier === null) {
            return $model;
        }

        return Str::beforeLast($model, ':');
    }

    protected function normalizeProvider(?string $provider): ?string
    {
        if (! is_string($provider) || $provider === '') {
            return null;
        }

        return strtolower(trim($provider));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function resolvePricingFromCatalog(
        string $operation,
        ?string $provider,
        ?string $model,
        array $context = [],
    ): ?AiModelPricing {
        $normalizedProvider = $this->normalizeProvider($provider);
        $normalizedModel = is_string($model) ? trim($model) : null;
        $modelWithoutTier = $this->modelWithoutTier($normalizedModel);
        $tier = data_get($context, 'tier');

        if (! is_string($tier) || $tier === '') {
            $tier = $this->extractModelTier($normalizedModel);
        }

        $query = AiModelPricing::query()->activeAt(now())
            ->whereIn('provider', array_filter([$normalizedProvider, '*']))
            ->whereIn('operation', [$operation, '*'])
            ->orderBy('priority');

        if ($tier !== null) {
            $query->whereIn('tier', [$tier, '*', null]);
        } else {
            $query->where(fn ($nested) => $nested->whereNull('tier')->orWhere('tier', '*'));
        }

        $candidates = $query->get();

        $best = null;
        $bestScore = -INF;

        foreach ($candidates as $candidate) {
            $score = $this->scorePricingCandidate(
                candidate: $candidate,
                provider: $normalizedProvider,
                model: $normalizedModel,
                modelWithoutTier: $modelWithoutTier,
                operation: $operation,
                tier: $tier,
            );

            if ($score > $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return $best;
    }

    protected function scorePricingCandidate(
        AiModelPricing $candidate,
        ?string $provider,
        ?string $model,
        ?string $modelWithoutTier,
        string $operation,
        ?string $tier,
    ): float {
        $providerScore = match (true) {
            $provider !== null && $candidate->provider === $provider => 1000,
            $candidate->provider === '*' => 100,
            default => -INF,
        };

        if (! is_finite($providerScore)) {
            return -INF;
        }

        $modelPattern = $candidate->model_pattern;
        $modelMatchedExactly = false;
        $modelMatched = false;

        foreach (array_filter([$model, $modelWithoutTier]) as $candidateModel) {
            if (! is_string($candidateModel) || $candidateModel === '') {
                continue;
            }

            if ($modelPattern === $candidateModel) {
                $modelMatched = true;
                $modelMatchedExactly = true;
                break;
            }

            if (Str::is($modelPattern, $candidateModel)) {
                $modelMatched = true;
            }
        }

        if ($modelPattern === '*') {
            $modelMatched = true;
        }

        if (! $modelMatched) {
            return -INF;
        }

        $modelScore = match (true) {
            $modelMatchedExactly => 500,
            $modelPattern === '*' => 80,
            str_contains($modelPattern, '*') => 300,
            default => 250,
        };

        $operationScore = match (true) {
            $candidate->operation === $operation => 140,
            $candidate->operation === '*' => 40,
            default => -INF,
        };

        if (! is_finite($operationScore)) {
            return -INF;
        }

        $tierScore = match (true) {
            $tier !== null && $candidate->tier === $tier => 140,
            $candidate->tier === '*' || $candidate->tier === null => 40,
            default => -INF,
        };

        if (! is_finite($tierScore)) {
            return -INF;
        }

        $priorityBonus = max(0, 200 - (int) $candidate->priority);

        return $providerScore + $modelScore + $operationScore + $tierScore + $priorityBonus;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolvePricingFromConfig(?string $provider, ?string $model): ?array
    {
        $pricing = config('ai.usage_tracking.pricing', []);

        if (! is_array($pricing) || $pricing === []) {
            return null;
        }

        $normalizedProvider = $this->normalizeProvider($provider);
        $modelWithoutTier = $this->modelWithoutTier($model);
        $tier = $this->extractModelTier($model);

        foreach (array_filter([$normalizedProvider, '*']) as $providerKey) {
            $providerPricing = $pricing[$providerKey] ?? null;

            if (! is_array($providerPricing)) {
                continue;
            }

            foreach (array_filter([$model, $modelWithoutTier, '*']) as $modelKey) {
                $matchedRates = $providerPricing[$modelKey] ?? null;

                if (! is_array($matchedRates)) {
                    foreach ($providerPricing as $pattern => $rates) {
                        if (! is_string($pattern) || ! is_array($rates)) {
                            continue;
                        }

                        if ($model !== null && Str::is($pattern, $model)) {
                            $matchedRates = $rates;
                            break;
                        }

                        if ($modelWithoutTier !== null && Str::is($pattern, $modelWithoutTier)) {
                            $matchedRates = $rates;
                            break;
                        }
                    }
                }

                if (! is_array($matchedRates)) {
                    continue;
                }

                if ($tier !== null && isset($matchedRates[$tier]) && is_array($matchedRates[$tier])) {
                    return $matchedRates[$tier];
                }

                return $matchedRates;
            }
        }

        return null;
    }
}
