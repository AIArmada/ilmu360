<?php

declare(strict_types=1);

namespace App\Features\References\EntryPoints\Admin\Resources;

use AIArmada\Contacting\Enums\SocialPlatform;
use AIArmada\References\Enums\ReferenceRecordKind;
use AIArmada\References\Rules\Isbn;
use App\Features\References\Api\Enums\ReferencePartType;
use App\Features\References\Api\Enums\ReferenceType;
use App\Features\References\Api\ReferenceWorkflow;
use App\Features\References\Models\Reference;
use App\Models\User;
use App\Platform\Admin\Concerns\BuildsAdminFieldMetadata;
use App\Platform\Api\Admin\Contracts\AdminMutationHooks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

final readonly class ReferenceMutationHooks implements AdminMutationHooks
{
    use BuildsAdminFieldMetadata;

    public function __construct(private ReferenceWorkflow $workflow) {}

    public function supports(string $resourceClass): bool
    {
        return $resourceClass === \App\Features\References\EntryPoints\Filament\Resources\References\ReferenceResource::class;
    }

    public function schema(string $resourceClass, string $resourceKey, string $operation, ?Model $record, array $defaults): ?array
    {
        if (! $this->supports($resourceClass)) {
            return null;
        }

        $updating = $operation === 'update';

        return [
            'resource_key' => $resourceKey,
            'operation' => $operation,
            'method' => $updating ? 'PUT' : 'POST',
            'endpoint' => $updating && $record instanceof Model
                ? route('api.admin.resources.update', ['resourceKey' => $resourceKey, 'recordKey' => $this->recordKey($record)], false)
                : route('api.admin.resources.store', ['resourceKey' => $resourceKey], false),
            'content_type' => 'multipart/form-data',
            'slug_behavior' => 'auto_managed',
            'defaults' => $defaults,
            'current_media' => $record instanceof Reference ? $this->mediaState($record, ['front_cover', 'back_cover', 'gallery']) : null,
            'fields' => $this->fields(),
            'catalogs' => [],
            'conditional_rules' => [],
        ];
    }

    public function rules(string $resourceClass, bool $updating): ?array
    {
        if (! $this->supports($resourceClass)) {
            return null;
        }

        $required = 'required';
        $maxUploadSize = 'max:'.$this->maxUploadSizeKb();

        return [
            'title' => [$required, 'string', 'max:255'],
            'author_ids' => ['sometimes', 'nullable', 'array'],
            'author_ids.*' => ['uuid', Rule::exists('persons', 'id')->whereIn('status', ['verified', 'pending'])],
            'type' => [$required, Rule::enum(ReferenceType::class)],
            'parent_id' => ['nullable', 'uuid', Rule::exists('references', 'id')->where('type', ReferenceType::Book->value)->whereIn('record_kind', ['work', 'edition'])],
            'record_kind' => ['sometimes', Rule::enum(ReferenceRecordKind::class)],
            'edition_number' => ['nullable', 'integer', 'min:1'],
            'edition_label' => ['nullable', 'string', 'max:255'],
            'isbn' => ['nullable', 'string', 'max:20', new Isbn],
            'language' => ['nullable', 'string', 'max:10', Rule::exists('languages', 'code')],
            'part_type' => ['nullable', Rule::enum(ReferencePartType::class)],
            'part_number' => ['nullable', 'string', 'max:255'],
            'part_label' => ['nullable', 'string', 'max:255'],
            'publication_year' => ['nullable', 'integer', 'min:-3000', 'max:'.((int) now()->year + 5)],
            'publisher' => ['nullable', 'string', 'max:255'],
            'url' => ['nullable', 'url:http,https', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => [$required, Rule::in(['pending', 'verified', 'inactive'])],
            'social_media' => ['nullable', 'array'],
            'social_media.*.platform' => ['required_with:social_media.*.handle,social_media.*.url', Rule::enum(SocialPlatform::class)],
            'social_media.*.handle' => ['nullable', 'string', 'max:255', 'required_without:social_media.*.url'],
            'social_media.*.url' => ['nullable', 'url', 'max:255', 'required_without:social_media.*.handle'],
            'front_cover' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp', $maxUploadSize],
            'back_cover' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp', $maxUploadSize],
            'gallery' => ['nullable', 'array', 'max:10'],
            'gallery.*' => ['file', 'mimetypes:image/jpeg,image/png,image/webp', $maxUploadSize],
            'clear_front_cover' => ['sometimes', 'boolean'],
            'clear_back_cover' => ['sometimes', 'boolean'],
            'clear_gallery' => ['sometimes', 'boolean'],
        ];
    }

    public function normalize(string $resourceClass, array $validated, ?Model $record): array
    {
        return $validated;
    }

    public function store(string $resourceClass, array $validated, User $actor): ?Model
    {
        if (! $this->supports($resourceClass)) {
            return null;
        }

        return Reference::query()->withoutOwnerScope()->findOrFail($this->workflow->saveFromAdmin($validated));
    }

    public function update(string $resourceClass, Model $record, array $validated, User $actor): ?Model
    {
        abort_unless($actor->canPublishDirectoryUpdates(), 403, __('Submit directory updates for review through the contribution form.'));

        if (! $this->supports($resourceClass)) {
            return null;
        }

        if (! $record instanceof Reference) {
            throw new \RuntimeException('Expected reference record.');
        }

        return Reference::query()->withoutOwnerScope()->findOrFail(
            $this->workflow->saveFromAdmin($validated, (string) $record->getKey()),
        );
    }

    public function defaultsForCreate(string $resourceClass): ?array
    {
        if (! $this->supports($resourceClass)) {
            return null;
        }

        return [
            'type' => ReferenceType::Book->value,
            'record_kind' => ReferenceRecordKind::Work->value,
            'status' => 'verified',
            'social_media' => [],
            'clear_front_cover' => false,
            'clear_back_cover' => false,
            'clear_gallery' => false,
        ];
    }

    public function augmentDefaultsForRecord(Model $record, array $defaults): array
    {
        if (! $record instanceof Reference) {
            return $defaults;
        }

        $defaults['status'] = $record->status;
        $defaults['clear_front_cover'] = false;
        $defaults['clear_back_cover'] = false;
        $defaults['clear_gallery'] = false;

        return $defaults;
    }

    /** @return list<array<string, mixed>> */
    private function fields(): array
    {
        return [
            $this->field('title', 'string', required: true, maxLength: 255),
            $this->field('author_ids', 'array<string>', required: false, meta: $this->relationCollectionMeta(
                'authors',
                submittedArray: 'replace_relation_sync',
                ordering: 'input_order',
                safeClientStrategy: 'omit_field_to_preserve_or_send_full_relation_ids',
            )),
            $this->field('type', 'string', required: true, default: ReferenceType::Book->value, allowedValues: $this->enumValues(ReferenceType::class)),
            $this->field('parent_id', 'string', required: false, meta: [
                'relation' => 'references',
                'accepted_parent_scope' => 'work_or_edition_books',
                'mutation_semantics' => 'replace_scalar',
                'clear_semantics' => ['omitted' => 'preserve_existing', 'explicit_null' => 'clear_parent_requires_work_record_kind'],
            ]),
            $this->field('record_kind', 'string', required: false, default: ReferenceRecordKind::Work->value, allowedValues: $this->enumValues(ReferenceRecordKind::class)),
            $this->field('edition_number', 'integer', required: false),
            $this->field('edition_label', 'string', required: false, maxLength: 255),
            $this->field('isbn', 'string', required: false, maxLength: 20),
            $this->field('language', 'string', required: false, maxLength: 10, meta: $this->trimmedStringMutationMeta()),
            $this->field('url', 'url', required: false, maxLength: 255),
            $this->field('part_type', 'string', required: false, default: ReferencePartType::Jilid->value, allowedValues: $this->enumValues(ReferencePartType::class), meta: [
                'used_when' => 'record_kind_is_part',
            ]),
            $this->field('part_number', 'string', required: false, maxLength: 255, meta: $this->trimmedStringMutationMeta()),
            $this->field('part_label', 'string', required: false, maxLength: 255, meta: $this->trimmedStringMutationMeta()),
            $this->field('publication_year', 'integer', required: false, meta: $this->trimmedStringMutationMeta()),
            $this->field('publisher', 'string', required: false, maxLength: 255, meta: $this->trimmedStringMutationMeta()),
            $this->field('description', 'string', required: false),
            $this->field('status', 'string', required: true, default: 'verified', allowedValues: ['pending', 'verified', 'inactive']),
            $this->field('social_media', 'array<object>', required: false, meta: $this->socialMediaCollectionMeta()),
            $this->field('front_cover', 'file', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb()),
            $this->field('back_cover', 'file', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb()),
            $this->field('gallery', 'array<file>', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb(), maxFiles: 10),
            $this->field('clear_front_cover', 'boolean', required: false, default: false),
            $this->field('clear_back_cover', 'boolean', required: false, default: false),
            $this->field('clear_gallery', 'boolean', required: false, default: false),
        ];
    }
}
