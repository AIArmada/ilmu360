<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\References;

use AIArmada\References\Enums\ReferenceRecordKind;
use AIArmada\References\Rules\Isbn;
use App\Features\Directory\Support\DirectoryModel;
use App\Features\Directory\Support\ReferencePayload;
use App\Shared\Directory\Enums\ReferencePartType;
use App\Shared\Directory\Enums\ReferenceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Create or update a reference from admin and quick-create forms.
 */
final readonly class SaveReferenceAction
{
    use AsAction;

    public function __construct(
        private SyncReferenceRelationsAction $syncRelations,
        private SyncReferenceMediaAction $syncMedia,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?Model $reference = null): Model
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

        $creating = ! $reference instanceof Model || ! $reference->exists;
        $reference ??= DirectoryModel::new('reference');
        $reference = DirectoryModel::assert($reference, 'reference');
        $status = array_key_exists('status', $data)
            ? (string) $data['status']
            : ($creating ? 'verified' : (string) $reference->getAttribute('status'));

        $reference->fill([
            'title' => ReferencePayload::requiredString($data['title'] ?? $reference->getAttribute('title'), 'Reference'),
            'type' => array_key_exists('type', $data)
                ? ReferencePayload::referenceType($data['type'])
                : ReferencePayload::referenceType($reference->getAttribute('type')),
            'parent_id' => array_key_exists('parent_id', $data)
                ? ReferencePayload::optionalString($data['parent_id'])
                : $reference->getAttribute('parent_id'),
            'record_kind' => ReferencePayload::optionalString($data['record_kind'] ?? $reference->getAttribute('record_kind')),
            'edition_number' => $data['edition_number'] ?? (array_key_exists('edition_number', $data) ? null : $reference->getAttribute('edition_number')),
            'edition_label' => array_key_exists('edition_label', $data) ? ReferencePayload::optionalString($data['edition_label']) : $reference->getAttribute('edition_label'),
            'isbn' => array_key_exists('isbn', $data) ? ReferencePayload::optionalString($data['isbn']) : $reference->getAttribute('isbn'),
            'language' => array_key_exists('language', $data) ? ReferencePayload::optionalString($data['language']) : $reference->getAttribute('language'),
            'part_type' => array_key_exists('part_type', $data) ? ReferencePayload::optionalString($data['part_type']) : ReferencePayload::partType($reference),
            'part_number' => array_key_exists('part_number', $data) ? ReferencePayload::optionalString($data['part_number']) : ReferencePayload::partNumber($reference),
            'part_label' => array_key_exists('part_label', $data) ? ReferencePayload::optionalString($data['part_label']) : ReferencePayload::partLabel($reference),
            'year' => array_key_exists('year', $data) || array_key_exists('publication_year', $data)
                ? (filled($data['year'] ?? $data['publication_year'] ?? null) ? (int) ($data['year'] ?? $data['publication_year']) : null)
                : $reference->getAttribute('year'),
            'publisher' => array_key_exists('publisher', $data) ? ReferencePayload::optionalString($data['publisher']) : $reference->getAttribute('publisher'),
            'description' => array_key_exists('description', $data) ? $data['description'] : $reference->getAttribute('description'),
            'url' => array_key_exists('url', $data) ? ReferencePayload::optionalString($data['url']) : $reference->getAttribute('url'),
            'status' => $status,
        ]);

        if (in_array($status, ['verified', 'pending'], true) && $reference->getAttribute('published_at') === null) {
            $reference->published_at = now();
        }

        $reference->save();

        $this->syncRelations->handle($reference, Arr::only($data, ['social_media', 'author_ids']));
        $this->syncMedia->handle($reference, $data);

        return DirectoryModel::referenceQuery()
            ->with([
                'socialProfiles',
                'media',
            ])
            ->find($reference->getKey()) ?? $reference;
    }
}
