<?php

namespace App\Support\Media;

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;

/**
 * Organizes media into a structured, scalable directory hierarchy.
 *
 * Structure: {model_type_plural}/{uuid_shard}/{model_uuid}/{collection}/
 *
 * Examples:
 *   events/019c/019c4228-10d2-733c-989c-b598e9485cca/poster/
 *   events/019c/019c4228-10d2-733c-989c-b598e9485cca/gallery/
 *   speakers/01a5/01a5f392-88b1-7e2c-a4d3-c1e920da17f8/avatar/
 *   institutions/01b2/01b2c1d4-5e6f-7a8b-9c0d-e1f2a3b4c5d6/logo/
 *   institutions/01b2/01b2c1d4-5e6f-7a8b-9c0d-e1f2a3b4c5d6/gallery/
 *
 * Why this structure:
 * - Model type grouping: all event media together, all speaker media together, etc.
 * - UUID shard (first 4 chars): prevents filesystem bottlenecks from thousands
 *   of subdirectories in a single folder (max ~65k shards per type).
 * - Full model UUID: unique per record, ties media to its owner unambiguously.
 * - Collection: separates cover/poster/gallery/avatar/logo within each model.
 * - Conversions and responsive images nested inside the collection directory.
 */
class MediaPathGenerator implements PathGenerator
{
    public function getPath(Media $media): string
    {
        return $this->getBasePath($media).'/';
    }

    public function getPathForConversions(Media $media): string
    {
        return $this->getBasePath($media).'/conversions/';
    }

    public function getPathForResponsiveImages(Media $media): string
    {
        return $this->getBasePath($media).'/responsive-images/';
    }

    protected function getBasePath(Media $media): string
    {
        $modelType = $this->getModelTypeDirectory($media);
        $modelId = $media->model_id;
        $shard = substr((string) $modelId, 0, 4);
        $collection = $media->collection_name ?: 'default';

        return "{$modelType}/{$shard}/{$modelId}/{$collection}";
    }

    /**
     * Derive a pluralized, URL-friendly directory name from the morph type.
     *
     * Morph map values like 'event' become 'events', 'institution' becomes 'institutions'.
     * Falls back to a slugified class basename if no morph alias is found.
     */
    protected function getModelTypeDirectory(Media $media): string
    {
        $morphType = $media->model_type;

        // The morph map stores short aliases like 'event', 'speaker', etc.
        // Pluralize for a natural directory name.
        return str($morphType)->plural()->slug()->toString();
    }
}
