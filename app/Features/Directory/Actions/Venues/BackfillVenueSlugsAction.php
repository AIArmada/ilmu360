<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Venues;

use App\Features\Directory\Support\DirectoryModel;
use Illuminate\Database\Eloquent\Model;

/**
 * Regenerate stored venue slugs using the canonical geographic format.
 */
final readonly class BackfillVenueSlugsAction
{
    public function __construct(
        private GenerateVenueSlugAction $generateVenueSlugAction,
    ) {}

    /**
     * @param  list<string>  $venueIds
     * @return list<string>
     */
    public function handle(array $venueIds = []): array
    {
        $query = DirectoryModel::query('venue')
            ->with([
                'addresses.country',
            ])
            ->orderBy('name')
            ->orderBy('id');

        if ($venueIds !== []) {
            $query->whereIn('id', $venueIds);
        }

        $changed = [];
        $venueClass = DirectoryModel::venue();

        foreach ($query->get() as $venue) {
            if (! $venue instanceof Model) {
                continue;
            }

            $slug = $this->generateVenueSlugAction->forVenue($venue);

            if ($venue->getAttribute('slug') === $slug) {
                continue;
            }

            $venueClass::withoutTimestamps(function () use ($venue, $slug): void {
                $venue->forceFill([
                    'slug' => $slug,
                ])->saveQuietly();
            });

            $changed[] = (string) $venue->getKey();
        }

        return $changed;
    }
}
