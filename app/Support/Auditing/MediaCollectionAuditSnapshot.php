<?php

namespace App\Support\Auditing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class MediaCollectionAuditSnapshot
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function forOwner(Model $owner, string $collection): array
    {
        if (! method_exists($owner, 'media')) {
            return [];
        }

        return $owner->media()
            ->where('collection_name', $collection)
            ->orderBy('order_column')
            ->orderBy('id')
            ->get()
            ->map(fn (Media $media): array => [
                'id' => (string) $media->getKey(),
                'name' => $media->name,
                'file_name' => $media->file_name,
                'mime_type' => $media->mime_type,
                'order_column' => $media->order_column === null ? null : (int) $media->order_column,
                'custom_properties' => $media->custom_properties,
                'manipulations' => $media->manipulations,
            ])
            ->values()
            ->all();
    }

    public static function field(string $collection): string
    {
        return Str::snake($collection).'_media';
    }

    public static function resolveOwner(string $modelType, mixed $modelId): ?Model
    {
        $modelClass = Relation::getMorphedModel($modelType) ?? $modelType;

        if (! is_string($modelClass) || ! class_exists($modelClass)) {
            return null;
        }

        $owner = $modelClass::query()->find($modelId);

        return $owner instanceof Model ? $owner : null;
    }
}
