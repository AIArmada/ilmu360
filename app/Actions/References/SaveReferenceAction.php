<?php

namespace App\Actions\References;

use App\Enums\ReferenceType;
use App\Models\Reference;
use App\Services\ContributionEntityMutationService;
use App\Support\Media\ModelMediaSyncService;
use BackedEnum;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class SaveReferenceAction
{
    use AsAction;

    public function __construct(
        private ContributionEntityMutationService $contributionEntityMutationService,
        private ModelMediaSyncService $mediaSyncService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?Reference $reference = null): Reference
    {
        $creating = ! $reference instanceof Reference;
        $reference ??= new Reference;

        $reference->fill([
            'title' => $this->normalizeRequiredString($data['title'] ?? $reference->title, 'Reference'),
            'author' => array_key_exists('author', $data) ? $this->normalizeOptionalString($data['author']) : $reference->author,
            'type' => array_key_exists('type', $data) ? $this->normalizeReferenceType($data['type']) : $this->normalizeReferenceType($reference->type),
            'parent_reference_id' => array_key_exists('parent_reference_id', $data)
                ? $this->normalizeOptionalString($data['parent_reference_id'])
                : $reference->parent_reference_id,
            'part_type' => array_key_exists('part_type', $data) ? $this->normalizeOptionalString($data['part_type']) : $reference->part_type,
            'part_number' => array_key_exists('part_number', $data) ? $this->normalizeOptionalString($data['part_number']) : $reference->part_number,
            'part_label' => array_key_exists('part_label', $data) ? $this->normalizeOptionalString($data['part_label']) : $reference->part_label,
            'publication_year' => array_key_exists('publication_year', $data)
                ? $this->normalizeOptionalString($data['publication_year'])
                : $reference->publication_year,
            'publisher' => array_key_exists('publisher', $data) ? $this->normalizeOptionalString($data['publisher']) : $reference->publisher,
            'description' => array_key_exists('description', $data) ? $data['description'] : $reference->description,
            'is_canonical' => array_key_exists('is_canonical', $data)
                ? (bool) $data['is_canonical']
                : ($creating ? false : (bool) $reference->is_canonical),
            'status' => array_key_exists('status', $data) ? (string) $data['status'] : ($creating ? 'verified' : (string) $reference->status),
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : ($creating ? true : (bool) $reference->is_active),
        ]);

        $reference->save();

        $this->contributionEntityMutationService->syncReferenceRelations($reference, Arr::only($data, ['social_media']));
        $this->syncMedia($reference, $data);

        return $reference->fresh([
            'socialMedia',
            'media',
        ]) ?? $reference;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncMedia(Reference $reference, array $data): void
    {
        if (($data['clear_front_cover'] ?? false) === true) {
            $this->mediaSyncService->clearCollection($reference, 'front_cover');
        }

        if (($data['clear_back_cover'] ?? false) === true) {
            $this->mediaSyncService->clearCollection($reference, 'back_cover');
        }

        if (($data['clear_gallery'] ?? false) === true) {
            $this->mediaSyncService->clearCollection($reference, 'gallery');
        }

        $frontCover = $data['front_cover'] ?? null;
        $backCover = $data['back_cover'] ?? null;
        $gallery = $data['gallery'] ?? null;

        $this->mediaSyncService->syncSingle(
            $reference,
            $frontCover instanceof UploadedFile ? $frontCover : null,
            'front_cover',
        );
        $this->mediaSyncService->syncSingle(
            $reference,
            $backCover instanceof UploadedFile ? $backCover : null,
            'back_cover',
        );
        $this->mediaSyncService->syncMultiple(
            $reference,
            is_array($gallery) ? $gallery : null,
            'gallery',
            replace: is_array($gallery),
        );
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function normalizeRequiredString(mixed $value, string $fallback): string
    {
        $normalized = $this->normalizeOptionalString($value);

        return $normalized ?? $fallback;
    }

    private function normalizeReferenceType(mixed $value): string
    {
        if ($value instanceof ReferenceType) {
            return $value->value;
        }

        if ($value instanceof BackedEnum) {
            return is_string($value->value) ? $value->value : ReferenceType::Book->value;
        }

        if (is_string($value) && ReferenceType::tryFrom($value) instanceof ReferenceType) {
            return $value;
        }

        return ReferenceType::Book->value;
    }
}
