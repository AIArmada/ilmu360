<?php

declare(strict_types=1);

namespace App\Features\Institutions\Actions;

use App\Features\Institutions\Api\InstitutionWorkflow;
use App\Features\Institutions\Models\Institution;
use Illuminate\Support\Carbon;

final readonly class PruneOrphanedInstitutionsAction
{
    public function __construct(private InstitutionWorkflow $institutionWorkflow) {}

    /**
     * @return array{found: int, pruned: int}
     */
    public function __invoke(int $hours, bool $dryRun = false): array
    {
        $threshold = Carbon::now()->subHours($hours);

        $query = Institution::query()
            ->where('status', 'pending')
            ->where('created_at', '<', $threshold)
            ->whereNotIn('id', function ($query): void {
                $involvementsTable = config('events.database.tables.event_involvements', 'event_involvements');
                $query->select('involveable_id')
                    ->from($involvementsTable)
                    ->where('involveable_type', (new Institution)->getMorphClass())
                    ->where('role_code', 'organizer')
                    ->where('is_primary', true);
            });

        $count = $query->count();

        if ($count === 0 || $dryRun) {
            return ['found' => $count, 'pruned' => 0];
        }

        /** @var list<string> $ids */
        $ids = (clone $query)->pluck((new Institution)->getQualifiedKeyName())
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $this->institutionWorkflow->delete($ids);

        return ['found' => $count, 'pruned' => $count];
    }
}
