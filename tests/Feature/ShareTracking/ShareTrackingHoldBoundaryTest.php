<?php

declare(strict_types=1);

use App\Features\ShareTracking\Models\ShareCohortProjection;
use App\Features\ShareTracking\Queries\ShareMetricReader;
use App\Features\ShareTracking\Services\Analytics\AdminShareAnalyticsService;
use App\Features\ShareTracking\Services\Analytics\AffiliatesShareTrackingAnalyticsService;
use App\Features\ShareTracking\Services\ShareCohortAuthority;
use App\Features\ShareTracking\Services\ShareProfileScope;
use App\Features\ShareTracking\Services\ShareTrackingAnalyticsService;
use App\Features\ShareTracking\Support\ShareMetricDefinitions;
use App\Features\ShareTracking\Support\ShareTrackingMeasurement;
use App\Features\ShareTracking\Support\ShareTrackingRetention;
use Carbon\CarbonImmutable;

it('keeps the analytics readers with their owning feature', function (string $class, string $relativePath): void {
    $file = new ReflectionClass($class)->getFileName();

    expect(realpath((string) $file))->toBe(realpath(base_path($relativePath)));
})->with([
    'user analytics' => [ShareTrackingAnalyticsService::class, 'app/Features/ShareTracking/Services/ShareTrackingAnalyticsService.php'],
    'affiliate analytics' => [AffiliatesShareTrackingAnalyticsService::class, 'app/Features/ShareTracking/Services/Analytics/AffiliatesShareTrackingAnalyticsService.php'],
    'admin analytics' => [AdminShareAnalyticsService::class, 'app/Features/ShareTracking/Services/Analytics/AdminShareAnalyticsService.php'],
    'metric reader' => [ShareMetricReader::class, 'app/Features/ShareTracking/Queries/ShareMetricReader.php'],
    'measurement contract' => [ShareTrackingMeasurement::class, 'app/Features/ShareTracking/Support/ShareTrackingMeasurement.php'],
    'profile scope' => [ShareProfileScope::class, 'app/Features/ShareTracking/Services/ShareProfileScope.php'],
    'cohort authority' => [ShareCohortAuthority::class, 'app/Features/ShareTracking/Services/ShareCohortAuthority.php'],
]);

it('keeps every named metric unit distinct and versioned', function (): void {
    expect(ShareMetricDefinitions::VERSION)->toBe('v1')
        ->and(array_keys(ShareMetricDefinitions::all()))->toBe([
            'submitted_shares',
            'recorded_shares',
            'recorded_visits',
            'distinct_sharing_browsers',
            'share_intensity',
            'accepted_participations',
            'verified_participations',
            'first_share_conversion',
            'participation_conversion',
        ])
        ->and(array_values(array_map(
            static fn (array $definition): string => $definition['unit'],
            ShareMetricDefinitions::all(),
        )))->toBe([
            'shares',
            'shares',
            'visits',
            'browsers',
            'shares_per_browser',
            'participations',
            'participations',
            'ratio',
            'ratio',
        ])
        ->and(ShareMetricDefinitions::unit('recorded_shares'))->toBe('shares')
        ->and(ShareMetricDefinitions::unit('unknown_metric'))->toBe('unknown');
});

it('keeps abandoned guest-profile retention parameterized at 90 days', function (): void {
    expect(ShareTrackingRetention::GUEST_PROFILE_DAYS)->toBe(90)
        ->and(config('share-tracking.guest_profile.retention_days'))->toBe(ShareTrackingRetention::GUEST_PROFILE_DAYS);
});

it('freezes cohort projection identity and allows only observed to participated', function (): void {
    $projection = ShareCohortProjection::query()->create([
        'business_key' => 'committed-share:retention-hold',
        'outcome' => 'committed_share',
        'cohort_week' => '2026-07-27',
        'stage' => ShareCohortProjection::STAGE_OBSERVED,
        'occurred_at' => CarbonImmutable::parse('2026-07-28 12:00:00', 'UTC'),
        'projected_at' => CarbonImmutable::parse('2026-07-28 12:05:00', 'UTC'),
    ]);

    foreach ([
        'business_key' => 'committed-share:rewritten',
        'outcome' => 'rewritten',
        'cohort_week' => '2026-08-03',
        'occurred_at' => CarbonImmutable::parse('2026-07-29 12:00:00', 'UTC'),
    ] as $field => $value) {
        expect(fn () => $projection->fresh()?->forceFill([$field => $value])->save())
            ->toThrow(LogicException::class, "Share cohort projection [{$field}] is frozen.");
    }

    expect(fn () => $projection->fresh()?->forceFill(['stage' => ShareCohortProjection::STAGE_PARTICIPATED])->save())
        ->not->toThrow(LogicException::class)
        ->and(fn () => $projection->fresh()?->forceFill(['stage' => ShareCohortProjection::STAGE_OBSERVED])->save())->toThrow(LogicException::class, 'Share cohort projection stages move observed -> participated only.')
        ->and(fn () => $projection->fresh()?->delete())->toThrow(LogicException::class, 'Share cohort projections cannot be deleted.');
});
