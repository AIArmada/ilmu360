<?php

declare(strict_types=1);

namespace App\Services\ShareTracking;

use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateAttribution;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateLink;
use AIArmada\Affiliates\Models\AffiliateTouchpoint;
use App\Data\ShareTracking\ShareTrackingLinkData;
use App\Data\ShareTracking\ShareTrackingOutcomeData;
use App\Data\ShareTracking\ShareTrackingVisitData;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final readonly class AffiliatesShareTrackingAnalyticsService
{
    public function __construct(
        private AffiliatesShareTrackingService $shareTrackingService,
    ) {}

    /**
     * @return array{
     *     outbound_shares: int,
     *     visits: int,
     *     unique_visitors: int,
     *     signups: int,
     *     event_registrations: int,
     *     event_checkins: int,
     *     event_submissions: int,
     *     total_outcomes: int
     * }
     */
    public function summaryForUser(User $user): array
    {
        $affiliate = $this->affiliateForUser($user);

        if (! $affiliate instanceof Affiliate) {
            return $this->emptySummary();
        }

        return $this->summaryFromCollections(
            $this->outboundSharesQuery($affiliate)->get(),
            $this->visitTouchpointsQuery($affiliate)->get(),
            $this->conversionsQuery($affiliate)->get(),
            $this->landingAttributionsQuery($affiliate)->get(),
        );
    }

    /**
     * @return Collection<int, array{
     *     provider: string,
     *     label: string,
     *     outbound_shares: int,
     *     visits: int,
     *     unique_visitors: int,
     *     outcomes: int,
     *     signups: int,
     *     event_registrations: int,
     *     event_checkins: int,
     *     event_submissions: int
     * }>
     */
    public function providerBreakdownForUser(User $user): Collection
    {
        $affiliate = $this->affiliateForUser($user);

        if (! $affiliate instanceof Affiliate) {
            return collect();
        }

        $outboundShares = $this->outboundSharesQuery($affiliate)->get();
        $visits = $this->visitTouchpointsQuery($affiliate)->get();
        $conversions = $this->conversionsQuery($affiliate)->get();
        $attributions = $this->landingAttributionsQuery($affiliate)->get();

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
        $affiliate = $this->affiliateForUser($user);

        if (! $affiliate instanceof Affiliate) {
            return collect();
        }

        $links = AffiliateLink::query()
            ->where('affiliate_id', $affiliate->id)
            ->get()
            ->map(fn (AffiliateLink $link): ShareTrackingLinkData => $this->buildLinkData($link))
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
        $affiliate = $this->affiliateForUser($user);

        if (! $affiliate instanceof Affiliate) {
            return collect();
        }

        return $this->conversionsQuery($affiliate)
            ->latest('occurred_at')
            ->limit($limit)
            ->get()
            ->map(fn (AffiliateConversion $conversion): ShareTrackingOutcomeData => $this->mapOutcome($conversion));
    }

    public function findLinkForUser(User $user, string $linkId): ?ShareTrackingLinkData
    {
        $affiliate = $this->affiliateForUser($user);

        if (! $affiliate instanceof Affiliate) {
            return null;
        }

        $link = AffiliateLink::query()
            ->where('affiliate_id', $affiliate->id)
            ->whereKey($linkId)
            ->first();

        return $link instanceof AffiliateLink ? $this->buildLinkData($link) : null;
    }

    /**
     * @return array{
     *     outbound_shares: int,
     *     visits: int,
     *     unique_visitors: int,
     *     outcomes: int,
     *     signups: int,
     *     event_registrations: int,
     *     event_checkins: int,
     *     event_submissions: int
     * }
     */
    public function summaryForLink(ShareTrackingLinkData $link): array
    {
        return [
            'outbound_shares' => $link->outboundShares,
            'visits' => $link->visitsCount,
            'unique_visitors' => $this->uniqueVisitorsForLink($link->id),
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
     *     unique_visitors: int,
     *     outcomes: int,
     *     signups: int,
     *     event_registrations: int,
     *     event_checkins: int,
     *     event_submissions: int
     * }>
     */
    public function providerBreakdownForLink(ShareTrackingLinkData $link): Collection
    {
        $outboundShares = $this->outboundSharesQueryForLink($link->id)->get();
        $visits = $this->visitTouchpointsQueryForLink($link->id)->get();
        $conversions = $this->conversionsQueryForLink($link->id)->get();
        $attributions = $this->landingAttributionsQueryForLink($link->id)->get();

        return $this->providerBreakdown($outboundShares, $visits, $conversions, $attributions);
    }

    /**
     * @return Collection<int, array{date: string, visits: int, outcomes: int, signups: int, event_registrations: int, event_checkins: int, event_submissions: int}>
     */
    public function dailyPerformanceForLink(ShareTrackingLinkData $link, int $days = 14): Collection
    {
        $fromDate = now()->subDays(max($days - 1, 0))->startOfDay();

        $visits = $this->visitTouchpointsQueryForLink($link->id)
            ->where('touched_at', '>=', $fromDate)
            ->get()
            ->groupBy(fn (AffiliateTouchpoint $touchpoint): string => optional($touchpoint->touched_at)?->toDateString() ?? now()->toDateString())
            ->map(fn (Collection $collection): int => $collection->count());

        $outcomes = $this->conversionsQueryForLink($link->id)
            ->where('occurred_at', '>=', $fromDate)
            ->get()
            ->groupBy(fn (AffiliateConversion $conversion): string => optional($conversion->occurred_at)?->toDateString() ?? now()->toDateString());

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

    private function affiliateForUser(User $user): ?Affiliate
    {
        return $this->shareTrackingService->findAffiliateForUser($user);
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
     *     unique_visitors: int,
     *     signups: int,
     *     event_registrations: int,
     *     event_checkins: int,
     *     event_submissions: int,
     *     total_outcomes: int
     * }
     */
    private function summaryFromCollections(Collection $outboundShares, Collection $visits, Collection $conversions, Collection $attributions): array
    {
        return [
            'outbound_shares' => $outboundShares->count(),
            'visits' => $visits->count(),
            'unique_visitors' => $attributions->pluck('cookie_value')->filter()->unique()->count(),
            'signups' => $conversions->where('conversion_type', 'signup')->count(),
            'event_registrations' => $conversions->where('conversion_type', 'event_registration')->count(),
            'event_checkins' => $conversions->where('conversion_type', 'event_checkin')->count(),
            'event_submissions' => $conversions->where('conversion_type', 'event_submission')->count(),
            'total_outcomes' => $conversions->count(),
        ];
    }

    private function buildLinkData(AffiliateLink $link): ShareTrackingLinkData
    {
        $visits = $this->visitTouchpointsQueryForLink($link->id)->get();
        $conversions = $this->conversionsQueryForLink($link->id)->get();

        return new ShareTrackingLinkData(
            id: (string) $link->id,
            backend: 'affiliates',
            subjectType: (string) ($link->subject_type ?: 'page'),
            subjectId: data_get($link->subject_metadata, 'subject_id'),
            subjectKey: (string) ($link->subject_identifier ?: 'page:unknown'),
            destinationUrl: (string) $link->destination_url,
            canonicalUrl: (string) $link->tracking_url,
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

    private function uniqueVisitorsForLink(string $linkId): int
    {
        return $this->landingAttributionsQueryForLink($linkId)
            ->get()
            ->pluck('cookie_value')
            ->filter()
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
     *     unique_visitors: int,
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
                $providerOutbound = $outboundShares->filter(fn (AffiliateTouchpoint $touchpoint): bool => data_get($touchpoint->metadata, 'provider') === $provider);
                $providerVisits = $visits->filter(fn (AffiliateTouchpoint $touchpoint): bool => data_get($touchpoint->metadata, 'share_provider') === $provider);
                $providerConversions = $conversions->filter(fn (AffiliateConversion $conversion): bool => data_get($conversion->metadata, 'share_provider') === $provider);
                $providerAttributions = $attributions->filter(fn (AffiliateAttribution $attribution): bool => data_get($attribution->metadata, 'share_provider') === $provider);

                return [
                    'provider' => $provider,
                    'label' => $this->providerLabel($provider),
                    'outbound_shares' => $providerOutbound->count(),
                    'visits' => $providerVisits->count(),
                    'unique_visitors' => $this->uniqueVisitorsForProvider($providerVisits, $providerAttributions),
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
            ->merge($outboundShares->pluck('metadata.provider'))
            ->merge($visits->pluck('metadata.share_provider'))
            ->merge($conversions->pluck('metadata.share_provider'))
            ->merge($attributions->pluck('metadata.share_provider'))
            ->filter(fn (mixed $provider): bool => is_string($provider) && $provider !== '')
            ->unique()
            ->values();
    }

    /**
     * @param  Collection<int, AffiliateTouchpoint>  $visits
     * @param  Collection<int, AffiliateAttribution>  $attributions
     */
    private function uniqueVisitorsForProvider(Collection $visits, Collection $attributions): int
    {
        $visitVisitorKeys = $visits
            ->pluck('metadata.visitor_key')
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->unique();

        if ($visitVisitorKeys->isNotEmpty()) {
            return $visitVisitorKeys->count();
        }

        return $attributions
            ->pluck('cookie_value')
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
     * @return Builder<AffiliateTouchpoint>
     */
    private function outboundSharesQuery(Affiliate $affiliate): Builder
    {
        return AffiliateTouchpoint::query()
            ->where('affiliate_id', $affiliate->id)
            ->where('metadata->event_type', 'outbound_share');
    }

    /**
     * @return Builder<AffiliateTouchpoint>
     */
    private function visitTouchpointsQuery(Affiliate $affiliate): Builder
    {
        return AffiliateTouchpoint::query()
            ->where('affiliate_id', $affiliate->id)
            ->where('metadata->event_type', 'visit');
    }

    /**
     * @return Builder<AffiliateConversion>
     */
    private function conversionsQuery(Affiliate $affiliate): Builder
    {
        return AffiliateConversion::query()->where('affiliate_id', $affiliate->id);
    }

    /**
     * @return Builder<AffiliateAttribution>
     */
    private function landingAttributionsQuery(Affiliate $affiliate): Builder
    {
        return AffiliateAttribution::query()
            ->where('affiliate_id', $affiliate->id)
            ->where('metadata->tracking_mode', 'landing');
    }

    /**
     * @return Builder<AffiliateTouchpoint>
     */
    private function outboundSharesQueryForLink(string $linkId): Builder
    {
        return AffiliateTouchpoint::query()
            ->where('metadata->event_type', 'outbound_share')
            ->where('metadata->link_id', $linkId);
    }

    /**
     * @return Builder<AffiliateTouchpoint>
     */
    private function visitTouchpointsQueryForLink(string $linkId): Builder
    {
        return AffiliateTouchpoint::query()
            ->where('metadata->event_type', 'visit')
            ->where('metadata->link_id', $linkId);
    }

    /**
     * @return Builder<AffiliateConversion>
     */
    private function conversionsQueryForLink(string $linkId): Builder
    {
        return AffiliateConversion::query()->where('metadata->link_id', $linkId);
    }

    /**
     * @return Builder<AffiliateAttribution>
     */
    private function landingAttributionsQueryForLink(string $linkId): Builder
    {
        return AffiliateAttribution::query()
            ->where('metadata->tracking_mode', 'landing')
            ->where('metadata->link_id', $linkId);
    }

    private function mapOutcome(AffiliateConversion $conversion): ShareTrackingOutcomeData
    {
        return new ShareTrackingOutcomeData(
            id: (string) $conversion->id,
            backend: 'affiliates',
            linkId: (string) data_get($conversion->metadata, 'link_id', ''),
            attributionId: $conversion->affiliate_attribution_id,
            sharerUserId: data_get($conversion->metadata, 'sharer_user_id'),
            actorUserId: data_get($conversion->metadata, 'actor_user_id'),
            outcomeType: (string) ($conversion->conversion_type ?: 'unknown'),
            subjectType: $this->nullableString($conversion->subject_type) ?? $this->nullableString(data_get($conversion->metadata, 'subject_type')),
            subjectId: $this->nullableString($conversion->cart_identifier) ?? $this->nullableString(data_get($conversion->metadata, 'subject_id')),
            subjectKey: $this->nullableString($conversion->subject_identifier) ?? $this->nullableString(data_get($conversion->metadata, 'subject_key')),
            outcomeKey: (string) data_get($conversion->metadata, 'outcome_key', $conversion->external_reference),
            linkTitleSnapshot: $this->nullableString(data_get($conversion->metadata, 'link_title_snapshot')) ?? $this->nullableString($conversion->subject_title_snapshot),
            occurredAt: $conversion->occurred_at,
            metadata: $conversion->metadata ?? [],
        );
    }

    private function mapVisit(AffiliateTouchpoint $touchpoint): ShareTrackingVisitData
    {
        return new ShareTrackingVisitData(
            id: (string) $touchpoint->id,
            backend: 'affiliates',
            linkId: (string) data_get($touchpoint->metadata, 'link_id', ''),
            attributionId: $touchpoint->affiliate_attribution_id,
            visitorKey: data_get($touchpoint->metadata, 'visitor_key'),
            visitedUrl: (string) data_get($touchpoint->metadata, 'visited_url', ''),
            subjectType: $this->nullableString($touchpoint->subject_type) ?? $this->nullableString(data_get($touchpoint->metadata, 'subject_type')),
            subjectId: $this->nullableString($touchpoint->getAttributes()['cart_identifier'] ?? null) ?? $this->nullableString(data_get($touchpoint->metadata, 'subject_id')),
            subjectKey: $this->nullableString($touchpoint->subject_identifier) ?? $this->nullableString(data_get($touchpoint->metadata, 'subject_key')),
            visitKind: (string) data_get($touchpoint->metadata, 'visit_kind', 'navigated'),
            occurredAt: $touchpoint->touched_at,
            metadata: $touchpoint->metadata ?? [],
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
     *     unique_visitors: int,
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
            'unique_visitors' => 0,
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
            'speaker_follow' => __('Speaker follows'),
            'series_follow' => __('Series follows'),
            'reference_follow' => __('Reference follows'),
            'saved_search_created' => __('Saved searches created'),
            default => str($outcomeType)->replace('_', ' ')->headline()->toString(),
        };
    }
}
