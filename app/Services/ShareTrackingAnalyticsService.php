<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\ShareTracking\ShareTrackingLinkData;
use App\Data\ShareTracking\ShareTrackingOutcomeData;
use App\Data\ShareTracking\ShareTrackingVisitData;
use App\Models\User;
use App\Services\ShareTracking\AffiliatesShareTrackingAnalyticsService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final readonly class ShareTrackingAnalyticsService
{
    public function __construct(
        private AffiliatesShareTrackingAnalyticsService $affiliates,
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
        return $this->affiliates->summaryForUser($user);
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
        return $this->affiliates->providerBreakdownForUser($user);
    }

    /**
     * @return Collection<int, array{
     *     subject_type: string,
     *     label: string,
     *     links: int,
     *     visits: int,
     *     signups: int,
     *     event_registrations: int,
     *     event_checkins: int,
     *     event_submissions: int,
     *     total_outcomes: int
     * }>
     */
    public function subjectSummariesForUser(User $user): Collection
    {
        return $this->linksForUser($user)
            ->groupBy(fn (ShareTrackingLinkData $link): string => $link->subjectType)
            ->map(fn (Collection $links, string $subjectType): array => [
                'subject_type' => $subjectType,
                'label' => $this->subjectTypeLabel($subjectType),
                'links' => $links->count(),
                'visits' => (int) $links->sum(fn (ShareTrackingLinkData $link): int => $link->visitsCount),
                'signups' => (int) $links->sum(fn (ShareTrackingLinkData $link): int => $link->signupsCount),
                'event_registrations' => (int) $links->sum(fn (ShareTrackingLinkData $link): int => $link->eventRegistrationsCount),
                'event_checkins' => (int) $links->sum(fn (ShareTrackingLinkData $link): int => $link->eventCheckinsCount),
                'event_submissions' => (int) $links->sum(fn (ShareTrackingLinkData $link): int => $link->eventSubmissionsCount),
                'total_outcomes' => (int) $links->sum(fn (ShareTrackingLinkData $link): int => $link->outcomesCount),
            ])
            ->sortByDesc('visits')
            ->values();
    }

    /**
     * @return Collection<int, array{
     *     subject_type: string,
     *     subject_key: string,
     *     title_snapshot: string,
     *     type_label: string,
     *     links: int,
     *     visits: int,
     *     signups: int,
     *     event_registrations: int,
     *     event_checkins: int,
     *     event_submissions: int,
     *     total_outcomes: int
     * }>
     */
    public function topSubjectsForUser(User $user, int $limit = 5): Collection
    {
        return $this->linksForUser($user)
            ->groupBy(fn (ShareTrackingLinkData $link): string => $link->subjectKey)
            ->map(function (Collection $links, string $subjectKey): array {
                /** @var ShareTrackingLinkData $first */
                $first = $links->first();

                return [
                    'subject_type' => $first->subjectType,
                    'subject_key' => $subjectKey,
                    'title_snapshot' => $first->titleSnapshot,
                    'type_label' => $this->subjectTypeLabel($first->subjectType),
                    'links' => $links->count(),
                    'visits' => (int) $links->sum(fn (ShareTrackingLinkData $link): int => $link->visitsCount),
                    'signups' => (int) $links->sum(fn (ShareTrackingLinkData $link): int => $link->signupsCount),
                    'event_registrations' => (int) $links->sum(fn (ShareTrackingLinkData $link): int => $link->eventRegistrationsCount),
                    'event_checkins' => (int) $links->sum(fn (ShareTrackingLinkData $link): int => $link->eventCheckinsCount),
                    'event_submissions' => (int) $links->sum(fn (ShareTrackingLinkData $link): int => $link->eventSubmissionsCount),
                    'total_outcomes' => (int) $links->sum(fn (ShareTrackingLinkData $link): int => $link->outcomesCount),
                ];
            })
            ->sort(fn (array $left, array $right): int => [
                $right['total_outcomes'],
                $right['visits'],
                $right['event_checkins'],
                $right['event_registrations'],
            ] <=> [
                $left['total_outcomes'],
                $left['visits'],
                $left['event_checkins'],
                $left['event_registrations'],
            ])
            ->take($limit)
            ->values();
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
        return $this->affiliates->linksForUser($user, $sort, $subjectType, $status, $outcomeType);
    }

    /**
     * @return Collection<int, ShareTrackingOutcomeData>
     */
    public function recentOutcomesForUser(User $user, int $limit = 12): Collection
    {
        return $this->affiliates->recentOutcomesForUser($user, $limit);
    }

    public function findLinkForUser(User $user, string $linkId): ?ShareTrackingLinkData
    {
        return $this->affiliates->findLinkForUser($user, $linkId);
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
        return $this->affiliates->summaryForLink($link);
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
        return $this->affiliates->providerBreakdownForLink($link);
    }

    /**
     * @return Collection<int, array{date: string, visits: int, outcomes: int, signups: int, event_registrations: int, event_checkins: int, event_submissions: int}>
     */
    public function dailyPerformanceForLink(ShareTrackingLinkData $link, int $days = 14): Collection
    {
        return $this->affiliates->dailyPerformanceForLink($link, $days);
    }

    /**
     * @return Collection<int, array{outcome_type: string, label: string, count: int}>
     */
    public function outcomeBreakdownForLink(ShareTrackingLinkData $link): Collection
    {
        return $this->affiliates->outcomeBreakdownForLink($link);
    }

    /**
     * @return Collection<int, ShareTrackingVisitData>
     */
    public function recentVisitsForLink(ShareTrackingLinkData $link, int $limit = 20): Collection
    {
        return $this->affiliates->recentVisitsForLink($link, $limit);
    }

    /**
     * @return Collection<int, ShareTrackingOutcomeData>
     */
    public function recentOutcomesForLink(ShareTrackingLinkData $link, int $limit = 20): Collection
    {
        return $this->affiliates->recentOutcomesForLink($link, $limit);
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
        return $this->affiliates->activityWindowForLink($link);
    }

    private function subjectTypeLabel(string $subjectType): string
    {
        return match ($subjectType) {
            'event' => __('Events'),
            'institution' => __('Institutions'),
            'speaker' => __('Speakers'),
            'series' => __('Series'),
            'reference' => __('References'),
            'search' => __('Search Results'),
            default => __('Pages'),
        };
    }
}
