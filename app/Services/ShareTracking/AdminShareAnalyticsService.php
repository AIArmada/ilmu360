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
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * @phpstan-type ShareAnalyticsSummary array{
 *     affiliates: int,
 *     shared_links: int,
 *     outbound_shares: int,
 *     visits: int,
 *     unique_visitors: int,
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
 *     unique_visitors: int,
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
 *     unique_visitors: int,
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
 *     unique_visitors: int,
 *     outcomes: int,
 *     last_shared_at: string|null
 * }
 * @phpstan-type ShareRecentVisitRow array{
 *     visited_url: string,
 *     provider: string,
 *     visit_kind: string,
 *     visitor_key: mixed,
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
    ) {}

    /**
     * @return ShareAnalyticsDashboard
     */
    public function dashboard(
        int $topSharersLimit = 8,
        int $topLinksLimit = 10,
        int $recentVisitsLimit = 10,
        int $recentOutcomesLimit = 10,
    ): array {
        $affiliates = Affiliate::query()->withoutGlobalScope(OwnerScope::class)->get();
        $links = AffiliateLink::query()->withoutGlobalScope(OwnerScope::class)->latest('updated_at')->get();
        $landingAttributions = AffiliateAttribution::query()
            ->withoutGlobalScope(OwnerScope::class)
            ->where('metadata->tracking_mode', 'landing')
            ->latest('last_seen_at')
            ->get();
        $outboundShares = AffiliateTouchpoint::query()
            ->withoutGlobalScope(OwnerScope::class)
            ->where('metadata->event_type', 'outbound_share')
            ->latest('touched_at')
            ->get();
        $visits = AffiliateTouchpoint::query()
            ->withoutGlobalScope(OwnerScope::class)
            ->where('metadata->event_type', 'visit')
            ->latest('touched_at')
            ->get();
        $conversions = AffiliateConversion::query()
            ->withoutGlobalScope(OwnerScope::class)
            ->latest('occurred_at')
            ->get();

        $users = $this->usersForAffiliates($affiliates);

        return [
            'summary' => $this->summary($affiliates, $links, $landingAttributions, $outboundShares, $visits, $conversions),
            'provider_breakdown' => $this->providerBreakdown($outboundShares, $visits, $landingAttributions, $conversions),
            'top_sharers' => $this->topSharers($affiliates, $users, $links, $landingAttributions, $visits, $conversions, $topSharersLimit),
            'top_links' => $this->topLinks($links, $affiliates, $users, $landingAttributions, $outboundShares, $visits, $conversions, $topLinksLimit),
            'recent_visits' => $this->recentVisits($visits, $affiliates, $users, $recentVisitsLimit),
            'recent_outcomes' => $this->recentOutcomes($conversions, $affiliates, $users, $recentOutcomesLimit),
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
     * @param  Collection<int, AffiliateLink>  $links
     * @param  Collection<int, AffiliateAttribution>  $landingAttributions
     * @param  Collection<int, AffiliateTouchpoint>  $outboundShares
     * @param  Collection<int, AffiliateTouchpoint>  $visits
     * @param  Collection<int, AffiliateConversion>  $conversions
     * @return ShareAnalyticsSummary
     */
    private function summary(
        Collection $affiliates,
        Collection $links,
        Collection $landingAttributions,
        Collection $outboundShares,
        Collection $visits,
        Collection $conversions,
    ): array {
        return [
            'affiliates' => $affiliates->count(),
            'shared_links' => $links->count(),
            'outbound_shares' => $outboundShares->count(),
            'visits' => $visits->count(),
            'unique_visitors' => $landingAttributions->pluck('cookie_value')->filter()->unique()->count(),
            'signups' => $conversions->where('conversion_type', 'signup')->count(),
            'event_registrations' => $conversions->where('conversion_type', 'event_registration')->count(),
            'event_checkins' => $conversions->where('conversion_type', 'event_checkin')->count(),
            'event_submissions' => $conversions->where('conversion_type', 'event_submission')->count(),
            'total_outcomes' => $conversions->count(),
        ];
    }

    /**
     * @param  Collection<int, AffiliateTouchpoint>  $outboundShares
     * @param  Collection<int, AffiliateTouchpoint>  $visits
     * @param  Collection<int, AffiliateAttribution>  $landingAttributions
     * @param  Collection<int, AffiliateConversion>  $conversions
     * @return list<ShareProviderBreakdownRow>
     */
    private function providerBreakdown(
        Collection $outboundShares,
        Collection $visits,
        Collection $landingAttributions,
        Collection $conversions,
    ): array {
        return $this->providersForBreakdown($outboundShares, $visits, $landingAttributions, $conversions)
            ->map(function (string $provider) use ($outboundShares, $visits, $landingAttributions, $conversions): array {
                $providerOutbound = $outboundShares->filter(fn (AffiliateTouchpoint $touchpoint): bool => data_get($touchpoint->metadata, 'provider') === $provider);
                $providerVisits = $visits->filter(fn (AffiliateTouchpoint $touchpoint): bool => data_get($touchpoint->metadata, 'share_provider') === $provider);
                $providerAttributions = $landingAttributions->filter(fn (AffiliateAttribution $attribution): bool => data_get($attribution->metadata, 'share_provider') === $provider);
                $providerConversions = $conversions->filter(fn (AffiliateConversion $conversion): bool => data_get($conversion->metadata, 'share_provider') === $provider);

                return [
                    'provider' => $provider,
                    'label' => $this->providerLabel($provider),
                    'outbound_shares' => $providerOutbound->count(),
                    'visits' => $providerVisits->count(),
                    'unique_visitors' => $providerAttributions->pluck('cookie_value')->filter()->unique()->count(),
                    'outcomes' => $providerConversions->count(),
                    'signups' => $providerConversions->where('conversion_type', 'signup')->count(),
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
    }

    /**
     * @param  Collection<int, AffiliateTouchpoint>  $outboundShares
     * @param  Collection<int, AffiliateTouchpoint>  $visits
     * @param  Collection<int, AffiliateAttribution>  $landingAttributions
     * @param  Collection<int, AffiliateConversion>  $conversions
     * @return Collection<int, non-empty-string>
     */
    private function providersForBreakdown(
        Collection $outboundShares,
        Collection $visits,
        Collection $landingAttributions,
        Collection $conversions,
    ): Collection {
        return collect($this->shareTrackingService->supportedProviders())
            ->merge($outboundShares->pluck('metadata.provider'))
            ->merge($visits->pluck('metadata.share_provider'))
            ->merge($landingAttributions->pluck('metadata.share_provider'))
            ->merge($conversions->pluck('metadata.share_provider'))
            ->filter(fn (mixed $provider): bool => is_string($provider) && $provider !== '')
            ->unique()
            ->values();
    }

    /**
     * @param  Collection<int, Affiliate>  $affiliates
     * @param  Collection<string, User>  $users
     * @param  Collection<int, AffiliateLink>  $links
     * @param  Collection<int, AffiliateAttribution>  $landingAttributions
     * @param  Collection<int, AffiliateTouchpoint>  $visits
     * @param  Collection<int, AffiliateConversion>  $conversions
     * @return list<ShareTopSharerRow>
     */
    private function topSharers(
        Collection $affiliates,
        Collection $users,
        Collection $links,
        Collection $landingAttributions,
        Collection $visits,
        Collection $conversions,
        int $limit,
    ): array {
        $linksByAffiliate = $links->groupBy('affiliate_id');
        $visitsByAffiliate = $visits->groupBy('affiliate_id');
        $attributionsByAffiliate = $landingAttributions->groupBy('affiliate_id');
        $conversionsByAffiliate = $conversions->groupBy('affiliate_id');

        return $affiliates
            ->map(function (Affiliate $affiliate) use ($users, $linksByAffiliate, $visitsByAffiliate, $attributionsByAffiliate, $conversionsByAffiliate): array {
                $user = $users->get($this->userIdForAffiliate($affiliate));
                $affiliateLinks = $linksByAffiliate->get($affiliate->id, collect());
                $affiliateVisits = $visitsByAffiliate->get($affiliate->id, collect());
                $affiliateAttributions = $attributionsByAffiliate->get($affiliate->id, collect());
                $affiliateConversions = $conversionsByAffiliate->get($affiliate->id, collect());
                $latestActivityAt = collect([
                    $affiliateLinks->max('updated_at'),
                    $affiliateVisits->max('touched_at'),
                    $affiliateConversions->max('occurred_at'),
                ])->filter()->sortByDesc(fn (CarbonInterface $value): int => $value->getTimestamp())->first();

                return [
                    'affiliate_id' => $affiliate->id,
                    'affiliate_code' => $affiliate->code,
                    'user_name' => $user instanceof User ? $user->name : $affiliate->name,
                    'user_email' => $user instanceof User ? $user->email : $affiliate->contact_email,
                    'links' => $affiliateLinks->count(),
                    'visits' => $affiliateVisits->count(),
                    'unique_visitors' => $affiliateAttributions->pluck('cookie_value')->filter()->unique()->count(),
                    'outcomes' => $affiliateConversions->count(),
                    'signups' => $affiliateConversions->where('conversion_type', 'signup')->count(),
                    'event_registrations' => $affiliateConversions->where('conversion_type', 'event_registration')->count(),
                    'last_activity_at' => $latestActivityAt?->toDateTimeString(),
                ];
            })
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
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, AffiliateLink>  $links
     * @param  Collection<int, Affiliate>  $affiliates
     * @param  Collection<string, User>  $users
     * @param  Collection<int, AffiliateAttribution>  $landingAttributions
     * @param  Collection<int, AffiliateTouchpoint>  $outboundShares
     * @param  Collection<int, AffiliateTouchpoint>  $visits
     * @param  Collection<int, AffiliateConversion>  $conversions
     * @return list<ShareTopLinkRow>
     */
    private function topLinks(
        Collection $links,
        Collection $affiliates,
        Collection $users,
        Collection $landingAttributions,
        Collection $outboundShares,
        Collection $visits,
        Collection $conversions,
        int $limit,
    ): array {
        $affiliatesById = $affiliates->keyBy('id');
        $visitCountsByLink = $visits->groupBy(fn (AffiliateTouchpoint $touchpoint): string => (string) data_get($touchpoint->metadata, 'link_id'))->map->count();
        $outboundCountsByLink = $outboundShares->groupBy(fn (AffiliateTouchpoint $touchpoint): string => (string) data_get($touchpoint->metadata, 'link_id'))->map->count();
        $uniqueVisitorsByLink = $landingAttributions->groupBy(fn (AffiliateAttribution $attribution): string => (string) data_get($attribution->metadata, 'link_id'))->map(
            fn (Collection $linkAttributions): int => $linkAttributions->pluck('cookie_value')->filter()->unique()->count(),
        );
        $conversionsByLink = $conversions->groupBy(fn (AffiliateConversion $conversion): string => (string) data_get($conversion->metadata, 'link_id'));

        return $links
            ->map(function (AffiliateLink $link) use ($affiliatesById, $users, $visitCountsByLink, $outboundCountsByLink, $uniqueVisitorsByLink, $conversionsByLink): array {
                $affiliate = $affiliatesById->get($link->affiliate_id);
                $user = $affiliate instanceof Affiliate ? $users->get($this->userIdForAffiliate($affiliate)) : null;
                $linkConversions = $conversionsByLink->get($link->id, collect());

                return [
                    'link_id' => $link->id,
                    'title_snapshot' => $link->subject_title_snapshot,
                    'subject_type' => $link->subject_type ?: 'page',
                    'destination_url' => $link->destination_url,
                    'affiliate_code' => $affiliate?->code,
                    'sharer_name' => $user instanceof User ? $user->name : $affiliate?->name,
                    'outbound_shares' => (int) ($outboundCountsByLink->get($link->id) ?? 0),
                    'visits' => (int) ($visitCountsByLink->get($link->id) ?? 0),
                    'unique_visitors' => (int) ($uniqueVisitorsByLink->get($link->id) ?? 0),
                    'outcomes' => $linkConversions->count(),
                    'last_shared_at' => $link->updated_at?->toDateTimeString(),
                ];
            })
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
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, AffiliateTouchpoint>  $visits
     * @param  Collection<int, Affiliate>  $affiliates
     * @param  Collection<string, User>  $users
     * @return list<ShareRecentVisitRow>
     */
    private function recentVisits(Collection $visits, Collection $affiliates, Collection $users, int $limit): array
    {
        $affiliatesById = $affiliates->keyBy('id');

        return $visits
            ->take($limit)
            ->map(function (AffiliateTouchpoint $visit) use ($affiliatesById, $users): array {
                $affiliate = $affiliatesById->get($visit->affiliate_id);
                $user = $affiliate instanceof Affiliate ? $users->get($this->userIdForAffiliate($affiliate)) : null;

                return [
                    'visited_url' => (string) data_get($visit->metadata, 'visited_url', ''),
                    'provider' => $this->providerLabel((string) data_get($visit->metadata, 'share_provider', 'direct')),
                    'visit_kind' => str((string) data_get($visit->metadata, 'visit_kind', 'visit'))->replace('_', ' ')->headline()->toString(),
                    'visitor_key' => data_get($visit->metadata, 'visitor_key'),
                    'sharer_name' => $user instanceof User ? $user->name : $affiliate?->name,
                    'occurred_at' => $visit->touched_at?->toDateTimeString(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, AffiliateConversion>  $conversions
     * @param  Collection<int, Affiliate>  $affiliates
     * @param  Collection<string, User>  $users
     * @return list<ShareRecentOutcomeRow>
     */
    private function recentOutcomes(Collection $conversions, Collection $affiliates, Collection $users, int $limit): array
    {
        $affiliatesById = $affiliates->keyBy('id');

        return $conversions
            ->take($limit)
            ->map(function (AffiliateConversion $conversion) use ($affiliatesById, $users): array {
                $affiliate = $affiliatesById->get($conversion->affiliate_id);
                $user = $affiliate instanceof Affiliate ? $users->get($this->userIdForAffiliate($affiliate)) : null;

                return [
                    'conversion_type' => str((string) $conversion->conversion_type)->replace('_', ' ')->headline()->toString(),
                    'subject_type' => str((string) ($conversion->subject_type ?: data_get($conversion->metadata, 'subject_type', 'page')))->headline()->toString(),
                    'title_snapshot' => (string) (data_get($conversion->metadata, 'link_title_snapshot') ?: $conversion->subject_title_snapshot ?: config('app.name')),
                    'sharer_name' => $user instanceof User ? $user->name : $affiliate?->name,
                    'occurred_at' => $conversion->occurred_at?->toDateTimeString(),
                ];
            })
            ->values()
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
