<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Spaces;

use App\Features\Directory\Support\DirectoryModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reconcile a space's institution links and per-institution capacity
 * overrides.
 *
 * The space row is locked for the whole reconciliation. Institution list
 * changes are recorded as the same custom `sync` audit shape the previous
 * relationship sync emitted; capacity-only edits stay silent, matching the
 * prior behavior.
 */
final readonly class SyncInstitutionSpacesAction
{
    public function __construct(private AttachInstitutionSpaceAction $attachLink) {}

    /**
     * @param  array<string, int|null>  $links  Institution id => capacity override.
     */
    public function handle(Model $space, array $links, bool $detaching = true): void
    {
        DirectoryModel::assert($space, 'space');

        $spaceId = (string) $space->getKey();
        $normalized = $this->normalizeLinks($links);

        DB::transaction(function () use ($spaceId, $normalized, $detaching): void {
            $locked = DirectoryModel::query('space')
                ->whereKey($spaceId)
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof Model) {
                throw ValidationException::withMessages([
                    'space_id' => __('The selected space is invalid.'),
                ]);
            }

            $before = $locked->institutions()
                ->get(['institutions.id', 'institutions.name']);

            if ($detaching) {
                DB::table('institution_space')
                    ->where('space_id', $spaceId)
                    ->when(
                        $normalized !== [],
                        static fn ($query) => $query->whereNotIn('institution_id', array_keys($normalized)),
                    )
                    ->delete();
            }

            foreach ($normalized as $institutionId => $capacity) {
                $this->attachLink->handle($institutionId, $spaceId, $capacity);
            }

            $locked->unsetRelation('institutions');
            $after = $locked->institutions()
                ->get(['institutions.id', 'institutions.name']);

            $this->recordInstitutionAudit($locked, $before, $after);
        });
    }

    /**
     * @param  array<string, int|null>  $links
     * @return array<string, int|null>
     */
    private function normalizeLinks(array $links): array
    {
        $normalized = [];

        foreach ($links as $institutionId => $capacity) {
            $id = trim((string) $institutionId);

            if ($id === '') {
                continue;
            }

            if ($capacity !== null) {
                if (! is_int($capacity) || $capacity < 1) {
                    throw ValidationException::withMessages([
                        'capacity' => __('The capacity must be at least 1.'),
                    ]);
                }
            }

            $normalized[$id] = $capacity;
        }

        return $normalized;
    }

    /**
     * @param  Collection<int, Model>  $before
     * @param  Collection<int, Model>  $after
     */
    private function recordInstitutionAudit(Model $space, Collection $before, Collection $after): void
    {
        $beforeIds = $before->modelKeys();
        $afterIds = $after->modelKeys();
        $removedIds = array_values(array_diff($beforeIds, $afterIds));
        $addedIds = array_values(array_diff($afterIds, $beforeIds));

        if ($removedIds === [] && $addedIds === []) {
            return;
        }

        $space->recordCustomAudit(
            'sync',
            ['institutions' => $before->whereIn('id', $removedIds)->values()->toArray()],
            ['institutions' => $after->whereIn('id', $addedIds)->values()->toArray()],
        );
    }
}
