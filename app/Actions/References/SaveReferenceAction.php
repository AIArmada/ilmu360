<?php

namespace App\Actions\References;

use AIArmada\References\Enums\ReferenceRecordKind;
use AIArmada\References\Rules\Isbn;
use App\Enums\ReferencePartType;
use App\Enums\ReferenceType;
use App\Models\Reference;
use App\Services\ContributionEntityMutationService;
use App\Support\Media\ModelMediaSyncService;
use BackedEnum;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
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
        Validator::make($data, [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', Rule::enum(ReferenceType::class)],
            'record_kind' => ['sometimes', Rule::enum(ReferenceRecordKind::class)],
            'parent_id' => ['nullable', 'uuid'],
            'part_type' => ['nullable', Rule::enum(ReferencePartType::class)],
            'part_number' => ['nullable', 'string', 'max:255'],
            'part_label' => ['nullable', 'string', 'max:255'],
            'year' => ['nullable', 'integer', 'between:-3000,'.(now()->year + 5)],
            'publication_year' => ['nullable', 'integer', 'between:-3000,'.(now()->year + 5)],
            'edition_number' => ['nullable', 'integer', 'min:1'],
            'edition_label' => ['nullable', 'string', 'max:255'],
            'isbn' => ['nullable', 'string', 'max:20', new Isbn],
            'language' => ['nullable', 'string', 'max:10', Rule::exists('languages', 'code')],
            'author_ids' => ['sometimes', 'nullable', 'array'],
            'author_ids.*' => ['uuid', Rule::exists('persons', 'id')->whereIn('status', ['verified', 'pending'])],
            'url' => ['nullable', 'url:http,https', 'max:255'],
            'publisher' => ['nullable', 'string', 'max:255'],
        ])->validate();

        $creating = ! $reference instanceof Reference;
        $reference ??= new Reference;
        $status = array_key_exists('status', $data) ? (string) $data['status'] : ($creating ? 'verified' : (string) $reference->status);

        $reference->fill([
            'title' => $this->normalizeRequiredString($data['title'] ?? $reference->title, 'Reference'),
            'type' => array_key_exists('type', $data) ? $this->normalizeReferenceType($data['type']) : $this->normalizeReferenceType($reference->type),
            'parent_id' => array_key_exists('parent_id', $data)
                ? $this->normalizeOptionalString($data['parent_id'])
                : $reference->parent_id,
            'record_kind' => $this->normalizeOptionalString($data['record_kind'] ?? $reference->recordKindValue()),
            'edition_number' => $data['edition_number'] ?? (array_key_exists('edition_number', $data) ? null : $reference->edition_number),
            'edition_label' => array_key_exists('edition_label', $data) ? $this->normalizeOptionalString($data['edition_label']) : $reference->edition_label,
            'isbn' => array_key_exists('isbn', $data) ? $this->normalizeOptionalString($data['isbn']) : $reference->isbn,
            'language' => array_key_exists('language', $data) ? $this->normalizeOptionalString($data['language']) : $reference->language,
            'part_type' => array_key_exists('part_type', $data) ? $this->normalizeOptionalString($data['part_type']) : $reference->partTypeValue(),
            'part_number' => array_key_exists('part_number', $data) ? $this->normalizeOptionalString($data['part_number']) : $reference->partNumberValue(),
            'part_label' => array_key_exists('part_label', $data) ? $this->normalizeOptionalString($data['part_label']) : $reference->partLabelValue(),
            'year' => array_key_exists('year', $data) || array_key_exists('publication_year', $data)
                ? (filled($data['year'] ?? $data['publication_year'] ?? null) ? (int) ($data['year'] ?? $data['publication_year']) : null)
                : $reference->year,
            'publisher' => array_key_exists('publisher', $data) ? $this->normalizeOptionalString($data['publisher']) : $reference->publisher,
            'description' => array_key_exists('description', $data) ? $data['description'] : $reference->description,
            'url' => array_key_exists('url', $data) ? $this->normalizeOptionalString($data['url']) : $reference->url,
            'status' => $status,

        ]);

        if (in_array($status, ['verified', 'pending'], true) && $reference->published_at === null) {
            $reference->published_at = now();
        }

        $reference->save();

        $this->contributionEntityMutationService->syncReferenceRelations($reference, Arr::only($data, ['social_media', 'author_ids']));
        $this->syncMedia($reference, $data);

        return $reference->fresh([
            'socialProfiles',
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
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

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
