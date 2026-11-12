<?php

namespace App\Features\Events\Support;

use AIArmada\Events\Models\Event as PackageEvent;
use App\Features\Events\Actions\Authoring\SyncEventAdminRelationsAction;
use App\Features\Events\Actions\Authoring\SyncEventResourceRelationsAction;
use App\Platform\Media\MediaCollectionSync;
use App\Platform\Seating\SeatMapAssignments;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

final readonly class AdminEventRelationsSynchronizer
{
    public function __construct(
        private SyncEventResourceRelationsAction $syncEventResourceRelationsAction,
        private SyncEventAdminRelationsAction $syncAdminRelations,
        private MediaCollectionSync $mediaSyncService,
        private SeatMapAssignments $seatMapAssignments,
    ) {}

    /**
     * @param  \App\Models\Event  $event
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $data
     */
    public function sync(PackageEvent $event, array $state, array $data, bool $creating): void
    {
        $this->syncReferences($event, $state);
        $this->syncSeries($event, $state);
        $this->syncEventResourceRelationsAction->handle(
            $event,
            $state,
            lockRegistrationMode: ! $creating,
            syncKeyPeople: true,
        );
        $this->syncMedia($event, $data);
        $this->syncSeatMaps($event, $state);
    }

    /**
     * @param  \App\Models\Event  $event
     * @param  array<string, mixed>  $state
     */
    private function syncReferences(PackageEvent $event, array $state): void
    {
        if (! array_key_exists('references', $state)) {
            return;
        }

        $referenceIds = $this->normalizeStringArray($state['references'] ?? []);

        $this->syncAdminRelations->syncReferences($event, $referenceIds);
    }

    /**
     * @param  \App\Models\Event  $event
     * @param  array<string, mixed>  $state
     */
    private function syncSeries(PackageEvent $event, array $state): void
    {
        if (! array_key_exists('series', $state)) {
            return;
        }

        $seriesIds = $this->normalizeStringArray($state['series'] ?? []);

        $this->syncAdminRelations->syncSeries($event, $seriesIds);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncMedia(PackageEvent $event, array $data): void
    {
        if ($this->shouldClearMediaCollection($data['clear_cover'] ?? false)) {
            $this->mediaSyncService->clearCollection($event, 'cover');
        }

        if ($this->shouldClearMediaCollection($data['clear_poster'] ?? false)) {
            $this->mediaSyncService->clearCollection($event, 'poster');
        }

        if ($this->shouldClearMediaCollection($data['clear_gallery'] ?? false)) {
            $this->mediaSyncService->clearCollection($event, 'gallery');
        }

        $cover = $data['cover'] ?? null;
        $poster = $data['poster'] ?? null;
        $gallery = $data['gallery'] ?? null;

        $this->mediaSyncService->syncSingle(
            $event,
            $cover instanceof UploadedFile ? $cover : null,
            'cover',
        );
        $this->mediaSyncService->syncSingle(
            $event,
            $poster instanceof UploadedFile ? $poster : null,
            'poster',
        );
        $this->mediaSyncService->syncMultiple(
            $event,
            is_array($gallery) ? $gallery : null,
            'gallery',
            replace: is_array($gallery),
        );
    }

    /**
     * @param  \App\Models\Event  $event
     * @param  array<string, mixed>  $state
     */
    private function syncSeatMaps(PackageEvent $event, array $state): void
    {
        if (! array_key_exists('seat_map_ids', $state)) {
            return;
        }

        $seatMapIds = $this->normalizeStringArray($state['seat_map_ids'] ?? []);

        $this->seatMapAssignments->sync($event, $seatMapIds);
    }

    private function shouldClearMediaCollection(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (! is_string($value) && ! is_int($value)) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
    }

    /**
     * @param  iterable<int, mixed>  $values
     * @return list<string>
     */
    private function normalizeStringArray(iterable $values): array
    {
        return Collection::make($values)
            ->map(function (mixed $value): ?string {
                if (! is_scalar($value)) {
                    return null;
                }

                $trimmed = trim((string) $value);

                return $trimmed !== '' ? $trimmed : null;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
