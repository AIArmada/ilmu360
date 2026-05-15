<?php

namespace App\Observers;

use App\Support\Auditing\MediaCollectionAuditSnapshot;
use App\Support\Cache\PublicDirectoryCacheVersion;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\MediaCollections\Models\Observers\MediaObserver;
use WeakMap;

class AuditedMediaObserver extends MediaObserver
{
    /**
     * @var WeakMap<Media, array<string, mixed>>|null
     */
    private static ?WeakMap $pendingUpdateSnapshots = null;

    /**
     * @var WeakMap<Media, array<string, mixed>>|null
     */
    private static ?WeakMap $pendingDeleteSnapshots = null;

    public function created(Media $media): void
    {
        app(PublicDirectoryCacheVersion::class)->bumpForMedia($media);

        $owner = $this->resolveAuditableOwner($this->resolveCurrentOwner($media));

        if (! $owner instanceof Model) {
            return;
        }

        $field = MediaCollectionAuditSnapshot::field($media->collection_name);
        $after = MediaCollectionAuditSnapshot::forOwner($owner, $media->collection_name);
        $before = array_values(array_filter(
            $after,
            fn (array $item): bool => ($item['id'] ?? null) !== (string) $media->getKey(),
        ));

        $this->recordOwnerAuditDifferences($owner, 'media_created', [$field => $before], [$field => $after]);
    }

    #[\Override]
    public function updating(Media $media): void
    {
        if ($this->mediaUpdateAffectsAudit($media)) {
            $sourceOwner = MediaCollectionAuditSnapshot::resolveOwner(
                (string) $media->getOriginal('model_type'),
                $media->getOriginal('model_id')
            );

            $sourceCollection = (string) $media->getOriginal('collection_name');
            $sourceField = MediaCollectionAuditSnapshot::field($sourceCollection);

            $destinationOwner = $this->resolveCurrentOwner($media);
            $destinationCollection = (string) $media->collection_name;
            $destinationField = MediaCollectionAuditSnapshot::field($destinationCollection);

            $sameContext = $this->sameAuditContext($sourceOwner, $destinationOwner, $sourceField, $destinationField);

            $this->pendingUpdateSnapshots()[$media] = [
                'source_owner' => $sourceOwner,
                'source_field' => $sourceField,
                'source_before' => $sourceOwner instanceof Model
                    ? MediaCollectionAuditSnapshot::forOwner($sourceOwner, $sourceCollection)
                    : [],
                'destination_owner' => $sameContext ? null : $destinationOwner,
                'destination_field' => $sameContext ? null : $destinationField,
                'destination_before' => $sameContext || ! $destinationOwner instanceof Model
                    ? null
                    : MediaCollectionAuditSnapshot::forOwner($destinationOwner, $destinationCollection),
            ];
        }

        parent::updating($media);
    }

    #[\Override]
    public function updated(Media $media): void
    {
        parent::updated($media);
        app(PublicDirectoryCacheVersion::class)->bumpForMedia($media);

        $pendingSnapshots = $this->pendingUpdateSnapshots();
        $snapshot = $pendingSnapshots[$media] ?? null;

        unset($pendingSnapshots[$media]);

        if (! is_array($snapshot)) {
            return;
        }

        $sourceOwner = $snapshot['source_owner'];
        $sourceField = $snapshot['source_field'];
        $sourceBefore = $snapshot['source_before'];
        $destinationOwner = $snapshot['destination_owner'];
        $destinationField = $snapshot['destination_field'];
        $destinationBefore = $snapshot['destination_before'];

        $currentOwner = $this->resolveAuditableOwner($this->resolveCurrentOwner($media));
        $currentField = MediaCollectionAuditSnapshot::field($media->collection_name);

        if (
            $this->sameAuditContext($sourceOwner, $currentOwner, $sourceField, $currentField)
            && $currentOwner instanceof Model
        ) {
            $this->recordOwnerAuditDifferences(
                $currentOwner,
                'media_updated',
                [$sourceField => $sourceBefore],
                [$currentField => MediaCollectionAuditSnapshot::forOwner($currentOwner, $media->collection_name)],
            );

            return;
        }

        $sourceOwner = $this->resolveAuditableOwner($sourceOwner);

        if ($sourceOwner instanceof Model) {
            $this->recordOwnerAuditDifferences(
                $sourceOwner,
                'media_updated',
                [$sourceField => $sourceBefore],
                [$sourceField => MediaCollectionAuditSnapshot::forOwner($sourceOwner, (string) $media->getOriginal('collection_name'))],
            );
        }

        $destinationOwner = $this->resolveAuditableOwner($destinationOwner);

        if ($destinationField !== null && is_array($destinationBefore) && $destinationOwner instanceof Model) {
            $this->recordOwnerAuditDifferences(
                $destinationOwner,
                'media_updated',
                [$destinationField => $destinationBefore],
                [$currentField => MediaCollectionAuditSnapshot::forOwner($destinationOwner, $media->collection_name)],
            );
        }
    }

    public function deleting(Media $media): void
    {
        $owner = $this->resolveCurrentOwner($media);

        if (! $owner instanceof Model) {
            return;
        }

        $this->pendingDeleteSnapshots()[$media] = [
            'owner' => $owner,
            'field' => MediaCollectionAuditSnapshot::field($media->collection_name),
            'before' => MediaCollectionAuditSnapshot::forOwner($owner, $media->collection_name),
        ];
    }

    #[\Override]
    public function deleted(Media $media): void
    {
        parent::deleted($media);
        app(PublicDirectoryCacheVersion::class)->bumpForMedia($media);

        $pendingSnapshots = $this->pendingDeleteSnapshots();
        $snapshot = $pendingSnapshots[$media] ?? null;

        unset($pendingSnapshots[$media]);

        if (! is_array($snapshot) || ! $this->canAuditOwner($snapshot['owner'])) {
            return;
        }

        $owner = $snapshot['owner'];
        $field = $snapshot['field'];

        $this->recordOwnerAuditDifferences(
            $owner,
            'media_deleted',
            [$field => $snapshot['before']],
            [$field => MediaCollectionAuditSnapshot::forOwner($owner, $media->collection_name)],
        );
    }

    private function mediaUpdateAffectsAudit(Media $media): bool
    {
        return $media->isDirty([
            'collection_name',
            'name',
            'file_name',
            'order_column',
            'custom_properties',
            'manipulations',
            'model_id',
            'model_type',
        ]);
    }

    private function resolveCurrentOwner(Media $media): ?Model
    {
        return MediaCollectionAuditSnapshot::resolveOwner((string) $media->model_type, $media->model_id);
    }

    /**
     * @phpstan-assert-if-true (Model&AuditableContract) $owner
     */
    private function canAuditOwner(?Model $owner): bool
    {
        return $owner instanceof AuditableContract && method_exists($owner, 'recordCustomAuditDifferences');
    }

    /**
     * @return (Model&AuditableContract)|null
     */
    private function resolveAuditableOwner(?Model $owner): ?Model
    {
        return $this->canAuditOwner($owner) ? $owner : null;
    }

    /**
     * @param  Model&AuditableContract  $owner
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function recordOwnerAuditDifferences(Model $owner, string $event, array $before, array $after): void
    {
        $callback = [$owner, 'recordCustomAuditDifferences'];

        if (! is_callable($callback)) {
            return;
        }

        $callback($event, $before, $after);
    }

    private function sameAuditContext(?Model $sourceOwner, ?Model $destinationOwner, string $sourceField, string $destinationField): bool
    {
        if (! $sourceOwner instanceof Model || ! $destinationOwner instanceof Model) {
            return false;
        }

        return $sourceOwner->is($destinationOwner) && $sourceField === $destinationField;
    }

    /**
     * @return WeakMap<Media, array<string, mixed>>
     */
    private function pendingUpdateSnapshots(): WeakMap
    {
        /** @var WeakMap<Media, array<string, mixed>> $snapshots */
        $snapshots = self::$pendingUpdateSnapshots ?? new WeakMap;

        self::$pendingUpdateSnapshots = $snapshots;

        return $snapshots;
    }

    /**
     * @return WeakMap<Media, array<string, mixed>>
     */
    private function pendingDeleteSnapshots(): WeakMap
    {
        /** @var WeakMap<Media, array<string, mixed>> $snapshots */
        $snapshots = self::$pendingDeleteSnapshots ?? new WeakMap;

        self::$pendingDeleteSnapshots = $snapshots;

        return $snapshots;
    }
}
