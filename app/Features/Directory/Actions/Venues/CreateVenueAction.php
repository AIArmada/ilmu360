<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Venues;

use App\Features\Directory\Support\DirectoryModel;
use App\Shared\Directory\Enums\VenueType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Create a venue from a shared quick-add form in the pending state.
 */
final readonly class CreateVenueAction
{
    public function __construct(
        private GenerateVenueSlugAction $generateVenueSlugAction,
        private SyncVenueRelationsAction $syncVenueRelations,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data): Model
    {
        return DB::transaction(function () use ($data): Model {
            $addressData = is_array($data['address'] ?? null) ? $data['address'] : $data;
            $venueClass = DirectoryModel::venue();
            $venue = DirectoryModel::new('venue');

            $venue->fill([
                'id' => (string) Str::uuid(),
                'name' => $data['name'],
                'slug' => $this->generateVenueSlugAction->handle((string) $data['name'], $addressData),
                'venue_type' => $this->normalizeVenueType($data['type'] ?? null),
                'status' => 'pending',
                'visibility' => 'public',
            ]);

            // Prevent VenueObserver from overriding the slug before the
            // address is linked.
            $venueClass::withoutEvents(fn () => $venue->save());

            $relations = ['address' => $addressData];

            if (array_key_exists('social_media', $data)) {
                $relations['social_media'] = $data['social_media'];
            }

            $this->syncVenueRelations->handle($venue, $relations);

            // Re-sync slug now that the address is linked (the created event
            // fires before the pivot is set up).
            $venue = $venue->fresh(['addresses']) ?? $venue;
            $venue->setAttribute('slug', $this->generateVenueSlugAction->forVenue($venue));
            $venueClass::withoutEvents(fn () => $venue->saveQuietly());

            return $venue;
        });
    }

    private function normalizeVenueType(mixed $value): string
    {
        if ($value instanceof VenueType) {
            return $value->value;
        }

        if (is_string($value) && VenueType::tryFrom($value) instanceof VenueType) {
            return $value;
        }

        return VenueType::Dewan->value;
    }
}
