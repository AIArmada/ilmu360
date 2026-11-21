<?php

declare(strict_types=1);

namespace App\Features\References\EntryPoints\Http;

use App\Features\Engagement\Api\EngagementSubjectType;
use App\Features\Engagement\Api\SubjectFollows;
use App\Features\Events\Api\EventCollections;
use App\Features\Events\Api\EventScheduleReads;
use App\Features\References\Api\Data\ReferenceDetailData;
use App\Features\References\Api\Data\ReferenceListData;
use App\Features\References\Api\Documentation\Schemas\ReferenceDirectoryResponse;
use App\Features\References\Api\ReferenceQueries;
use App\Features\References\Api\ReferenceSearch;
use App\Features\References\Models\Reference;
use App\Models\User;
use App\Platform\Api\ApiPagination;
use App\Platform\Api\Frontend\SearchPayloadTransformer;
use App\Platform\Api\Search\SearchRequestNormalizer;
use App\Platform\Http\FrontendController;
use App\Platform\Models\SlugOrUuidResolver;
use App\Platform\Search\PublicDiscovery;
use App\Shared\Cache\SelectionCatalogCache;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

final class ReferenceDirectoryController extends FrontendController
{
    /** @var list<string> */
    private const array REFERENCE_LIST_FIELDS = [
        'id',
        'slug',
        'title',
        'display_title',
        'authors',
        'author_ids',
        'type',
        'parent_id',
        'record_kind',
        'edition_number',
        'edition_label',
        'isbn',
        'language',
        'language_label',
        'url',
        'part_type',
        'part_number',
        'part_label',
        'is_part',
        'publisher',
        'publication_year',
        'status',
        'events_count',
        'front_cover_url',
        'is_following',
    ];

    public function __construct(
        private readonly ReferenceSearch $referenceSearch,
        private readonly ReferenceQueries $referenceQueries,
        private readonly EventCollections $eventCollections,
        private readonly SearchRequestNormalizer $searchRequestNormalizer,
        private readonly SlugOrUuidResolver $slugOrUuidResolver,
        private readonly PublicDiscovery $publicDiscovery,
        private readonly SubjectFollows $subjectFollows,
        private readonly SearchPayloadTransformer $searchPayloadTransformer,
    ) {}

    #[Group('Reference', 'Public reference directory and detail endpoints.')]
    #[Endpoint(
        title: 'List public references',
        description: 'Returns a paginated directory of references that have been published and are verified or pending. Supports search by title, author, or publisher, and a following filter.',
    )]
    #[QueryParameter('fields', 'Optional comma-separated top-level list fields to return. Supported fields: id, slug, title, display_title, authors, author_ids, type, parent_id, record_kind, edition_number, edition_label, isbn, language, language_label, url, part_type, part_number, part_label, is_part, publisher, publication_year, status, events_count, front_cover_url, is_following.', required: false, type: 'string', infer: false, example: 'id,display_title,authors,front_cover_url')]
    #[QueryParameter('search', 'Optional free-text search across public reference titles, authors, and publishers.', required: false, type: 'string', infer: false, example: 'Riyadus Solihin')]
    #[QueryParameter('following', 'When authenticated, restrict results to references followed by the current user.', required: false, type: 'boolean', infer: false, example: false)]
    #[QueryParameter('page', 'Pagination page number.', required: false, type: 'integer', infer: false, default: 1, example: 1)]
    #[QueryParameter('per_page', 'Pagination page size. Values are clamped to the server-supported maximum.', required: false, type: 'integer', infer: false, default: 12, example: 12)]
    #[Response(
        status: 200,
        description: 'Reference directory response.',
        type: ReferenceDirectoryResponse::class,
    )]
    public function references(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $search = $this->searchRequestNormalizer->normalizedString($request->query('search'));
        $followingOnly = $request->boolean('following');
        $perPage = ApiPagination::normalizePerPage($request->integer('per_page', 12), default: 12, max: 50);
        $requestedFields = $this->searchRequestNormalizer->requestedFields($request, self::REFERENCE_LIST_FIELDS, 'reference');
        $followingIds = $this->followingIds($user);
        $followingTotal = 0;

        $baseQuery = $this->baseReferenceQuery();

        if ($followingOnly) {
            $this->applyFollowingScope($baseQuery, $followingIds);
        } elseif ($user instanceof User) {
            $followedReferenceQuery = clone $baseQuery;

            $this->applyFollowingScope($followedReferenceQuery, $followingIds);

            $followingTotal = $this->referenceDirectoryTotalWithBase($request, $search, $followedReferenceQuery);
        }

        $paginator = $search === null
            ? $baseQuery
                ->orderBy('references.title')
                ->paginate($perPage)
            : $this->referenceDirectorySearchPaginator($request, $search, $perPage, $baseQuery);

        if ($followingOnly) {
            $followingTotal = $paginator->total();
        }

        $this->applyFollowStates($paginator->items(), $followingIds);
        $this->applyEventCounts($paginator->items());

        return response()->json([
            'data' => $paginator
                ->getCollection()
                ->map(fn (Reference $reference): array => $this->searchRequestNormalizer->sparsePayload($this->referenceListData($reference, $user), $requestedFields))
                ->all(),
            'meta' => [
                'pagination' => [
                    'page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
                'following' => ['total' => $followingTotal],
            ],
        ]);
    }

    #[Group('Reference', 'Public reference directory and detail endpoints.')]
    #[Endpoint(
        title: 'Get a public reference',
        description: 'Returns the public reference detail payload by slug or UUID, including upcoming and past events linked to that reference.',
    )]
    #[QueryParameter('include_family', 'Include events from the whole work family instead of the selected reference subtree.', required: false, type: 'boolean', infer: false, example: false)]
    public function showReference(Request $request, string $referenceKey): JsonResponse
    {
        $user = $this->currentUser($request);
        $now = now();

        $record = Reference::query()
            ->with(['media', 'socialProfiles', 'parentReference.parentReference'])
            ->tap(fn (Builder $query): Builder => $this->slugOrUuidResolver->apply($query, 'references.slug', $referenceKey))
            ->firstOrFail();

        abort_unless(
            $user instanceof User
                ? $user->can('view', $record)
                : $record->isPubliclyVisible(),
            404,
        );

        $referenceEventIds = $request->boolean('include_family')
            ? $this->referenceQueries->familyReferenceIds((string) $record->getKey())
            : $this->referenceQueries->defaultEventReferenceIds((string) $record->getKey());

        $upcomingPerPage = max(1, min($request->integer('upcoming_per_page', 10), 50));
        $upcomingEvents = $this->eventCollections->forReferences($referenceEventIds, past: false, perPage: $upcomingPerPage, now: $now);

        $pastPerPage = max(1, min($request->integer('past_per_page', 10), 50));
        $pastEvents = $this->eventCollections->forReferences($referenceEventIds, past: true, perPage: $pastPerPage, now: $now);

        return response()->json([
            'data' => [
                'reference' => $this->referenceDetailData($record, $user),
                'upcoming_events' => $upcomingEvents['items'],
                'upcoming_total' => $upcomingEvents['total'],
                'past_events' => $pastEvents['items'],
                'past_total' => $pastEvents['total'],
            ],
        ]);
    }

    /**
     * @return list<string>
     */
    private function followingIds(?User $user): array
    {
        return $user instanceof User
            ? $this->subjectFollows->followedSubjectIds((string) $user->id, EngagementSubjectType::Reference)
            : [];
    }

    /**
     * @param  iterable<int, Reference>  $references
     */
    private function applyEventCounts(iterable $references): void
    {
        $families = [];

        foreach ($references as $reference) {
            $referenceId = (string) $reference->getKey();
            $families[$referenceId] = $this->referenceQueries->defaultEventReferenceIds($referenceId);
        }

        $counts = app(EventScheduleReads::class)->publicEventCountsForReferenceFamilies($families);

        foreach ($references as $reference) {
            $reference->setAttribute('events_count', $counts[(string) $reference->getKey()] ?? 0);
        }
    }

    /**
     * @param  iterable<int, Reference>  $references
     * @param  list<string>  $followingIds
     */
    private function applyFollowStates(iterable $references, array $followingIds): void
    {
        foreach ($references as $reference) {
            $reference->setAttribute(
                'is_following',
                in_array((string) $reference->id, $followingIds, true),
            );
        }
    }

    /**
     * @param  Builder<Reference>  $query
     * @param  list<string>  $followingIds
     */
    private function applyFollowingScope(Builder $query, array $followingIds): void
    {
        if ($followingIds === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIn('references.id', $followingIds);
    }

    /**
     * @return Builder<Reference>
     */
    private function baseReferenceQuery(): Builder
    {
        return Reference::query()
            ->select('references.*')
            ->active()
            ->with(['media', 'parentReference.parentReference']);
    }

    /**
     * @param  Builder<Reference>  $base
     * @return LengthAwarePaginator<int, Reference>
     */
    private function referenceDirectorySearchPaginator(Request $request, string $search, int $perPage, Builder $base): LengthAwarePaginator
    {
        return $this->publicDiscovery->paginate(
            request: $request,
            search: $search,
            perPage: $perPage,
            base: $base,
            directBase: clone $base,
            adapter: $this->referenceSearch,
            idColumn: 'references.id',
            preserveDirectEngineOrder: true,
        );
    }

    /**
     * @param  Builder<Reference>  $base
     */
    private function referenceDirectoryTotalWithBase(Request $request, ?string $search, Builder $base): int
    {
        if ($search === null) {
            return (clone $base)->count();
        }

        return $this->referenceDirectorySearchPaginator($request, $search, 1, $base)->total();
    }

    /**
     * @return array<string, mixed>
     */
    private function referenceDetailData(Reference $reference, ?User $user): array
    {
        $authors = $reference->effectiveAuthorsStructured();

        return new ReferenceDetailData(
            id: (string) $reference->id,
            slug: (string) $reference->slug,
            title: (string) $reference->titleValue(),
            display_title: $reference->displayTitle(),
            authors: $authors,
            author_ids: array_column($authors, 'id'),
            type: $reference->typeValue(),
            parent_id: $reference->parent_id,
            record_kind: (string) $reference->record_kind,
            edition_number: $reference->edition_number,
            edition_label: $reference->edition_label,
            isbn: $reference->isbn,
            language: $reference->language,
            language_label: app(SelectionCatalogCache::class)->languageLabel($reference->language),
            url: $reference->url,
            part_type: $reference->partTypeValue(),
            part_number: $reference->partNumberValue(),
            part_label: $reference->partLabelValue(),
            is_part: $reference->isPart(),
            publisher: $reference->publisherValue(),
            publication_year: filled($reference->year) ? (string) $reference->year : null,
            description: $reference->descriptionValue(),
            status: (string) $reference->status,
            verified_by: $reference->getAttribute('verified_by'),
            is_following: $user?->isFollowing($reference) ?? false,
            media: [
                'front_cover_url' => $reference->getFirstMediaUrl('front_cover', 'thumb') ?: $reference->getFirstMediaUrl('front_cover'),
                'back_cover_url' => $reference->getFirstMediaUrl('back_cover', 'thumb') ?: $reference->getFirstMediaUrl('back_cover'),
            ],
            social_media: $this->searchPayloadTransformer->socialMediaData($reference->socialProfiles),
        )->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    private function referenceListData(Reference $reference, ?User $user = null): array
    {
        $attributes = $reference->getAttributes();
        $isFollowing = array_key_exists('is_following', $attributes)
            ? (bool) $attributes['is_following']
            : ($user?->isFollowing($reference) ?? false);
        $authors = $reference->effectiveAuthorsStructured();

        return new ReferenceListData(
            id: (string) $reference->id,
            slug: (string) $reference->slug,
            title: (string) $reference->titleValue(),
            display_title: $reference->displayTitle(),
            authors: $authors,
            author_ids: array_column($authors, 'id'),
            type: $reference->typeValue(),
            parent_id: $reference->parent_id,
            record_kind: (string) $reference->record_kind,
            edition_number: $reference->edition_number,
            edition_label: $reference->edition_label,
            isbn: $reference->isbn,
            language: $reference->language,
            language_label: app(SelectionCatalogCache::class)->languageLabel($reference->language),
            url: $reference->url,
            part_type: $reference->partTypeValue(),
            part_number: $reference->partNumberValue(),
            part_label: $reference->partLabelValue(),
            is_part: $reference->isPart(),
            publisher: $reference->publisherValue(),
            publication_year: filled($reference->year) ? (string) $reference->year : null,
            status: (string) $reference->status,
            verified_by: $reference->getAttribute('verified_by'),
            events_count: (int) ($reference->events_count ?? 0),
            front_cover_url: $reference->getFirstMediaUrl('front_cover', 'thumb') ?: ($reference->getFirstMediaUrl('front_cover') ?: null),
            is_following: $isFollowing,
        )->toArray();
    }
}
