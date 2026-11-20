<?php

declare(strict_types=1);

namespace App\Features\Places\Actions;

use App\Features\Places\Api\PruneOrphanedVenues;
use App\Features\Places\Api\VenueWorkflow;
use App\Features\Places\Models\Venue;
use Illuminate\Support\Carbon;

final readonly class PruneOrphanedVenuesAction implements PruneOrphanedVenues
{
    public function __construct(private VenueWorkflow $venueWorkflow) {}

    /**
     * @return array{found: int, pruned: int}
     */
    public function __invoke(int $hours, bool $dryRun = false): array
    {
        $threshold = Carbon::now()->subHours($hours);

        $query = Venue::query()
            ->where('status', 'pending')
            ->where('created_at', '<', $threshold)
            ->whereDoesntHave('events');

        $count = $query->count();

        if ($count === 0 || $dryRun) {
            return ['found' => $count, 'pruned' => 0];
        }

        /** @var list<string> $ids */
        $ids = (clone $query)->pluck((new Venue)->getQualifiedKeyName())
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $this->venueWorkflow->delete($ids);

        return ['found' => $count, 'pruned' => $count];
    }
}
