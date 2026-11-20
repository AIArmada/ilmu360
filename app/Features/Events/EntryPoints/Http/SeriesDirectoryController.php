<?php

declare(strict_types=1);

namespace App\Features\Events\EntryPoints\Http;

use App\Features\Events\Api\EventCollections;
use App\Features\Events\EntryPoints\Http\Data\SeriesDetailData;
use App\Features\Events\Models\Series;
use App\Platform\Http\FrontendController;
use App\Platform\Models\SlugOrUuidResolver;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SeriesDirectoryController extends FrontendController
{
    public function __construct(
        private readonly EventCollections $eventCollections,
        private readonly SlugOrUuidResolver $slugOrUuidResolver,
    ) {}

    #[Group('Series', 'Public series detail endpoints for visible series and their related events.')]
    #[Endpoint(
        title: 'Get a public series',
        description: 'Returns the public series detail payload by slug or UUID, including upcoming and past events in the series.',
    )]
    public function showSeries(Request $request, string $series): JsonResponse
    {
        $user = $this->currentUser($request);
        $now = now();
        $canBypassVisibility = $user?->hasAnyRole(['super_admin', 'moderator']) ?? false;

        $record = Series::query()
            ->with(['media'])
            ->tap(fn (Builder $query): Builder => $this->slugOrUuidResolver->apply($query, (new Series)->getTable().'.slug', $series))
            ->firstOrFail();

        if ($record->visibility !== 'public' && ! $canBypassVisibility) {
            abort(404);
        }

        $upcomingPerPage = max(1, min($request->integer('upcoming_per_page', 10), 50));
        $upcomingEvents = $this->eventCollections->forSeries((string) $record->id, past: false, perPage: $upcomingPerPage, now: $now);

        $pastPerPage = max(1, min($request->integer('past_per_page', 10), 50));
        $pastEvents = $this->eventCollections->forSeries((string) $record->id, past: true, perPage: $pastPerPage, now: $now);

        return response()->json([
            'data' => [
                'series' => SeriesDetailData::fromModel($record, $user)->toArray(),
                'upcoming_events' => $upcomingEvents['items'],
                'upcoming_total' => $upcomingEvents['total'],
                'past_events' => $pastEvents['items'],
                'past_total' => $pastEvents['total'],
            ],
        ]);
    }
}
