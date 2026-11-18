<?php

declare(strict_types=1);

namespace App\Features\Persons\Actions;

use App\Features\Persons\Api\PersonWorkflow;
use App\Features\Persons\Models\Person;
use Illuminate\Support\Carbon;

final readonly class PruneOrphanedPersonsAction
{
    public function __construct(private PersonWorkflow $personWorkflow) {}

    /**
     * @return array{found: int, pruned: int}
     */
    public function __invoke(int $hours, bool $dryRun = false): array
    {
        $threshold = Carbon::now()->subHours($hours);

        $query = Person::query()
            ->where('status', 'pending')
            ->where('created_at', '<', $threshold)
            ->whereDoesntHave('events');

        $count = $query->count();

        if ($count === 0 || $dryRun) {
            return ['found' => $count, 'pruned' => 0];
        }

        /** @var list<string> $ids */
        $ids = (clone $query)->pluck((new Person)->getQualifiedKeyName())
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $this->personWorkflow->delete($ids);

        return ['found' => $count, 'pruned' => $count];
    }
}
