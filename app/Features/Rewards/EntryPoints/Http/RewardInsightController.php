<?php

declare(strict_types=1);

namespace App\Features\Rewards\EntryPoints\Http;

use App\Features\ShareTracking\Api\ShareAnalytics;
use App\Platform\Http\FrontendController;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

#[Group('Rewards', 'Private impact and cohort metrics.')]
class RewardInsightController extends FrontendController
{
    #[Endpoint(title: 'Get your reward impact summary')]
    public function impact(Request $request, ShareAnalytics $analytics): JsonResponse
    {
        $user = $this->requireAccountActor($request);

        $validated = $request->validate([
            'timezone' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $timezone = (string) ($validated['timezone'] ?? $this->accountTimezone($user));
        $to = isset($validated['to']) ? CarbonImmutable::parse($validated['to']) : CarbonImmutable::now('UTC');
        $from = isset($validated['from']) ? CarbonImmutable::parse($validated['from']) : $to->subDays(30);

        try {
            $summary = $analytics->memberSummary($user->actorId(), $timezone, $from, $to);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $summary]);
    }

    #[Endpoint(title: 'Get reward cohort metrics for administrators')]
    public function cohort(Request $request, ShareAnalytics $analytics): JsonResponse
    {
        $user = $this->requireAccountActor($request);

        abort_unless($user->hasAnyRole(['super_admin', 'admin']), 403);

        $validated = $request->validate([
            'weeks' => ['nullable', 'integer', 'min:1', 'max:52'],
            'as_of' => ['nullable', 'date'],
        ]);

        try {
            $summary = $analytics->cohortSummary(
                isset($validated['as_of']) ? CarbonImmutable::parse($validated['as_of']) : CarbonImmutable::now('UTC'),
                (int) ($validated['weeks'] ?? 12),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $summary]);
    }
}
