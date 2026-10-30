<?php

declare(strict_types=1);

namespace App\Services\ShareTracking;

use AIArmada\Affiliates\Models\AffiliateAttribution;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateLink;
use AIArmada\Affiliates\Models\AffiliateTouchpoint;
use AIArmada\Affiliates\States\ConversionStatus;
use AIArmada\CommerceSupport\Support\OwnerScope;
use App\Data\ShareTracking\ShareTrackingLinkData;
use App\Data\ShareTracking\ShareTrackingOutcomeData;
use App\Data\ShareTracking\ShareTrackingVisitData;
use App\Models\User;
use App\Support\Share\ShareMetricReader;
use App\Support\Share\ShareProfileScope;
use App\Support\ShareTracking\ShareTrackingMeasurement;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final readonly class AffiliatesShareTrackingAnalyticsService
{
    public function __construct(
        private AffiliatesShareTrackingService $shareTrackingService,
    ) {}

    /**
     * @return array{
     *     outbound_shares: int,
     *     visits: int,
     *     distinct_browsers: int,
     *     signups: int,
     *     event_registrations: int,
     *     event_checkins: int,
     *     event_submissions: int,
     *     total_outcomes: int
     * }
     */
    public function summaryForUser(User $user): array
    {
        if (! ShareTrackingMeasurement::isConfigured()) {
            return $this->emptySummary();
        }

        $affiliateIds = $this->affiliateIdsForUser($user);

        if ($affiliateIds === []) {
            return $this->emptySummary();
        }

        return $this->summaryFromCollections(
            $this->outboundSharesQuery($affiliateIds)->get(),
            $this->visitTouchpointsQuery($affiliateIds)->get(),
            $this->conversionsQuery($affiliateIds)->get(),
            $this->landingAttributionsQuery($affiliateIds)->get(),
        );
    }

    /**
     * @return Collection<int, array{
     *     provider: string,
     *     label: string,
     *     outbound_shares: int,
     *     visits: int,
     *     distinct_browsers: int,
     *     outcomes: int,
     *     signups: int,
     *     event_registrations: int,
     *     event_checkins: int,
     *     event_submissions: int
     * }>
     */
    public function providerBreakdownForUser(User $user): Collection
    {
        if (! ShareTrackingMeasurement::isConfigured()) {
            return collect();
        }

        $affiliateIds = $this->affiliateIdsForUser($user);

        if ($affiliateIds === []) {
            return collect();
        }

        $outboundShares = $this->outboundSharesQuery($affiliateIds)->get();
        $visits = $this->visitTouchpointsQuery($affiliateIds)->get();
        $conversions = $this->conversionsQuery($affiliateIds)->get();
        $attributions = $this->landingAttributionsQuery($affiliateIds)->get();

        return $this->providerBreakdown($outboundShares, $visits, $conversions, $attributions);
    }

    /**
     * @return Collection<int, ShareTrackingLinkData>
     */
    public function linksForUser(
        User $user,
        string $sort = 'recent',
        ?string $subjectType = null,
        string $status = 'all',
        string $outcomeType = 'all',
    ): Collection {
        $affiliateIds = $this->affiliateIdsForUser($user);

        if ($affiliateIds === []) {
            return collect();
        }

        $withholdMeasurements = ! ShareTrackingMeasurement::isConfigured();

        $links = AffiliateLink::query()
            ->withoutGlobalScope('affiliate_owner')
            ->whereIn('affiliate_id', $affiliateIds)
            ->get()
            ->map(fn (AffiliateLink $link): ShareTrackingLinkData => $this->buildLinkData($link, $withholdMeasurements))
            ->when(
                filled($subjectType) && $subjectType !== 'all',
                fn (Collection $collection): Collection => $collection->filter(
                    fn (ShareTrackingLinkData $link): bool => $link->subjectType === $subjectType,
                ),
            )
            ->when(
                filled($outcomeType) && $outcomeType !== 'all',
                fn (Collection $collection): Collection => $collection->filter(
                    fn (ShareTrackingLinkData $link): bool => $this->outcomeCountForType($link, $outcomeType) > 0,
                ),
            )
            ->filter(function (ShareTrackingLinkData $link) use ($status): bool {
                if ($status === 'all') {
                    return true;
                }

                $isActive = $link->latestActivityAt()?->greaterThanOrEqualTo(now()->subDays((int) config('dawah-share.ttl_days', 30))) ?? false;

                return $status === 'active' ? $isActive : ! $isActive;
            });

        return $this->sortLinks($links->values(), $sort);
    }

    /**
     * @return Collection<int, ShareTrackingOutcomeData>
     */
    public function recentOutcomesForUser(User $user, int $limit = 12): Collection
    {
        if (! ShareTrackingMeasurement::isConfigured()) {
            return collect();
        }

        $affiliateIds = $this->affiliateIdsForUser($user);

        if ($affiliateIds === []) {
            return collect();
        }

        return $this->conversionsQuery($affiliateIds)
            ->latest('occurred_at')
            ->limit($limit)
            ->get()
            ->map(fn (AffiliateConversion $conversion): ShareTrackingOutcomeData => $this->mapOutcome($conversion));
    }

    public function findLinkForUser(User $user, string $linkId): ?ShareTrackingLinkData
    {
        $affiliateIds = $this->affiliateIdsForUser($user);

        if ($affiliateIds === []) {
            return null;
        }

        $link = AffiliateLink::query()
            ->withoutGlobalScope('affiliate_owner')
            ->whereIn('affiliate_id', $affiliateIds)
            ->whereKey($linkId)
            ->first();

        if (! $link instanceof AffiliateLink) {
            return null;
        }

        return $this->buildLinkData($link, ! ShareTrackingMeasurement::isConfigured());
    }

    /**
     * @return array{
     *     outbound_shares: int,
     *     visits: int,
     *     distinct_browsers: int,
     *     outcomes: int,
     *     signups: int,
     *     event_registrations: int,
     *     event_checkins: int,
     *     event_submissions: int
     * }
     */
    public function summaryForLink(ShareTrackingLinkData $link): array
    {
        if (! ShareTrackingMeasurement::isConfigured()) {
            return [
                'outbound_shares' => 0,
                'visits' => 0,
                'distinct_browsers' => 0,
                'outcomes' => 0,
                'signups' => 0,
                'event_registrations' => 0,
                'event_checkins' => 0,
                'event_submissions' => 0,
            ];
        }

        return [
            'outbound_shares' => $link->outboundShares,
            'visits' => $link->visitsCount,
            'distinct_browsers' => $this->distinctBrowsersForLink($link->id),
            'outcomes' => $link->outcomesCount,
            'signups' => $link->signupsCount,
            'event_registrations' => $link->eventRegistrationsCount,
            'event_checkins' => $link->eventCheckinsCount,
            'event_submissions' => $link->eventSubmissionsCount,
        ];
    }

    /**
     * @return Collection<int, array{
     *     provider: string,
     *     label: string,
     *     outbound_shares: int,
     *     visits: int,
     *     distinct_browsers: int,
     *     outcomes: int,
     *     signups: int,
     *     event_registrations: int,
     *     event_checkins: int,
     *     event_submissions: int
     * }>
     */
    public function providerBreakdownForLink(ShareTrackingLinkData $link): Collection
    {
        if (! ShareTrackingMeasurement::isConfigured()) {
            return collect();
        }

        $outboundShares = $this->outboundSharesQueryForLink($link->id)->get();
        $visits = $this->visitTouchpointsQueryForLink($link->id)->get();
        $conversions = $this->conversionsQueryForLink($link->id)->get();
        $attributions = $this->landingAttributionsQueryForLink($link->id)->get();

        return $this->providerBreakdown($outboundShares, $visits, $conversions, $attributions);
    }

    /**
     * @return Collection<int, array{date: string, visits: int, outcomes: int, signups: int, event_registrations: int, event_checkins: int, event_submissions: int}>
     */
    public function dailyPerformanceForLink(ShareTrackingLinkData $link, int $days = 14, ?string $timezone = null): Collection
    {
        if (! ShareTrackingMeasurement::isConfigured()) {
            return collect();
        }

        $timezone ??= (string) config('app.timezone', 'UTC');

        try {
            $tz = new \DateTimeZone($timezone);
        } catch (\Throwable) {
            throw new InvalidArgumentException('Viewer timezone must be a valid identifier.');
        }

        $today = Carbon::now($tz)->startOfDay();
        $fromDate = $today->copy()->subDays(max($days - 1, 0));
        $fromUtc = $fromDate->copy()->setTimezone('UTC');

        $bucket = static function (mixed $instant) use ($tz, $today): string {
            if ($instant instanceof CarbonInterface) {
                return Carbon::parse($instant)->setTimezone($tz)->toDateString();
            }

            return $today->toDateString();
        };

        $visits = $this->visitTouchpointsQueryForLink($link->id)
            ->where('touched_at', '>=', $fromUtc)
            ->get()
            ->groupBy(fn (AffiliateTouchpoint $touchpoint): string => $bucket($touchpoint->touched_at))
            ->map(fn (Collection $collection): int => $collection->count());

        $outcomes = $this->conversionsQueryForLink($link->id)
            ->where('occurred_at', '>=', $fromUtc)
            ->get()
            ->groupBy(fn (AffiliateConversion $conversion): string => $bucket($conversion->occurred_at));

        return collect(range(0, max($days - 1, 0)))
            ->map(function (int $offset) use ($fromDate, $visits, $outcomes): array {
                $date = $fromDate->copy()->addDays($offset)->toDateString();
                $dailyOutcomes = $outcomes->get($date, collect());

                return [
                    'date' => $date,
                    'visits' => (int) $visits->get($date, 0),
                    'outcomes' => (int) $dailyOutcomes->count(),
                    'signups' => (int) $dailyOutcomes->where('conversion_type', 'signup')->count(),
                    'event_registrations' => (int) $dailyOutcomes->where('conversion_type', 'event_registration')->count(),
                    'event_checkins' => (int) $dailyOutcomes->where('conversion_type', 'event_checkin')->count(),
                    'event_submissions' => (int) $dailyOutcomes->where('conversion_type', 'event_submission')->count(),
                ];
            });
    }

    /**
     * @return Collection<int, array{outcome_type: string, label: string, count: int}>
     */
    public function outcomeBreakdownForLink(ShareTrackingLinkData $link): Collection
    {
        if (! ShareTrackingMeasurement::isConfigured()) {
            return collect();
        }

        return $this->conversionsQueryForLink($link->id)
            ->get()
            ->groupBy('conversion_type')
            ->map(fn (Collection $collection, string $type): array => $this->outcomeBreakdownRow($collection, $type))
            ->sortByDesc('count')
            ->values();
    }

    /**
     * @return Collection<int, ShareTrackingVisitData>
     */
    public function recentVisitsForLink(ShareTrackingLinkData $link, int $limit = 20): Collection
    {
        if (! ShareTrackingMeasurement::isConfigured()) {
            return collect();
        }

        return $this->visitTouchpointsQueryForLink($link->id)
            ->latest('touched_at')
            ->limit($limit)
            ->get()
            ->map(fn (AffiliateTouchpoint $touchpoint): ShareTrackingVisitData => $this->mapVisit($touchpoint));
    }

    /**
     * @return Collection<int, ShareTrackingOutcomeData>
     */
    public function recentOutcomesForLink(ShareTrackingLinkData $link, int $limit = 20): Collection
    {
        if (! ShareTrackingMeasurement::isConfigured()) {
            return collect();
        }

        return $this->conversionsQueryForLink($link->id)
            ->latest('occurred_at')
            ->limit($limit)
            ->get()
            ->map(fn (AffiliateConversion $conversion): ShareTrackingOutcomeData => $this->mapOutcome($conversion));
    }

    /**
     * @return array{
     *     first_seen_at: CarbonInterface|null,
     *     last_visit_at: CarbonInterface|null,
     *     last_outcome_at: CarbonInterface|null,
     *     latest_activity_at: CarbonInterface|null
     * }
     */
    public function activityWindowForLink(ShareTrackingLinkData $link): array
    {
        if (! ShareTrackingMeasurement::isConfigured()) {
            return [
                'first_seen_at' => null,
                'last_visit_at' => null,
                'last_outcome_at' => null,
                'latest_activity_at' => null,
            ];
        }

        $firstSeen = $this->landingAttributionsQueryForLink($link->id)->min('first_seen_at');
        $lastVisit = $this->visitTouchpointsQueryForLink($link->id)->max('touched_at');
        $lastOutcome = $this->conversionsQueryForLink($link->id)->max('occurred_at');
        $latestActivity = collect([
            $this->asCarbon($firstSeen),
            $this->asCarbon($lastVisit),
            $this->asCarbon($lastOutcome),
            $link->lastSharedAt,
        ])->filter()->sortByDesc(fn (CarbonInterface $value): int => $value->getTimestamp())->first();

        return [
            'first_seen_at' => $this->asCarbon($firstSeen),
            'last_visit_at' => $this->asCarbon($lastVisit),
            'last_outcome_at' => $this->asCarbon($lastOutcome),
            'latest_activity_at' => $latestActivity,
        ];
    }

    /**
     * Read scope for a user: their primary member profile plus every
     * guest profile they actively claimed. Revoked claims (deleted
     * history) are excluded: detached history is never readable.
     *
     * @return list<string>
     */
    private function affiliateIdsForUser(User $user): array
    {
        return ShareProfileScope::affiliateIdsForUser($user);
    }

    /**
     * @param  Collection<int, AffiliateConversion>  $collection
     * @return array{outcome_type: string, label: string, count: int}
     */
    private function outcomeBreakdownRow(Collection $collection, string $type): array
    {
        return [
            'outcome_type' => $type,
            'label' => $this->outcomeTypeLabel($type),
            'count' => $collection->count(),
        ];
    }

    /**
     * @param  Collection<int, AffiliateTouchpoint>  $outboundShares
     * @param  Collection<int, AffiliateTouchpoint>  $visits
     * @param  Collection<int, AffiliateConversion>  $conversions
     * @param  Collection<int, AffiliateAttribution>  $attributions
     * @return array{
     *     outbound_shares: int,
     *     visits: int,
     *     distinct_browsers: int,
     *     signups: int,
     *     event_registrations: int,
     *     event_checkins: int,
     *     event_submissions: int,
     *     total_outcomes: int
     * }
     */
    private function summaryFromCollections(Collection $outboundShares, Collection $visits, Collection $conversions, Collection $attributions): array
    {
        $valid = $conversions->filter(fn (AffiliateConversion $conversion): bool => $this->isValidConversion($conversion));

        $browserKeys = $attributions
            ->pluck('visitor_key')
            ->merge($visits->pluck('visitor_key'))
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->unique();

        return [
            'outbound_shares' => $outboundShares->count(),
            'visits' => $visits->count(),
            'distinct_browsers' => $browserKeys->count(),
            'signups' => $valid->where('conversion_type', 'signup')->count(),
            'event_registrations' => $valid->where('conversion_type', 'event_registration')->count(),
            'event_checkins' => $valid->where('conversion_type', 'event_checkin')->count(),
            'event_submissions' => $valid->where('conversion_type', 'event_submission')->count(),
            'total_outcomes' => $valid->count(),
        ];
    }

    private function isValidConversion(AffiliateConversion $conversion): bool
    {
        $status = $conversion->status;

        $value = $status instanceof ConversionStatus
            ? $status->getValue()
            : (string) $status;

        // Currently valid only: pending (unknown), rejected, and
        // reversed (historical) outcomes are never mixed into valid
        // counts. Same definition as ShareMetricReader.
        return in_array($value, ShareMetricReader::VALID_STATUSES, true);
    }

    private function reportingStart(): ?CarbonInterface
    {
        $raw = ShareTrackingMeasurement::reportingStart();

        if ($raw === null) {
            return null;
        }

        try {
            return Carbon::parse($raw, 'UTC');
        } catch (\Throwable) {
            return null;
        }
    }

    private function buildLinkData(AffiliateLink $link, bool $withholdMeasurements = false): ShareTrackingLinkData
    {
        // Pre-declaration, link identity stays usable for share-again while
        // every measurement is withheld as uncertified.
        if ($withholdMeasurements) {
            return new ShareTrackingLinkData(
                id: (string) $link->id,
                backend: 'affiliates',
                subjectType: (string) ($link->subject_type ?: 'page'),
                subjectId: $this->nullableString($link->subject_id),
                subjectKey: (string) ($link->subject_key ?: 'page:unknown'),
                destinationUrl: (string) $link->destination_url,
                canonicalUrl: (string) $link->destination_url,
                titleSnapshot: (string) ($link->subject_title_snapshot ?: config('app.name')),
                lastSharedAt: $link->updated_at,
                outboundShares: 0,
                visitsCount: 0,
                outcomesCount: 0,
                signupsCount: 0,
                eventRegistrationsCount: 0,
                eventCheckinsCount: 0,
                eventSubmissionsCount: 0,
                latestVisitAt: null,
                latestOutcomeAt: null,
            );
        }

        $visits = $this->visitTouchpointsQueryForLink($link->id)->get();
        $conversions = $this->conversionsQueryForLink($link->id)->get();

        return new ShareTrackingLinkData(
            id: (string) $link->id,
            backend: 'affiliates',
            subjectType: (string) ($link->subject_type ?: 'page'),
            subjectId: $this->nullableString($link->subject_id),
            subjectKey: (string) ($link->subject_key ?: 'page:unknown'),
            destinationUrl: (string) $link->destination_url,
            canonicalUrl: (string) $link->destination_url,
            titleSnapshot: (string) ($link->subject_title_snapshot ?: config('app.name')),
            lastSharedAt: $link->updated_at,
            outboundShares: $this->outboundSharesQueryForLink($link->id)->count(),
            visitsCount: $visits->count(),
            outcomesCount: $conversions->count(),
            signupsCount: $conversions->where('conversion_type', 'signup')->count(),
            eventRegistrationsCount: $conversions->where('conversion_type', 'event_registration')->count(),
            eventCheckinsCount: $conversions->where('conversion_type', 'event_checkin')->count(),
            eventSubmissionsCount: $conversions->where('conversion_type', 'event_submission')->count(),
            latestVisitAt: $this->asCarbon($visits->max('touched_at')),
            latestOutcomeAt: $this->asCarbon($conversions->max('occurred_at')),
        );
    }

    /**
     * @param  Collection<int, ShareTrackingLinkData>  $links
     * @return Collection<int, ShareTrackingLinkData>
     */
    private function sortLinks(Collection $links, string $sort): Collection
    {
        $sorted = match ($sort) {
            'visits' => $links->sortByDesc(fn (ShareTrackingLinkData $link): int => $link->visitsCount),
            'signups' => $links->sortByDesc(fn (ShareTrackingLinkData $link): int => $link->signupsCount),
            'registrations' => $links->sortByDesc(fn (ShareTrackingLinkData $link): int => $link->eventRegistrationsCount),
            'checkins' => $links->sortByDesc(fn (ShareTrackingLinkData $link): int => $link->eventCheckinsCount),
            'submissions' => $links->sortByDesc(fn (ShareTrackingLinkData $link): int => $link->eventSubmissionsCount),
            default => $links->sortByDesc(fn (ShareTrackingLinkData $link): int => $link->latestActivityAt()?->getTimestamp() ?? 0),
        };

        return $sorted->values();
    }

    private function outcomeCountForType(ShareTrackingLinkData $link, string $type): int
    {
        return match ($type) {
            'signup' => $link->signupsCount,
            'event_registration' => $link->eventRegistrationsCount,
            'event_checkin' => $link->eventCheckinsCount,
            'event_submission' => $link->eventSubmissionsCount,
            default => $this->conversionsQueryForLink($link->id)->where('conversion_type', $type)->count(),
        };
    }

    private function distinctBrowsersForLink(string $linkId): int
    {
        $attributionKeys = $this->landingAttributionsQueryForLink($linkId)->get()->pluck('visitor_key');
        $visitKeys = $this->visitTouchpointsQueryForLink($linkId)->get()->pluck('visitor_key');

        return $attributionKeys
            ->merge($visitKeys)
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->unique()
            ->count();
    }

    /**
     * @param  Collection<int, AffiliateTouchpoint>  $outboundShares
     * @param  Collection<int, AffiliateTouchpoint>  $visits
     * @param  Collection<int, AffiliateConversion>  $conversions
     * @param  Collection<int, AffiliateAttribution>  $attributions
     * @return Collection<int, array{
     *     provider: string,
     *     label: string,
     *     outbound_shares: int,
     *     visits: int,
     *     distinct_browsers: int,
     *     outcomes: int,
     *     signups: int,
     *     event_registrations: int,
     *     event_checkins: int,
     *     event_submissions: int
     * }>
     */
    private function providerBreakdown(Collection $outboundShares, Collection $visits, Collection $conversions, Collection $attributions): Collection
    {
        return $this->providersForBreakdown($outboundShares, $visits, $conversions, $attributions)
            ->map(function (string $provider) use ($outboundShares, $visits, $conversions, $attributions): array {
                $providerOutbound = $outboundShares->filter(fn (AffiliateTouchpoint $touchpoint): bool => $touchpoint->channel === $provider);
                $providerVisits = $visits->filter(fn (AffiliateTouchpoint $touchpoint): bool => $touchpoint->channel === $provider);
                $providerConversions = $conversions->filter(fn (AffiliateConversion $conversion): bool => $conversion->channel === $provider);
                $providerAttributions = $attributions->filter(fn (AffiliateAttribution $attribution): bool => $attribution->channel === $provider);

                return [
                    'provider' => $provider,
                    'label' => $this->providerLabel($provider),
                    'outbound_shares' => $providerOutbound->count(),
                    'visits' => $providerVisits->count(),
                    'distinct_browsers' => $this->distinctBrowsersForProvider($providerVisits, $providerAttributions),
                    'outcomes' => $providerConversions->count(),
                    'signups' => $providerConversions->where('conversion_type', 'signup')->count(),
                    'event_registrations' => $providerConversions->where('conversion_type', 'event_registration')->count(),
                    'event_checkins' => $providerConversions->where('conversion_type', 'event_checkin')->count(),
                    'event_submissions' => $providerConversions->where('conversion_type', 'event_submission')->count(),
                ];
            })
            ->filter(fn (array $provider): bool => collect([$provider['outbound_shares'], $provider['visits'], $provider['outcomes']])->some(fn (int $count): bool => $count > 0))
            ->sort(fn (array $left, array $right): int => [
                $right['outcomes'],
                $right['visits'],
                $right['outbound_shares'],
            ] <=> [
                $left['outcomes'],
                $left['visits'],
                $left['outbound_shares'],
            ])
            ->values();
    }

    /**
     * @param  Collection<int, AffiliateTouchpoint>  $outboundShares
     * @param  Collection<int, AffiliateTouchpoint>  $visits
     * @param  Collection<int, AffiliateConversion>  $conversions
     * @param  Collection<int, AffiliateAttribution>  $attributions
     * @return Collection<int, non-empty-string>
     */
    private function providersForBreakdown(Collection $outboundShares, Collection $visits, Collection $conversions, Collection $attributions): Collection
    {
        return collect($this->shareTrackingService->supportedProviders())
            ->merge($outboundShares->pluck('channel'))
            ->merge($visits->pluck('channel'))
            ->merge($conversions->pluck('channel'))
            ->merge($attributions->pluck('channel'))
            ->filter(fn (mixed $provider): bool => is_string($provider) && $provider !== '')
            ->unique()
            ->values();
    }

    /**
     * @param  Collection<int, AffiliateTouchpoint>  $visits
     * @param  Collection<int, AffiliateAttribution>  $attributions
     */
    private function distinctBrowsersForProvider(Collection $visits, Collection $attributions): int
    {
        return $visits
            ->pluck('visitor_key')
            ->merge($attributions->pluck('visitor_key'))
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->unique()
            ->count();
    }

    private function providerLabel(string $provider): string
    {
        return match ($provider) {
            'threads' => 'Threads',
            default => str($provider)->replace('_', ' ')->headline()->toString(),
        };
    }

    /**
     * @param  list<string>  $affiliateIds
     * @return Builder<AffiliateTouchpoint>
     */
    private function outboundSharesQuery(array $affiliateIds): Builder
    {
        $query = AffiliateTouchpoint::query()
            ->withoutGlobalScope(OwnerScope::class)
            ->whereIn('affiliate_id', $affiliateIds)
            ->where('touchpoint_type', 'outbound_share');

        if ($this->reportingStart() instanceof CarbonInterface) {
            $query->where('touched_at', '>=', $this->reportingStart());
        }

        return $query;
    }

    /**
     * @param  list<string>  $affiliateIds
     * @return Builder<AffiliateTouchpoint>
     */
    private function visitTouchpointsQuery(array $affiliateIds): Builder
    {
        $query = AffiliateTouchpoint::query()
            ->withoutGlobalScope(OwnerScope::class)
            ->whereIn('affiliate_id', $affiliateIds)
            ->where('touchpoint_type', 'visit');

        if ($this->reportingStart() instanceof CarbonInterface) {
            $query->where('touched_at', '>=', $this->reportingStart());
        }

        return $query;
    }

    /**
     * @param  list<string>  $affiliateIds
     * @return Builder<AffiliateConversion>
     */
    private function conversionsQuery(array $affiliateIds): Builder
    {
        $query = AffiliateConversion::query()
            ->withoutGlobalScope(OwnerScope::class)
            ->whereIn('affiliate_id', $affiliateIds);

        if ($this->reportingStart() instanceof CarbonInterface) {
            $query->where('occurred_at', '>=', $this->reportingStart());
        }

        return $query->whereIn('status', ShareMetricReader::VALID_STATUSES);
    }

    /**
     * @param  list<string>  $affiliateIds
     * @return Builder<AffiliateAttribution>
     */
    private function landingAttributionsQuery(array $affiliateIds): Builder
    {
        $query = AffiliateAttribution::query()
            ->withoutGlobalScope(OwnerScope::class)
            ->whereIn('affiliate_id', $affiliateIds)
            ->where('attribution_type', 'landing');

        if ($this->reportingStart() instanceof CarbonInterface) {
            $query->where('first_seen_at', '>=', $this->reportingStart());
        }

        return $query;
    }

    /**
     * @return Builder<AffiliateTouchpoint>
     */
    private function outboundSharesQueryForLink(string $linkId): Builder
    {
        $query = AffiliateTouchpoint::query()
            ->withoutGlobalScope(OwnerScope::class)
            ->where('touchpoint_type', 'outbound_share')
            ->where('affiliate_link_id', $linkId);

        if ($this->reportingStart() instanceof CarbonInterface) {
            $query->where('touched_at', '>=', $this->reportingStart());
        }

        return $query;
    }

    /**
     * @return Builder<AffiliateTouchpoint>
     */
    private function visitTouchpointsQueryForLink(string $linkId): Builder
    {
        $query = AffiliateTouchpoint::query()
            ->withoutGlobalScope(OwnerScope::class)
            ->where('touchpoint_type', 'visit')
            ->where('affiliate_link_id', $linkId);

        if ($this->reportingStart() instanceof CarbonInterface) {
            $query->where('touched_at', '>=', $this->reportingStart());
        }

        return $query;
    }

    /**
     * @return Builder<AffiliateConversion>
     */
    private function conversionsQueryForLink(string $linkId): Builder
    {
        $query = AffiliateConversion::query()
            ->withoutGlobalScope(OwnerScope::class)
            ->where('affiliate_link_id', $linkId);

        if ($this->reportingStart() instanceof CarbonInterface) {
            $query->where('occurred_at', '>=', $this->reportingStart());
        }

        return $query->whereIn('status', ShareMetricReader::VALID_STATUSES);
    }

    /**
     * @return Builder<AffiliateAttribution>
     */
    private function landingAttributionsQueryForLink(string $linkId): Builder
    {
        $query = AffiliateAttribution::query()
            ->withoutGlobalScope(OwnerScope::class)
            ->where('attribution_type', 'landing')
            ->where('affiliate_link_id', $linkId);

        if ($this->reportingStart() instanceof CarbonInterface) {
            $query->where('first_seen_at', '>=', $this->reportingStart());
        }

        return $query;
    }

    private function mapOutcome(AffiliateConversion $conversion): ShareTrackingOutcomeData
    {
        return new ShareTrackingOutcomeData(
            id: (string) $conversion->id,
            backend: 'affiliates',
            linkId: $this->nullableString($conversion->affiliate_link_id) ?? '',
            outcomeType: (string) ($conversion->conversion_type ?: 'unknown'),
            subjectType: $conversion->subject_type,
            subjectId: $this->nullableString($conversion->subject_id) ?? $this->nullableString($conversion->subject_key),
            subjectKey: $conversion->subject_key,
            linkTitleSnapshot: $this->nullableString($conversion->subject_title_snapshot),
            occurredAt: $conversion->occurred_at,
        );
    }

    private function mapVisit(AffiliateTouchpoint $touchpoint): ShareTrackingVisitData
    {
        return new ShareTrackingVisitData(
            id: (string) $touchpoint->id,
            backend: 'affiliates',
            linkId: $this->nullableString($touchpoint->affiliate_link_id) ?? '',
            subjectType: $touchpoint->subject_type,
            subjectId: $this->nullableString($touchpoint->subject_id) ?? $this->nullableString($touchpoint->subject_key),
            subjectKey: $touchpoint->subject_key,
            visitKind: $this->nullableString($touchpoint->metadata['visit_kind'] ?? null) ?? 'navigated',
            channel: $this->nullableString($touchpoint->channel),
            occurredAt: $touchpoint->touched_at,
        );
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function asCarbon(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            return Carbon::parse($value);
        }

        return null;
    }

    /**
     * @return array{
     *     outbound_shares: int,
     *     visits: int,
     *     distinct_browsers: int,
     *     signups: int,
     *     event_registrations: int,
     *     event_checkins: int,
     *     event_submissions: int,
     *     total_outcomes: int
     * }
     */
    private function emptySummary(): array
    {
        return [
            'outbound_shares' => 0,
            'visits' => 0,
            'distinct_browsers' => 0,
            'signups' => 0,
            'event_registrations' => 0,
            'event_checkins' => 0,
            'event_submissions' => 0,
            'total_outcomes' => 0,
        ];
    }

    private function outcomeTypeLabel(string $outcomeType): string
    {
        return match ($outcomeType) {
            'signup' => __('Signups'),
            'event_registration' => __('Event registrations'),
            'event_checkin' => __('Event check-ins'),
            'event_submission' => __('Event submissions'),
            'event_save' => __('Event saves'),
            'event_going' => __('Going responses'),
            'institution_follow' => __('Institution follows'),
            'person_follow' => __('Speaker follows'),
            'series_follow' => __('Series follows'),
            'reference_follow' => __('Reference follows'),
            'saved_search_created' => __('Saved searches created'),
            default => str($outcomeType)->replace('_', ' ')->headline()->toString(),
        };
    }
}
