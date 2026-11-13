<?php

declare(strict_types=1);

use App\Models\ShareCohortProjection;
use App\Services\ShareTracking\AdminShareAnalyticsService;
use App\Services\ShareTracking\AffiliatesShareTrackingAnalyticsService;
use App\Services\ShareTrackingAnalyticsService;
use App\Shared\ShareTracking\ShareTrackingRetention;
use App\Support\Share\CommunityReleaseReader;
use App\Support\Share\ShareCohortAuthority;
use App\Support\Share\ShareMetricDefinitions;
use App\Support\Share\ShareMetricReader;
use App\Support\Share\ShareProfileScope;
use App\Support\ShareTracking\ShareTrackingMeasurement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('keeps the analytics readers as documented root adapters', function (string $class, string $relativePath): void {
    $file = (new ReflectionClass($class))->getFileName();

    expect(realpath((string) $file))->toBe(realpath(base_path($relativePath)))
        ->and((string) $file)->not->toStartWith(app_path('Features/ShareTracking'));
})->with([
    'user analytics' => [ShareTrackingAnalyticsService::class, 'app/Services/ShareTrackingAnalyticsService.php'],
    'affiliate analytics' => [AffiliatesShareTrackingAnalyticsService::class, 'app/Services/ShareTracking/AffiliatesShareTrackingAnalyticsService.php'],
    'admin analytics' => [AdminShareAnalyticsService::class, 'app/Services/ShareTracking/AdminShareAnalyticsService.php'],
    'metric reader' => [ShareMetricReader::class, 'app/Support/Share/ShareMetricReader.php'],
    'measurement contract' => [ShareTrackingMeasurement::class, 'app/Support/ShareTracking/ShareTrackingMeasurement.php'],
    'profile scope' => [ShareProfileScope::class, 'app/Support/Share/ShareProfileScope.php'],
    'cohort authority' => [ShareCohortAuthority::class, 'app/Support/Share/ShareCohortAuthority.php'],
    'community releases' => [CommunityReleaseReader::class, 'app/Support/Share/CommunityReleaseReader.php'],
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
        ->not->toThrow(LogicException::class);

    expect(fn () => $projection->fresh()?->forceFill(['stage' => ShareCohortProjection::STAGE_OBSERVED])->save())
        ->toThrow(LogicException::class, 'Share cohort projection stages move observed -> participated only.');

    expect(fn () => $projection->fresh()?->delete())
        ->toThrow(LogicException::class, 'Share cohort projections cannot be deleted.');
});
