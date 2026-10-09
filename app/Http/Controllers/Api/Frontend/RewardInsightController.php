<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Frontend;

use App\Support\Share\CommunityReleaseReader;
use App\Support\Share\ShareCohortAuthority;
use App\Support\Share\ShareMetricReader;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

#[Group('Rewards', 'Private impact metrics, cohort authority, and public community releases.')]
class RewardInsightController extends FrontendController
{
    public function impact(Request $request, ShareMetricReader $reader): JsonResponse
    {
        $user = $this->requireUser($request);

        $validated = $request->validate([
            'timezone' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $timezone = (string) ($validated['timezone'] ?? $user->preferredTimezone());
        $to = isset($validated['to']) ? CarbonImmutable::parse($validated['to']) : CarbonImmutable::now('UTC');
        $from = isset($validated['from']) ? CarbonImmutable::parse($validated['from']) : $to->subDays(30);

        try {
            $summary = $reader->summarize($timezone, $from, $to, $user);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $summary]);
    }

    public function cohort(Request $request, ShareCohortAuthority $authority): JsonResponse
    {
        $user = $this->requireUser($request);

        abort_unless($user->hasAnyRole(['super_admin', 'admin']), 403);

        $validated = $request->validate([
            'weeks' => ['nullable', 'integer', 'min:1', 'max:52'],
            'as_of' => ['nullable', 'date'],
        ]);

        try {
            $summary = $authority->summarize(
                isset($validated['as_of']) ? CarbonImmutable::parse($validated['as_of']) : CarbonImmutable::now('UTC'),
                (int) ($validated['weeks'] ?? 12),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $summary]);
    }

    public function community(CommunityReleaseReader $reader): JsonResponse
    {
        return response()->json(['data' => $reader->latest()]);
    }
}
