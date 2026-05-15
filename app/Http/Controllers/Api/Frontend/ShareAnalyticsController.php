<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Frontend;

use App\Data\ShareTracking\ShareTrackingLinkData;
use App\Data\ShareTracking\ShareTrackingOutcomeData;
use App\Data\ShareTracking\ShareTrackingVisitData;
use App\Enums\DawahShareOutcomeType;
use App\Enums\DawahShareSubjectType;
use App\Services\ShareTrackingAnalyticsService;
use App\Services\ShareTrackingService;
use App\Support\Api\ApiPagination;
use Carbon\CarbonInterface;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

#[Group('Share', 'Authenticated share analytics endpoints that mirror the private Dawah impact dashboard for mobile clients.')]
class ShareAnalyticsController extends FrontendController
{
    public function __construct(
        private readonly ShareTrackingAnalyticsService $analytics,
        private readonly ShareTrackingService $shareTrackingService,
    ) {}

    #[Endpoint(
        title: 'Get share analytics dashboard',
        description: 'Returns the authenticated share dashboard data used by the web Dawah impact view, including summary metrics, channel breakdowns, top shared subjects, top links, recent responses, and a paginated link library for iOS and Android clients.',
    )]
    #[QueryParameter('type', 'Optional shared subject filter. Supported values match the web dashboard: all, event, institution, speaker, series, reference, search, page.', required: false, type: 'string', infer: false, default: 'all', example: 'event')]
    #[QueryParameter('sort', 'Optional link sort mode. Supported values: recent, visits, signups, registrations, checkins, submissions.', required: false, type: 'string', infer: false, default: 'recent', example: 'visits')]
    #[QueryParameter('status', 'Optional link activity filter. Supported values: all, active, inactive.', required: false, type: 'string', infer: false, default: 'all', example: 'active')]
    #[QueryParameter('outcome', 'Optional outcome filter. Supported values: all, signup, event_registration, event_checkin, event_submission, event_save, event_going, institution_follow, speaker_follow, series_follow, reference_follow, saved_search_created.', required: false, type: 'string', infer: false, default: 'all', example: 'signup')]
    #[QueryParameter('page', 'Pagination page number for the link library.', required: false, type: 'integer', infer: false, default: 1, example: 1)]
    #[QueryParameter('per_page', 'Pagination page size for the link library. Values are clamped to the supported maximum.', required: false, type: 'integer', infer: false, default: 12, example: 12)]
    public function index(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        $validated = $request->validate([
            'type' => ['nullable', 'string', Rule::in(['all', ...$this->subjectTypes()])],
            'sort' => ['nullable', 'string', Rule::in($this->sorts())],
            'status' => ['nullable', 'string', Rule::in($this->statuses())],
            'outcome' => ['nullable', 'string', Rule::in(['all', ...$this->outcomeTypes()])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $subjectType = (string) ($validated['type'] ?? 'all');
        $sort = (string) ($validated['sort'] ?? 'recent');
        $status = (string) ($validated['status'] ?? 'all');
        $outcomeType = (string) ($validated['outcome'] ?? 'all');
        $page = (int) ($validated['page'] ?? 1);
        $perPage = ApiPagination::normalizePerPage((int) ($validated['per_page'] ?? 12), default: 12, max: 50);

        $filteredLinks = $this->analytics->linksForUser(
            $user,
            $sort,
            $subjectType,
            $status,
            $outcomeType,
        );
        $topLinks = $this->analytics->linksForUser($user, 'visits')->take(3)->values();
        $paginator = $this->paginateLinks($filteredLinks, $page, $perPage, $request);

        return response()->json([
            'data' => [
                'summary' => $this->analytics->summaryForUser($user),
                'provider_breakdown' => $this->arrayRows($this->analytics->providerBreakdownForUser($user)),
                'subject_summaries' => $this->arrayRows($this->analytics->subjectSummariesForUser($user)),
                'top_subjects' => $this->arrayRows($this->analytics->topSubjectsForUser($user)),
                'top_links' => $this->linkRows($topLinks),
                'recent_responses' => $this->outcomeRows($this->analytics->recentOutcomesForUser($user, 12)),
                'links' => [
                    'data' => $this->linkRows($paginator->getCollection()),
                    'meta' => [
                        'pagination' => ApiPagination::paginationMeta($paginator),
                    ],
                ],
            ],
            'meta' => [
                'filters' => [
                    'type' => $subjectType,
                    'sort' => $sort,
                    'status' => $status,
                    'outcome' => $outcomeType,
                    'page' => $page,
                    'per_page' => $perPage,
                ],
                'request_id' => $this->requestId($request),
            ],
        ]);
    }

    #[Endpoint(
        title: 'Get share analytics link detail',
        description: 'Returns the authenticated analytics detail for one tracked share link, including share-again links, daily performance, provider breakdown, recent visits, recent responses, and activity window data.',
    )]
    #[PathParameter('link', 'Tracked share link UUID returned by the analytics dashboard.', example: '18c51525-c8ed-4bb5-92df-9d842a7c4441')]
    public function show(string $link, Request $request): JsonResponse
    {
        $user = $this->requireUser($request);

        validator([
            'link' => $link,
        ], [
            'link' => ['required', 'uuid'],
        ])->validate();

        $linkData = $this->analytics->findLinkForUser($user, $link);

        abort_unless($linkData instanceof ShareTrackingLinkData, 404);

        $shareText = trim(($linkData->titleSnapshot ?: config('app.name')).' - '.config('app.name'));

        return response()->json([
            'data' => [
                'link' => $this->linkRow($linkData),
                'summary' => $this->analytics->summaryForLink($linkData),
                'provider_breakdown' => $this->arrayRows($this->analytics->providerBreakdownForLink($linkData)),
                'share_links' => $this->shareTrackingService->redirectLinks($linkData->destinationUrl, $shareText, $linkData->titleSnapshot),
                'daily_performance' => $this->arrayRows($this->analytics->dailyPerformanceForLink($linkData, 14)),
                'outcome_breakdown' => $this->arrayRows($this->analytics->outcomeBreakdownForLink($linkData)),
                'recent_visits' => $this->visitRows($this->analytics->recentVisitsForLink($linkData, 20)),
                'recent_outcomes' => $this->outcomeRows($this->analytics->recentOutcomesForLink($linkData, 20)),
                'activity_window' => $this->activityWindowRow($this->analytics->activityWindowForLink($linkData)),
            ],
            'meta' => [
                'request_id' => $this->requestId($request),
            ],
        ]);
    }

    /**
     * @return list<string>
     */
    private function subjectTypes(): array
    {
        return array_map(
            static fn (DawahShareSubjectType $type): string => $type->value,
            DawahShareSubjectType::cases(),
        );
    }

    /**
     * @return list<string>
     */
    private function sorts(): array
    {
        return ['recent', 'visits', 'signups', 'registrations', 'checkins', 'submissions'];
    }

    /**
     * @return list<string>
     */
    private function statuses(): array
    {
        return ['all', 'active', 'inactive'];
    }

    /**
     * @return list<string>
     */
    private function outcomeTypes(): array
    {
        return array_map(
            static fn (DawahShareOutcomeType $type): string => $type->value,
            DawahShareOutcomeType::cases(),
        );
    }

    /**
     * @param  Collection<int, ShareTrackingLinkData>  $links
     * @return LengthAwarePaginator<int, ShareTrackingLinkData>
     */
    private function paginateLinks(Collection $links, int $page, int $perPage, Request $request): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            $links->forPage($page, $perPage)->values(),
            $links->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'pageName' => 'page',
            ],
        );
    }

    /**
     * @template T of array<string, mixed>
     *
     * @param  Collection<int, T>  $rows
     * @return list<T>
     */
    private function arrayRows(Collection $rows): array
    {
        return $rows->values()->all();
    }

    /**
     * @param  Collection<int, ShareTrackingLinkData>  $links
     * @return list<array<string, mixed>>
     */
    private function linkRows(Collection $links): array
    {
        return $links
            ->map(fn (ShareTrackingLinkData $link): array => $this->linkRow($link))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function linkRow(ShareTrackingLinkData $link): array
    {
        return [
            'id' => $link->id,
            'backend' => $link->backend,
            'subject_type' => $link->subjectType,
            'subject_id' => $link->subjectId,
            'subject_key' => $link->subjectKey,
            'destination_url' => $link->destinationUrl,
            'canonical_url' => $link->canonicalUrl,
            'title_snapshot' => $link->titleSnapshot,
            'last_shared_at' => $this->optionalDateTimeString($link->lastSharedAt),
            'outbound_shares' => $link->outboundShares,
            'visits_count' => $link->visitsCount,
            'outcomes_count' => $link->outcomesCount,
            'signups_count' => $link->signupsCount,
            'event_registrations_count' => $link->eventRegistrationsCount,
            'event_checkins_count' => $link->eventCheckinsCount,
            'event_submissions_count' => $link->eventSubmissionsCount,
            'latest_visit_at' => $this->optionalDateTimeString($link->latestVisitAt),
            'latest_outcome_at' => $this->optionalDateTimeString($link->latestOutcomeAt),
            'latest_activity_at' => $this->optionalDateTimeString($link->latestActivityAt()),
        ];
    }

    /**
     * @param  Collection<int, ShareTrackingVisitData>  $visits
     * @return list<array<string, mixed>>
     */
    private function visitRows(Collection $visits): array
    {
        return $visits
            ->map(fn (ShareTrackingVisitData $visit): array => $this->visitRow($visit))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function visitRow(ShareTrackingVisitData $visit): array
    {
        return [
            'id' => $visit->id,
            'backend' => $visit->backend,
            'link_id' => $visit->linkId,
            'attribution_id' => $visit->attributionId,
            'visitor_key' => $visit->visitorKey,
            'visited_url' => $visit->visitedUrl,
            'subject_type' => $visit->subjectType,
            'subject_id' => $visit->subjectId,
            'subject_key' => $visit->subjectKey,
            'visit_kind' => $visit->visitKind,
            'occurred_at' => $this->optionalDateTimeString($visit->occurredAt),
            'metadata' => $visit->metadata,
        ];
    }

    /**
     * @param  Collection<int, ShareTrackingOutcomeData>  $outcomes
     * @return list<array<string, mixed>>
     */
    private function outcomeRows(Collection $outcomes): array
    {
        return $outcomes
            ->map(fn (ShareTrackingOutcomeData $outcome): array => $this->outcomeRow($outcome))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function outcomeRow(ShareTrackingOutcomeData $outcome): array
    {
        return [
            'id' => $outcome->id,
            'backend' => $outcome->backend,
            'link_id' => $outcome->linkId,
            'attribution_id' => $outcome->attributionId,
            'sharer_user_id' => $outcome->sharerUserId,
            'actor_user_id' => $outcome->actorUserId,
            'outcome_type' => $outcome->outcomeType,
            'subject_type' => $outcome->subjectType,
            'subject_id' => $outcome->subjectId,
            'subject_key' => $outcome->subjectKey,
            'outcome_key' => $outcome->outcomeKey,
            'link_title_snapshot' => $outcome->linkTitleSnapshot,
            'occurred_at' => $this->optionalDateTimeString($outcome->occurredAt),
            'metadata' => $outcome->metadata,
        ];
    }

    /**
     * @param  array{first_seen_at: CarbonInterface|null, last_visit_at: CarbonInterface|null, last_outcome_at: CarbonInterface|null, latest_activity_at: CarbonInterface|null}  $window
     * @return array<string, mixed>
     */
    private function activityWindowRow(array $window): array
    {
        return [
            'first_seen_at' => $this->optionalDateTimeString($window['first_seen_at']),
            'last_visit_at' => $this->optionalDateTimeString($window['last_visit_at']),
            'last_outcome_at' => $this->optionalDateTimeString($window['last_outcome_at']),
            'latest_activity_at' => $this->optionalDateTimeString($window['latest_activity_at']),
        ];
    }
}
