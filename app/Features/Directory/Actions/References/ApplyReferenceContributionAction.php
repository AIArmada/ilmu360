<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\References;

use App\Features\Directory\Support\ReferencePayload;
use Illuminate\Database\Eloquent\Model;

/**
 * Apply an approved contribution payload to an existing reference.
 */
final readonly class ApplyReferenceContributionAction
{
    public function __construct(
        private SyncReferenceRelationsAction $syncRelations,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> Changed attributes.
     */
    public function handle(Model $reference, array $payload): array
    {
        $reference->fill([
            'title' => $payload['title'] ?? $reference->getAttribute('title'),
            'type' => array_key_exists('type', $payload)
                ? ReferencePayload::referenceType($payload['type'])
                : ReferencePayload::referenceType($reference->getAttribute('type')),
            'parent_id' => array_key_exists('parent_id', $payload) ? ReferencePayload::optionalString($payload['parent_id']) : $reference->getAttribute('parent_id'),
            'record_kind' => $payload['record_kind'] ?? $reference->getAttribute('record_kind'),
            'edition_number' => array_key_exists('edition_number', $payload) ? $payload['edition_number'] : $reference->getAttribute('edition_number'),
            'edition_label' => array_key_exists('edition_label', $payload) ? ReferencePayload::optionalString($payload['edition_label']) : $reference->getAttribute('edition_label'),
            'isbn' => array_key_exists('isbn', $payload) ? ReferencePayload::optionalString($payload['isbn']) : $reference->getAttribute('isbn'),
            'language' => array_key_exists('language', $payload) ? ReferencePayload::optionalString($payload['language']) : $reference->getAttribute('language'),
            'part_type' => array_key_exists('part_type', $payload) ? ReferencePayload::optionalString($payload['part_type']) : ReferencePayload::partType($reference),
            'part_number' => array_key_exists('part_number', $payload) ? ReferencePayload::optionalString($payload['part_number']) : ReferencePayload::partNumber($reference),
            'part_label' => array_key_exists('part_label', $payload) ? ReferencePayload::optionalString($payload['part_label']) : ReferencePayload::partLabel($reference),
            'year' => array_key_exists('publication_year', $payload)
                ? (filled($payload['publication_year']) ? (int) $payload['publication_year'] : null)
                : $reference->getAttribute('year'),
            'publisher' => array_key_exists('publisher', $payload) ? ReferencePayload::optionalString($payload['publisher']) : $reference->getAttribute('publisher'),
            'description' => array_key_exists('description', $payload) ? $payload['description'] : $reference->getAttribute('description'),
            'url' => array_key_exists('url', $payload) ? ReferencePayload::optionalString($payload['url']) : $reference->getAttribute('url'),
        ]);

        $dirty = $reference->getDirty();
        $reference->save();

        $this->syncRelations->handle(
            $reference,
            array_intersect_key($payload, ['social_media' => true, 'author_ids' => true]),
        );

        return $dirty;
    }
}
