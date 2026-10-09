<?php

declare(strict_types=1);

namespace App\Services\ShareTracking;

use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateAttribution;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateLink;
use AIArmada\Affiliates\Models\AffiliateTouchpoint;
use AIArmada\CommerceSupport\Support\OwnerScope;
use App\Models\User;
use App\Services\ShareTrackingService;
use App\Support\Share\ShareMetricReader;
use App\Support\ShareTracking\ShareTrackingMeasurement;
use App\Support\ShareTracking\ShareTrackingProfile;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @phpstan-type ShareAnalyticsSummary array{
 *     affiliates: int,
 *     shared_links: int,
 *     outbound_shares: int,
 *     visits: int,
 *     distinct_browsers: int,
 *     signups: int,
 *     event_registrations: int,
 *     event_checkins: int,
 *     event_submissions: int,
 *     total_outcomes: int
 * }
 * @phpstan-type ShareProviderBreakdownRow array{
 *     provider: string,
 *     label: string,
 *     outbound_shares: int,
 *     visits: int,
 *     distinct_browsers: int,
 *     outcomes: int,
 *     signups: int
 * }
 * @phpstan-type ShareTopSharerRow array{
 *     affiliate_id: string,
 *     affiliate_code: string,
 *     user_name: string,
 *     user_email: string|null,
 *     links: int,
 *     visits: int,
 *     distinct_browsers: int,
 *     outcomes: int,
 *     signups: int,
 *     event_registrations: int,
 *     last_activity_at: string|null
 * }
 * @phpstan-type ShareTopLinkRow array{
 *     link_id: string,
 *     title_snapshot: string|null,
 *     subject_type: string,
 *     destination_url: string,
 *     affiliate_code: string|null,
 *     sharer_name: string|null,
 *     outbound_shares: int,
 *     visits: int,
 *     distinct_browsers: int,
 *     outcomes: int,
 *     last_shared_at: string|null
 * }
 * @phpstan-type ShareRecentVisitRow array{
 *     subject: string,
 *     provider: string,
 *     visit_kind: string,
 *     channel: string|null,
 *     sharer_name: string|null,
 *     occurred_at: string|null
 * }
 * @phpstan-type ShareRecentOutcomeRow array{
 *     conversion_type: string,
 *     subject_type: string,
 *     title_snapshot: string,
 *     sharer_name: string|null,
 *     occurred_at: string|null
 * }
 * @phpstan-type ShareAnalyticsDashboard array{
 *     summary: ShareAnalyticsSummary,
 *     provider_breakdown: list<ShareProviderBreakdownRow>,
 *     top_sharers: list<ShareTopSharerRow>,
 *     top_links: list<ShareTopLinkRow>,
 *     recent_visits: list<ShareRecentVisitRow>,
 *     recent_outcomes: list<ShareRecentOutcomeRow>
 * }
 */
final readonly class AdminShareAnalyticsService
{
    public function __construct(
        private ShareTrackingService $shareTrackingService,
        private ShareMetricReader $metricReader,
    ) {}

    /**
     * Staff dashboard over marked share profiles only. The summary uses
     * the SAME bounded definitions as ShareMetricReader (frozen
     * outbound shares, frozen visitor_key browser union, valid-only
     * outcomes, canonical reporting start); detail sections are bounded
     * aggregates plus limit-bounded recent rows — never lifetime
     * collections. Commercial facts never enter this report.
     *
     * @return ShareAnalyticsDashboard
     */
    public function dashboard(
        int $topSharersLimit = 8,
        int $topLinksLimit = 10,
        int $recentVisitsLimit = 10,
        int $recentOutcomesLimit = 10,
    ): array {
        if (! ShareTrackingMeasurement::isConfigured()) {
            return [
                'summary' => [
                    'affiliates' => 0,
                    'shared_links' => 0,
                    'outbound_shares' => 0,
                    'visits' => 0,
                    'distinct_browsers' => 0,
                    'signups' => 0,
                    'event_registrations' => 0,
                    'event_checkins' => 0,
                    'event_submissions' => 0,
                    'total_outcomes' => 0,
                ],
                'provider_breakdown' => [],
                'top_sharers' => [],
                'top_links' => [],
                'recent_visits' => [],
                'recent_outcomes' => [],
            ];
        }

        $startedAt = CarbonImmutable::parse((string) ShareTrackingMeasurement::reportingStart(), 'UTC');
        $now = CarbonImmutable::now('UTC');

        // Marker scope: share analytics reads only marked share profiles.
        // Commercial profiles and their facts never enter this report.
        $affiliates = Affiliate::query()->withoutGlobalScope(OwnerScope::class)->get()
            ->filter(fn (Affiliate $affiliate): bool => ShareTrackingProfile::isMarked($affiliate))
            ->values();
        $affiliateIds = $affiliates->map(fn (Affiliate $affiliate): string => (string) $affiliate->id)->all();
        $users = $this->usersForAffiliates($affiliates);

        $summary = $this->summary($affiliates, $affiliateIds, $startedAt, $now);

        return [
            'summary' => $summary,
            'provider_breakdown' => $this->providerBreakdown($affiliateIds, $startedAt, $now),
            'top_sharers' => $this->topSharers($affiliates, $users, $affiliateIds, $startedAt, $now, $topSharersLimit),
            'top_links' => $this->topLinks($affiliates, $users, $affiliateIds, $startedAt, $now, $topLinksLimit),
            'recent_visits' => $this->recentVisits($affiliates, $users, $affiliateIds, $startedAt, $recentVisitsLimit),
            'recent_outcomes' => $this->recentOutcomes($affiliates, $users, $affiliateIds, $startedAt, $recentOutcomesLimit),
        ];
    }

    /**
     * @param  Collection<int, Affiliate>  $affiliates
     * @return Collection<string, User>
     */
    private function usersForAffiliates(Collection $affiliates): Collection
    {
        $userIds = $affiliates
            ->map(fn (Affiliate $affiliate): ?string => $this->userIdForAffiliate($affiliate))
            ->filter(fn (mixed $userId): bool => is_string($userId) && $userId !== '')
            ->unique()
            ->values();

        if ($userIds->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $userIds->all())
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  Collection<int, Affiliate>  $affiliates
     * @param  list<string>  $affiliateIds
     * @return ShareAnalyticsSummary
     */
    private function summary(
        Collection $affiliates,
        array $affiliateIds,
        CarbonImmutable $startedAt,
        CarbonImmutable $now,
    ): array {
        $linkTable = (new AffiliateLink)->getTable();
        $links = $affiliateIds === [] ? 0 : (int) DB::table($linkTable)
            ->whereIn('affiliate_id', $affiliateIds)
            ->count();

        if ($affiliateIds === []) {
            $totals = ['recorded_shares' => 0, 'recorded_visits' => 0, 'distinct_sharing_browsers' => 0];
            $byOutcome = [];
            $valid = 0;
        } else {
            // The same definitions as every other read surface: global
            // marked scope over the full certified window, no daily
            // buckets at this scale.
            $report = $this->metricReader->summarize('UTC', $startedAt, $now, null, [], 36500, false);
            $totals = $report['totals'];
            $byOutcome = $report['by_outcome'];
            $valid = $report['by_category']['valid'];
        }

        return [
            'affiliates' => $affiliates->count(),
            'shared_links' => $links,
            'outbound_shares' => (int) $totals['recorded_shares'],
            'visits' => (int) $totals['recorded_visits'],
            'distinct_browsers' => (int) $totals['distinct_sharing_browsers'],
            'signups' => (int) ($byOutcome['signup'] ?? 0),
            'event_registrations' => (int) ($byOutcome['event_registration'] ?? 0),
            'event_checkins' => (int) ($byOutcome['event_checkin'] ?? 0),
            'event_submissions' => (int) ($byOutcome['event_submission'] ?? 0),
            'total_outcomes' => $valid,
        ];
    }

    /**
     * @param  list<string>  $affiliateIds
     * @return list<ShareProviderBreakdownRow>
     */
    private function providerBreakdown(array $affiliateIds, CarbonImmutable $startedAt, CarbonImmutable $now): array
    {
        if ($affiliateIds === []) {
            return [];
        }

        $touchpointTable = (new AffiliateTouchpoint)->getTable();
        $attributionTable = (new AffiliateAttribution)->getTable();
        $conversionTable = (new AffiliateConversion)->getTable();

        $channels = collect($this->shareTrackingService->supportedProviders());

        foreach ([
            DB::table($touchpointTable)->whereIn('affiliate_id', $affiliateIds)->where('touched_at', '>=', $startedAt)->distinct()->pluck('channel'),
            DB::table($attributionTable)->whereIn('affiliate_id', $affiliateIds)->where('attribution_type', 'landing')->where('first_seen_at', '>=', $startedAt)->distinct()->pluck('channel'),
            DB::table($conversionTable)->whereIn('affiliate_id', $affiliateIds)->where('occurred_at', '>=', $startedAt)->distinct()->pluck('channel'),
        ] as $observed) {
            $channels = $channels->merge($observed);
        }

        $rows = $channels
            ->filter(fn (mixed $provider): bool => is_string($provider) && $provider !== '')
            ->unique()
            ->values()
            ->map(function (string $provider) use (
                $affiliateIds, $touchpointTable, $attributionTable, $conversionTable, $startedAt, $now
            ): array {
                $outbound = (int) DB::table($touchpointTable)
                    ->whereIn('affiliate_id', $affiliateIds)
                    ->where('touchpoint_type', 'outbound_share')
                    ->where('channel', $provider)
                    ->where('touched_at', '>=', $startedAt)
                    ->where('touched_at', '<=', $now)
                    ->count();

                $visits = (int) DB::table($touchpointTable)
                    ->whereIn('affiliate_id', $affiliateIds)
                    ->where('touchpoint_type', 'visit')
                    ->where('channel', $provider)
                    ->where('touched_at', '>=', $startedAt)
                    ->where('touched_at', '<=', $now)
                    ->count();

                $browsers = $this->distinctBrowsers($touchpointTable, $attributionTable, $affiliateIds, $startedAt, $now, $provider, null);

                $outcomes = (int) DB::table($conversionTable)
                    ->whereIn('affiliate_id', $affiliateIds)
                    ->whereIn('status', ShareMetricReader::VALID_STATUSES)
                    ->where('channel', $provider)
                    ->where('occurred_at', '>=', $startedAt)
                    ->where('occurred_at', '<=', $now)
                    ->count();

                $signups = (int) DB::table($conversionTable)
                    ->whereIn('affiliate_id', $affiliateIds)
                    ->whereIn('status', ShareMetricReader::VALID_STATUSES)
                    ->where('channel', $provider)
                    ->where('conversion_type', 'signup')
                    ->where('occurred_at', '>=', $startedAt)
                    ->where('occurred_at', '<=', $now)
                    ->count();

                return [
                    'provider' => $provider,
                    'label' => $this->providerLabel($provider),
                    'outbound_shares' => $outbound,
                    'visits' => $visits,
                    'distinct_browsers' => $browsers,
                    'outcomes' => $outcomes,
                    'signups' => $signups,
                ];
            })
            ->filter(fn (array $provider): bool => collect([$provider['outbound_shares'], $provider['visits'], $provider['outcomes']])->contains(fn (int $count): bool => $count > 0))
            ->sort(fn (array $left, array $right): int => [
                $right['outcomes'],
                $right['visits'],
                $right['outbound_shares'],
            ] <=> [
                $left['outcomes'],
                $left['visits'],
                $left['outbound_shares'],
            ])
            ->values()
            ->all();

        return $rows;
    }

    /**
     * @param  Collection<int, Affiliate>  $affiliates
     * @param  Collection<string, User>  $users
     * @param  list<string>  $affiliateIds
     * @return list<ShareTopSharerRow>
     */
    private function topSharers(
        Collection $affiliates,
        Collection $users,
        array $affiliateIds,
        CarbonImmutable $startedAt,
        CarbonImmutable $now,
        int $limit,
    ): array {
        if ($affiliateIds === []) {
            return [];
        }

        $linkTable = (new AffiliateLink)->getTable();
        $touchpointTable = (new AffiliateTouchpoint)->getTable();
        $attributionTable = (new AffiliateAttribution)->getTable();
        $conversionTable = (new AffiliateConversion)->getTable();

        $linksByAffiliate = DB::table($linkTable)
            ->selectRaw('affiliate_id, COUNT(*) as total')
            ->whereIn('affiliate_id', $affiliateIds)
            ->groupBy('affiliate_id')
            ->pluck('total', 'affiliate_id')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();

        $visitsByAffiliate = DB::table($touchpointTable)
            ->selectRaw('affiliate_id, COUNT(*) as total')
            ->whereIn('affiliate_id', $affiliateIds)
            ->where('touchpoint_type', 'visit')
            ->where('touched_at', '>=', $startedAt)
            ->where('touched_at', '<=', $now)
            ->groupBy('affiliate_id')
            ->pluck('total', 'affiliate_id')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();

        $outcomesByAffiliate = DB::table($conversionTable)
            ->selectRaw('affiliate_id, COUNT(*) as total')
            ->whereIn('affiliate_id', $affiliateIds)
            ->whereIn('status', ShareMetricReader::VALID_STATUSES)
            ->where('occurred_at', '>=', $startedAt)
            ->where('occurred_at', '<=', $now)
            ->groupBy('affiliate_id')
            ->pluck('total', 'affiliate_id')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();

        $ranked = $affiliates
            ->map(fn (Affiliate $affiliate): array => [
                'affiliate' => $affiliate,
                'outcomes' => (int) ($outcomesByAffiliate[(string) $affiliate->id] ?? 0),
                'visits' => (int) ($visitsByAffiliate[(string) $affiliate->id] ?? 0),
                'links' => (int) ($linksByAffiliate[(string) $affiliate->id] ?? 0),
            ])
            ->sort(fn (array $left, array $right): int => [
                $right['outcomes'],
                $right['visits'],
                $right['links'],
            ] <=> [
                $left['outcomes'],
                $left['visits'],
                $left['links'],
            ])
            ->take($limit)
            ->values();

        $topIds = $ranked->map(fn (array $row): string => (string) $row['affiliate']->id)->all();

        $signupsByAffiliate = $this->conversionTypeCounts($conversionTable, $topIds, 'signup', $startedAt, $now);
        $registrationsByAffiliate = $this->conversionTypeCounts($conversionTable, $topIds, 'event_registration', $startedAt, $now);

        $latestLinks = DB::table($linkTable)
            ->selectRaw('affiliate_id, MAX(updated_at) as latest')
            ->whereIn('affiliate_id', $topIds)
            ->groupBy('affiliate_id')
            ->pluck('latest', 'affiliate_id')
            ->all();
        $latestVisits = DB::table($touchpointTable)
            ->selectRaw('affiliate_id, MAX(touched_at) as latest')
            ->whereIn('affiliate_id', $topIds)
            ->where('touchpoint_type', 'visit')
            ->where('touched_at', '>=', $startedAt)
            ->groupBy('affiliate_id')
            ->pluck('latest', 'affiliate_id')
            ->all();
        $latestOutcomes = DB::table($conversionTable)
            ->selectRaw('affiliate_id, MAX(occurred_at) as latest')
            ->whereIn('affiliate_id', $topIds)
            ->whereIn('status', ShareMetricReader::VALID_STATUSES)
            ->where('occurred_at', '>=', $startedAt)
            ->groupBy('affiliate_id')
            ->pluck('latest', 'affiliate_id')
            ->all();

        return $ranked
            ->map(function (array $row) use (
                $users, $signupsByAffiliate, $registrationsByAffiliate,
                $latestLinks, $latestVisits, $latestOutcomes,
                $touchpointTable, $attributionTable, $startedAt, $now
            ): array {
                /** @var Affiliate $affiliate */
                $affiliate = $row['affiliate'];
                $id = (string) $affiliate->id;
                $user = $users->get($this->userIdForAffiliate($affiliate));

                $latest = collect([$latestLinks[$id] ?? null, $latestVisits[$id] ?? null, $latestOutcomes[$id] ?? null])
                    ->filter(fn (mixed $value): bool => $value !== null)
                    ->map(fn (mixed $value): CarbonInterface => CarbonImmutable::parse($value, 'UTC'))
                    ->sortByDesc(fn (CarbonInterface $value): int => $value->getTimestamp())
                    ->first();

                return [
                    'affiliate_id' => $affiliate->id,
                    'affiliate_code' => $affiliate->code,
                    'user_name' => $user instanceof User ? $user->name : $affiliate->name,
                    'user_email' => $user instanceof User ? $user->email : $affiliate->contact_email,
                    'links' => $row['links'],
                    'visits' => $row['visits'],
                    'distinct_browsers' => $this->distinctBrowsers($touchpointTable, $attributionTable, [$id], $startedAt, $now, null, null),
                    'outcomes' => $row['outcomes'],
                    'signups' => (int) ($signupsByAffiliate[$id] ?? 0),
                    'event_registrations' => (int) ($registrationsByAffiliate[$id] ?? 0),
                    'last_activity_at' => $latest instanceof CarbonInterface ? $latest->toDateTimeString() : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Affiliate>  $affiliates
     * @param  Collection<string, User>  $users
     * @param  list<string>  $affiliateIds
     * @return list<ShareTopLinkRow>
     */
    private function topLinks(
        Collection $affiliates,
        Collection $users,
        array $affiliateIds,
        CarbonImmutable $startedAt,
        CarbonImmutable $now,
        int $limit,
    ): array {
        if ($affiliateIds === []) {
            return [];
        }

        $linkTable = (new AffiliateLink)->getTable();
        $touchpointTable = (new AffiliateTouchpoint)->getTable();
        $attributionTable = (new AffiliateAttribution)->getTable();
        $conversionTable = (new AffiliateConversion)->getTable();

        $visitsByLink = DB::table($touchpointTable)
            ->selectRaw('affiliate_link_id, COUNT(*) as total')
            ->whereIn('affiliate_id', $affiliateIds)
            ->where('touchpoint_type', 'visit')
            ->where('touched_at', '>=', $startedAt)
            ->where('touched_at', '<=', $now)
            ->whereNotNull('affiliate_link_id')
            ->groupBy('affiliate_link_id')
            ->pluck('total', 'affiliate_link_id')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();

        $outboundByLink = DB::table($touchpointTable)
            ->selectRaw('affiliate_link_id, COUNT(*) as total')
            ->whereIn('affiliate_id', $affiliateIds)
            ->where('touchpoint_type', 'outbound_share')
            ->where('touched_at', '>=', $startedAt)
            ->where('touched_at', '<=', $now)
            ->whereNotNull('affiliate_link_id')
            ->groupBy('affiliate_link_id')
            ->pluck('total', 'affiliate_link_id')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();

        $outcomesByLink = DB::table($conversionTable)
            ->selectRaw('affiliate_link_id, COUNT(*) as total')
            ->whereIn('affiliate_id', $affiliateIds)
            ->whereIn('status', ShareMetricReader::VALID_STATUSES)
            ->where('occurred_at', '>=', $startedAt)
            ->where('occurred_at', '<=', $now)
            ->whereNotNull('affiliate_link_id')
            ->groupBy('affiliate_link_id')
            ->pluck('total', 'affiliate_link_id')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();

        $links = AffiliateLink::query()
            ->withoutGlobalScope(OwnerScope::class)
            ->whereIn('affiliate_id', $affiliateIds)
            ->latest('updated_at')
            ->limit(500)
            ->get();

        $affiliatesById = $affiliates->keyBy('id');

        return $links
            ->map(fn (AffiliateLink $link): array => [
                'link' => $link,
                'outcomes' => (int) ($outcomesByLink[(string) $link->id] ?? 0),
                'visits' => (int) ($visitsByLink[(string) $link->id] ?? 0),
                'outbound_shares' => (int) ($outboundByLink[(string) $link->id] ?? 0),
            ])
            ->sort(fn (array $left, array $right): int => [
                $right['outcomes'],
                $right['visits'],
                $right['outbound_shares'],
            ] <=> [
                $left['outcomes'],
                $left['visits'],
                $left['outbound_shares'],
            ])
            ->take($limit)
            ->map(function (array $row) use (
                $affiliatesById, $users, $touchpointTable, $attributionTable, $startedAt, $now
            ): array {
                /** @var AffiliateLink $link */
                $link = $row['link'];
                $affiliate = $affiliatesById->get($link->affiliate_id);
                $user = $affiliate instanceof Affiliate ? $users->get($this->userIdForAffiliate($affiliate)) : null;

                return [
                    'link_id' => $link->id,
                    'title_snapshot' => $link->subject_title_snapshot,
                    'subject_type' => $link->subject_type ?: 'page',
                    'destination_url' => $link->destination_url,
                    'affiliate_code' => $affiliate?->code,
                    'sharer_name' => $user instanceof User ? $user->name : $affiliate?->name,
                    'outbound_shares' => $row['outbound_shares'],
                    'visits' => $row['visits'],
                    'distinct_browsers' => $this->distinctBrowsers($touchpointTable, $attributionTable, [], $startedAt, $now, null, (string) $link->id),
                    'outcomes' => $row['outcomes'],
                    'last_shared_at' => $link->updated_at?->toDateTimeString(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Affiliate>  $affiliates
     * @param  Collection<string, User>  $users
     * @param  list<string>  $affiliateIds
     * @return list<ShareRecentVisitRow>
     */
    private function recentVisits(
        Collection $affiliates,
        Collection $users,
        array $affiliateIds,
        CarbonImmutable $startedAt,
        int $limit,
    ): array {
        if ($affiliateIds === []) {
            return [];
        }

        $affiliatesById = $affiliates->keyBy('id');

        return AffiliateTouchpoint::query()
            ->withoutGlobalScope(OwnerScope::class)
            ->whereIn('affiliate_id', $affiliateIds)
            ->where('touchpoint_type', 'visit')
            ->where('touched_at', '>=', $startedAt)
            ->latest('touched_at')
            ->limit($limit)
            ->get()
            ->map(function (AffiliateTouchpoint $visit) use ($affiliatesById, $users): array {
                $affiliate = $affiliatesById->get($visit->affiliate_id);
                $user = $affiliate instanceof Affiliate ? $users->get($this->userIdForAffiliate($affiliate)) : null;

                $metadata = $visit->metadata;
                $visitKind = is_array($metadata) ? ($metadata['visit_kind'] ?? null) : null;

                return [
                    'subject' => str((string) ($visit->subject_type ?? 'page'))->headline()->toString(),
                    'provider' => $this->providerLabel((string) ($visit->channel ?? 'direct')),
                    'visit_kind' => str(is_string($visitKind) && $visitKind !== '' ? $visitKind : 'visit')->replace('_', ' ')->headline()->toString(),
                    'channel' => is_string($visit->channel) && $visit->channel !== '' ? $visit->channel : null,
                    'sharer_name' => $user instanceof User ? $user->name : $affiliate?->name,
                    'occurred_at' => $visit->touched_at?->toDateTimeString(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Affiliate>  $affiliates
     * @param  Collection<string, User>  $users
     * @param  list<string>  $affiliateIds
     * @return list<ShareRecentOutcomeRow>
     */
    private function recentOutcomes(
        Collection $affiliates,
        Collection $users,
        array $affiliateIds,
        CarbonImmutable $startedAt,
        int $limit,
    ): array {
        if ($affiliateIds === []) {
            return [];
        }

        $affiliatesById = $affiliates->keyBy('id');

        return AffiliateConversion::query()
            ->withoutGlobalScope(OwnerScope::class)
            ->whereIn('affiliate_id', $affiliateIds)
            ->whereIn('status', ShareMetricReader::VALID_STATUSES)
            ->where('occurred_at', '>=', $startedAt)
            ->latest('occurred_at')
            ->limit($limit)
            ->get()
            ->map(function (AffiliateConversion $conversion) use ($affiliatesById, $users): array {
                $affiliate = $affiliatesById->get($conversion->affiliate_id);
                $user = $affiliate instanceof Affiliate ? $users->get($this->userIdForAffiliate($affiliate)) : null;

                return [
                    'conversion_type' => str((string) $conversion->conversion_type)->replace('_', ' ')->headline()->toString(),
                    'subject_type' => str((string) ($conversion->subject_type ?? 'page'))->headline()->toString(),
                    'title_snapshot' => (string) ($conversion->subject_title_snapshot ?: config('app.name')),
                    'sharer_name' => $user instanceof User ? $user->name : $affiliate?->name,
                    'occurred_at' => $conversion->occurred_at?->toDateTimeString(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Frozen browser-key union over landing attributions and visit
     * touchpoints — the same browser definition as ShareMetricReader.
     * Either an affiliate scope or a single link scope applies.
     *
     * @param  list<string>  $affiliateIds
     */
    private function distinctBrowsers(
        string $touchpointTable,
        string $attributionTable,
        array $affiliateIds,
        CarbonImmutable $startedAt,
        CarbonImmutable $now,
        ?string $channel,
        ?string $linkId,
    ): int {
        $landingKeys = DB::table($attributionTable)
            ->select('visitor_key')
            ->where('attribution_type', 'landing')
            ->where('first_seen_at', '>=', $startedAt)
            ->where('first_seen_at', '<=', $now)
            ->whereNotNull('visitor_key')
            ->where('visitor_key', '!=', '');

        $visitKeys = DB::table($touchpointTable)
            ->select('visitor_key')
            ->where('touchpoint_type', 'visit')
            ->where('touched_at', '>=', $startedAt)
            ->where('touched_at', '<=', $now)
            ->whereNotNull('visitor_key')
            ->where('visitor_key', '!=', '');

        if ($linkId !== null) {
            $landingKeys->where('affiliate_link_id', $linkId);
            $visitKeys->where('affiliate_link_id', $linkId);
        } else {
            $landingKeys->whereIn('affiliate_id', $affiliateIds);
            $visitKeys->whereIn('affiliate_id', $affiliateIds);
        }

        if ($channel !== null) {
            $landingKeys->where('channel', $channel);
            $visitKeys->where('channel', $channel);
        }

        return (int) DB::query()
            ->fromSub($landingKeys->union($visitKeys), 'browser_union')
            ->count();
    }

    /**
     * @param  list<string>  $affiliateIds
     * @return array<string, int>
     */
    private function conversionTypeCounts(
        string $conversionTable,
        array $affiliateIds,
        string $type,
        CarbonImmutable $startedAt,
        CarbonImmutable $now,
    ): array {
        if ($affiliateIds === []) {
            return [];
        }

        return DB::table($conversionTable)
            ->selectRaw('affiliate_id, COUNT(*) as total')
            ->whereIn('affiliate_id', $affiliateIds)
            ->whereIn('status', ShareMetricReader::VALID_STATUSES)
            ->where('conversion_type', $type)
            ->where('occurred_at', '>=', $startedAt)
            ->where('occurred_at', '<=', $now)
            ->groupBy('affiliate_id')
            ->pluck('total', 'affiliate_id')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();
    }

    private function userIdForAffiliate(Affiliate $affiliate): ?string
    {
        $owner = $affiliate->owner;

        return $owner instanceof User ? (string) $owner->getAuthIdentifier() : null;
    }

    private function providerLabel(string $provider): string
    {
        return match ($provider) {
            'threads' => 'Threads',
            default => str($provider)->replace('_', ' ')->headline()->toString(),
        };
    }
}
