<?php

declare(strict_types=1);

namespace App\Features\Places\EntryPoints\Http;

use App\Features\Events\Api\EventCollections;
use App\Features\Places\Api\Data\VenueDetailData;
use App\Features\Places\Models\Venue;
use App\Platform\Api\Frontend\SearchPayloadTransformer;
use App\Platform\Http\FrontendController;
use App\Platform\Models\SlugOrUuidResolver;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class VenueDirectoryController extends FrontendController
{
    public function __construct(
        private readonly EventCollections $eventCollections,
        private readonly SlugOrUuidResolver $slugOrUuidResolver,
        private readonly SearchPayloadTransformer $searchPayloadTransformer,
    ) {}

    #[Group('Venue', 'Public venue detail endpoints for active verified venues and their related events.')]
    #[Endpoint(
        title: 'Get a public venue',
        description: 'Returns the public venue detail payload by slug or UUID, including upcoming and past events hosted there.',
    )]
    public function showVenue(Request $request, string $venueKey): JsonResponse
    {
        $user = $this->currentUser($request);
        $now = now();
        $canBypassVisibility = $user?->hasAnyRole(['super_admin', 'moderator']) ?? false;

        $record = Venue::query()
            ->with([
                'media',
                'addresses.country',
                'contactMethods',
                'socialProfiles',
            ])
            ->tap(fn (Builder $query): Builder => $this->slugOrUuidResolver->apply($query, 'venues.slug', $venueKey))
            ->firstOrFail();

        if ($record->status !== 'verified' && ! $canBypassVisibility) {
            abort(404);
        }

        $upcomingPerPage = max(1, min($request->integer('upcoming_per_page', 8), 50));
        $upcomingEvents = $this->eventCollections->forVenue((string) $record->id, past: false, perPage: $upcomingPerPage, now: $now);

        $pastPerPage = max(1, min($request->integer('past_per_page', 8), 50));
        $pastEvents = $this->eventCollections->forVenue((string) $record->id, past: true, perPage: $pastPerPage, now: $now);

        return response()->json([
            'data' => [
                'venue' => $this->venueDetailData($record),
                'upcoming_events' => $upcomingEvents['items'],
                'upcoming_total' => $upcomingEvents['total'],
                'past_events' => $pastEvents['items'],
                'past_total' => $pastEvents['total'],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function venueDetailData(Venue $venue): array
    {
        return new VenueDetailData(
            id: (string) $venue->id,
            slug: (string) $venue->slug,
            name: (string) $venue->name,
            description: $venue->description,
            status: (string) $venue->status,
            verified_by: $venue->getAttribute('verified_by'),
            visibility: (string) ($venue->visibility ?? 'public'),
            media: [
                'main_url' => $venue->getFirstMediaUrl('main', 'banner') ?: $venue->getFirstMediaUrl('main'),
                'cover_url' => $venue->getFirstMediaUrl('cover', 'banner') ?: $venue->getFirstMediaUrl('cover'),
            ],
            contacts: $this->searchPayloadTransformer->contactData($venue->contactMethods),
            social_media: $this->searchPayloadTransformer->socialMediaData($venue->socialProfiles),
        )->toArray();
    }
}
