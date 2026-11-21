<?php

declare(strict_types=1);

namespace App\Features\Events\EntryPoints\Admin\Resources;

use AIArmada\Addressing\Support\ModelResolver;
use AIArmada\Events\Models\EventTaxonomy;
use AIArmada\Events\Models\EventTerm;
use App\Features\Events\Api\AdminEventWriter;
use App\Features\Events\Api\Enums\EventAgeGroup;
use App\Features\Events\Api\Enums\EventFormat;
use App\Features\Events\Api\Enums\EventGenderRestriction;
use App\Features\Events\Api\Enums\EventKeyPersonRole;
use App\Features\Events\Api\Enums\EventVisibility;
use App\Features\Events\Api\EventAuthoring;
use App\Features\Events\Api\EventCategoryCatalog;
use App\Features\Events\Api\EventTaxonomyCode;
use App\Features\Events\Api\SeriesAuthoring;
use App\Features\Events\Api\RegistrationScope;
use App\Features\Events\Models\Event;
use App\Features\Events\Models\Series;
use App\Features\Prayer\Api\EventPrayerTime;
use App\Models\User;
use App\Platform\Admin\Concerns\BuildsAdminFieldMetadata;
use App\Platform\Api\Admin\Contracts\AdminMutationHooks;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

final readonly class EventMutationHooks implements AdminMutationHooks
{
    use BuildsAdminFieldMetadata;

    public function __construct(
        private AdminEventWriter $adminEventWriter,
        private EventAuthoring $eventAuthoring,
        private SeriesAuthoring $seriesAuthoring,
    ) {}

    public function supports(string $resourceClass): bool
    {
        return in_array($resourceClass, [
            \App\Features\Events\EntryPoints\Filament\Resources\Events\EventResource::class,
            \App\Features\Events\EntryPoints\Filament\Resources\Series\SeriesResource::class,
        ], true);
    }

    public function schema(string $resourceClass, string $resourceKey, string $operation, ?Model $record, array $defaults): ?array
    {
        $updating = $operation === 'update';

        if ($resourceClass === \App\Features\Events\EntryPoints\Filament\Resources\Events\EventResource::class) {
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
                'current_media' => $record instanceof Event ? $this->mediaState($record, ['cover', 'poster', 'gallery']) : null,
                'fields' => $this->eventFields($updating),
                'catalogs' => [
                    $this->catalog('submission_country_id', route('api.admin.catalogs.countries', [], false)),
                ],
                'conditional_rules' => [
                    ['field' => 'custom_time', 'required_when' => ['prayer_time' => [EventPrayerTime::LainWaktu->value]]],
                ],
            ];
        }

        if ($resourceClass === \App\Features\Events\EntryPoints\Filament\Resources\Series\SeriesResource::class) {
            return [
                'resource_key' => $resourceKey,
                'operation' => $operation,
                'method' => $updating ? 'PUT' : 'POST',
                'endpoint' => $updating && $record instanceof Model
                    ? route('api.admin.resources.update', ['resourceKey' => $resourceKey, 'recordKey' => $this->recordKey($record)], false)
                    : route('api.admin.resources.store', ['resourceKey' => $resourceKey], false),
                'content_type' => 'multipart/form-data',
                'slug_behavior' => 'user_provided',
                'defaults' => $defaults,
                'current_media' => $record instanceof Series ? $this->mediaState($record, ['cover', 'gallery']) : null,
                'fields' => $this->seriesFields(),
                'catalogs' => [],
                'conditional_rules' => [],
            ];
        }

        return null;
    }

    public function rules(string $resourceClass, bool $updating): ?array
    {
        return match ($resourceClass) {
            \App\Features\Events\EntryPoints\Filament\Resources\Events\EventResource::class => $this->eventRules($updating),
            \App\Features\Events\EntryPoints\Filament\Resources\Series\SeriesResource::class => $this->seriesRules($updating),
            default => null,
        };
    }

    public function normalize(string $resourceClass, array $validated, ?Model $record): array
    {
        return $validated;
    }

    public function store(string $resourceClass, array $validated, User $actor): ?Model
    {
        return match ($resourceClass) {
            \App\Features\Events\EntryPoints\Filament\Resources\Events\EventResource::class => $this->persistAdminEvent($validated, $actor),
            \App\Features\Events\EntryPoints\Filament\Resources\Series\SeriesResource::class => Series::query()->with(['languages', 'media'])->findOrFail($this->seriesAuthoring->save($validated)),
            default => null,
        };
    }

    public function update(string $resourceClass, Model $record, array $validated, User $actor): ?Model
    {
        if ($resourceClass === \App\Features\Events\EntryPoints\Filament\Resources\Events\EventResource::class && $record instanceof Event) {
            return $this->persistAdminEvent($validated, $actor, $record);
        }

        if ($resourceClass === \App\Features\Events\EntryPoints\Filament\Resources\Series\SeriesResource::class && $record instanceof Series) {
            return Series::query()->with(['languages', 'media'])->findOrFail($this->seriesAuthoring->save($validated, (string) $record->getKey()));
        }

        return null;
    }

    public function defaultsForCreate(string $resourceClass): ?array
    {
        if ($resourceClass === \App\Features\Events\EntryPoints\Filament\Resources\Events\EventResource::class) {
            return $this->eventAuthoring->adminFormDefaults();
        }

        if ($resourceClass === \App\Features\Events\EntryPoints\Filament\Resources\Series\SeriesResource::class) {
            return [
                'title' => '',
                'slug' => '',
                'description' => null,
                'visibility' => 'public',
                'status' => 'active',
                'languages' => [],
                'clear_cover' => false,
                'clear_gallery' => false,
            ];
        }

        return null;
    }

    public function augmentDefaultsForRecord(Model $record, array $defaults): array
    {
        if ($record instanceof Series) {
            $defaults = [
                'title' => $record->title,
                'slug' => $record->slug,
                'description' => $record->description,
                'visibility' => (string) $record->visibility,
                'status' => (string) $record->status,
                'languages' => $record->languages()->pluck('languages.id')->map(fn (mixed $id): string => (string) $id)->values()->all(),
                'clear_cover' => false,
                'clear_gallery' => false,
            ];
        }

        if ($record instanceof Event) {
            $defaults = $this->eventAuthoring->adminFormState((string) $record->getKey());
        }

        return $defaults;
    }

    /** @param array<string, mixed> $data */
    private function persistAdminEvent(array $data, User $actor, ?Event $event = null): Event
    {
        $id = $this->adminEventWriter->save($data, (string) $actor->getKey(), $event?->getKey());

        return Event::query()->with(['accessPolicy', 'references', 'series', 'classifications', 'keyPeople', 'languages', 'media', 'institution', 'venue', 'primaryLocation.venueSpace'])->findOrFail($id);
    }

    /**
     /**
     * @return array<int, array<string, mixed>>
     */
    private function eventFields(bool $updating): array
    {
        $fields = [
            $this->field('title', 'string', required: ! $updating, maxLength: 255),
            $this->field('description', 'rich_text', required: false),
            $this->field('event_date', 'date', required: ! $updating),
            $this->field('prayer_time', 'string', required: ! $updating, default: EventPrayerTime::LainWaktu->value, allowedValues: $this->enumValues(EventPrayerTime::class)),
            $this->field('custom_time', 'string', required: false, maxLength: 32),
            $this->field('end_time', 'string', required: false, maxLength: 32),
            $this->field('timezone', 'string', required: ! $updating, default: 'Asia/Kuala_Lumpur', maxLength: 64),
            $this->field('event_format', 'string', required: ! $updating, default: EventFormat::Physical->value, allowedValues: $this->enumValues(EventFormat::class)),
            $this->field('visibility', 'string', required: ! $updating, default: EventVisibility::Public->value, allowedValues: $this->enumValues(EventVisibility::class)),
            $this->field('event_url', 'string', required: false, maxLength: 255, meta: $this->trimmedStringMutationMeta(omitted: 'preserve_existing_via_server_state_merge')),
            $this->field('live_url', 'string', required: false, maxLength: 255, meta: $this->trimmedStringMutationMeta(omitted: 'preserve_existing_via_server_state_merge')),
            $this->field('recording_url', 'string', required: false, maxLength: 255, meta: $this->trimmedStringMutationMeta(omitted: 'preserve_existing_via_server_state_merge')),
            $this->field('gender', 'string', required: ! $updating, default: EventGenderRestriction::All->value, allowedValues: $this->enumValues(EventGenderRestriction::class)),
            $this->field('age_group', 'array<string>', required: ! $updating, allowedValues: $this->enumValues(EventAgeGroup::class), meta: [
                'collection_semantics' => $this->replaceCollectionSemantics(
                    explicitNull: 'invalid_type',
                    emptyArray: 'invalid_minimum_size',
                    itemIdsPreserved: null,
                    ordering: null,
                    safeClientStrategy: 'omit_field_to_preserve_or_send_full_age_group_array',
                    omitted: 'preserve_existing_collection_via_server_state_merge',
                ),
            ]),
            $this->field('children_allowed', 'boolean', required: false, default: false),
            $this->field('is_muslim_only', 'boolean', required: false, default: false),
            $this->field('languages', 'array<string>', required: false, meta: $this->relationCollectionMeta(
                'languages',
                submittedArray: 'replace_relation_sync',
                safeClientStrategy: 'omit_field_to_preserve_or_send_full_relation_ids',
                omitted: 'preserve_existing_collection_via_server_state_merge',
            )),
            $this->field('event_category_ids', 'array<uuid>', required: ! $updating, allowedValues: array_keys(app(EventCategoryCatalog::class)->options()), meta: [
                'collection_semantics' => $this->replaceCollectionSemantics(
                    explicitNull: 'invalid_type',
                    emptyArray: 'invalid_minimum_size',
                    itemIdsPreserved: null,
                    ordering: null,
                    safeClientStrategy: 'omit_field_to_preserve_or_send_full_event_category_ids_array',
                    omitted: 'preserve_existing_collection_via_server_state_merge',
                ),
            ]),
            $this->field('domain_tags', 'array<string>', required: false, meta: array_merge(
                $this->relationCollectionMeta(
                    'classifications',
                    submittedArray: 'replace_relation_sync',
                    safeClientStrategy: 'omit_field_to_preserve_or_send_full_event_term_ids',
                    omitted: 'preserve_existing_collection_via_server_state_merge',
                ),
                ['taxonomy_code' => EventTaxonomyCode::Domain->value],
            )),
            $this->field('discipline_tags', 'array<string>', required: false, meta: array_merge(
                $this->relationCollectionMeta(
                    'classifications',
                    submittedArray: 'replace_relation_sync',
                    safeClientStrategy: 'omit_field_to_preserve_or_send_full_event_term_ids',
                    omitted: 'preserve_existing_collection_via_server_state_merge',
                ),
                ['taxonomy_code' => EventTaxonomyCode::Discipline->value],
            )),
            $this->field('source_tags', 'array<string>', required: false, meta: array_merge(
                $this->relationCollectionMeta(
                    'classifications',
                    submittedArray: 'replace_relation_sync',
                    safeClientStrategy: 'omit_field_to_preserve_or_send_full_event_term_ids',
                    omitted: 'preserve_existing_collection_via_server_state_merge',
                ),
                ['taxonomy_code' => EventTaxonomyCode::Source->value],
            )),
            $this->field('issue_tags', 'array<string>', required: false, meta: array_merge(
                $this->relationCollectionMeta(
                    'classifications',
                    submittedArray: 'replace_relation_sync',
                    safeClientStrategy: 'omit_field_to_preserve_or_send_full_event_term_ids',
                    omitted: 'preserve_existing_collection_via_server_state_merge',
                ),
                ['taxonomy_code' => EventTaxonomyCode::Issue->value],
            )),
            $this->field('references', 'array<string>', required: false, meta: $this->relationCollectionMeta(
                'references',
                submittedArray: 'replace_relation_sync',
                safeClientStrategy: 'omit_field_to_preserve_or_send_full_reference_ids',
                omitted: 'preserve_existing_collection_via_server_state_merge',
            )),
            $this->field('series', 'array<string>', required: false, meta: $this->relationCollectionMeta(
                'series',
                submittedArray: 'replace_relation_sync',
                safeClientStrategy: 'omit_field_to_preserve_or_send_full_series_ids',
                omitted: 'preserve_existing_collection_via_server_state_merge',
            )),
            $this->field('primary_organizer_id', 'string', required: ! $updating, meta: [
                'mutation_semantics' => 'replace_scalar',
                'clear_semantics' => [
                    'omitted' => 'preserve_existing_via_server_state_merge',
                    'explicit_null' => 'invalid_required_value',
                ],
                'accepted_models' => [
                    \App\Shared\Support\MorphAliasModel::classFor('institution'),
                    \App\Shared\Support\MorphAliasModel::classFor('person'),
                ],
            ]),
            $this->field('institution_id', 'string', required: false, meta: [
                'mutation_semantics' => 'replace_scalar',
                'clear_semantics' => [
                    'omitted' => 'preserve_existing_via_server_state_merge',
                    'explicit_null' => 'clear_to_null',
                ],
                'exclusive_with' => ['venue_id'],
            ]),
            $this->field('venue_id', 'string', required: false, meta: [
                'mutation_semantics' => 'replace_scalar',
                'clear_semantics' => [
                    'omitted' => 'preserve_existing_via_server_state_merge',
                    'explicit_null' => 'clear_to_null',
                ],
                'exclusive_with' => ['institution_id', 'space_id'],
            ]),
            $this->field('submission_country_id', 'string', required: false, meta: [
                'mutation_semantics' => 'replace_scalar',
                'clear_semantics' => [
                    'omitted' => 'preserve_existing_via_server_state_merge',
                    'explicit_null' => 'clear_to_null',
                ],
                'relation' => 'address-countries',
            ]),
            $this->field('space_id', 'string', required: false, meta: [
                'mutation_semantics' => 'replace_scalar',
                'clear_semantics' => [
                    'omitted' => 'preserve_existing_via_server_state_merge',
                    'explicit_null' => 'clear_to_null',
                ],
                'requires' => ['institution_id'],
                'prohibited_with' => ['venue_id'],
            ]),
            $this->field('persons', 'array<string>', required: false, meta: [
                'relation' => 'key_people',
                'subset_scope' => 'persons',
                'collection_semantics' => $this->replaceCollectionSemantics(
                    submittedArray: 'replace_person_subset_and_rebuild_key_people',
                    itemIdsPreserved: false,
                    ordering: 'payload_order_sets_order_column_before_other_key_people',
                    safeClientStrategy: 'omit_field_to_preserve_or_send_full_person_list',
                    omitted: 'preserve_existing_collection_via_server_state_merge',
                ),
            ]),
            $this->field('other_key_people', 'array<object>', required: false, meta: [
                'relation' => 'key_people',
                'subset_scope' => 'other_key_people',
                'collection_semantics' => $this->replaceCollectionSemantics(
                    submittedArray: 'replace_other_key_people_subset_and_rebuild_key_people',
                    itemIdsPreserved: false,
                    ordering: 'payload_order_sets_order_column_after_persons',
                    safeClientStrategy: 'omit_field_to_preserve_or_send_full_other_key_people_array',
                    omitted: 'preserve_existing_collection_via_server_state_merge',
                ),
                'item_schema' => $this->eventKeyPersonItemSchema(),
            ]),
            $this->field('cover', 'file', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb(), meta: [
                ...$this->singleMediaFieldMutationMeta('clear_cover'),
                'required_aspect_ratio' => '16:9',
                'media_role' => 'website_app_cover',
            ]),
            $this->field('poster', 'file', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb(), meta: [
                ...$this->singleMediaFieldMutationMeta('clear_poster'),
                'required_aspect_ratio' => '3:4',
                'media_role' => 'external_distribution_poster',
            ]),
            $this->field('gallery', 'array<file>', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb(), maxFiles: 10),
            $this->field('clear_cover', 'boolean', required: false, default: false),
            $this->field('clear_poster', 'boolean', required: false, default: false),
            $this->field('clear_gallery', 'boolean', required: false, default: false),
            $this->field('registration_required', 'boolean', required: false, default: false),
            $this->field('registration_mode', 'string', required: false, default: RegistrationScope::Event->value, allowedValues: $this->enumValues(RegistrationScope::class), meta: [
                'mutation_semantics' => 'replace_setting_with_runtime_lock',
                'clear_semantics' => [
                    'omitted' => 'preserve_existing_via_server_state_merge',
                ],
                'lock_behavior' => [
                    'when_event_has_registrations' => 'retain_current_value',
                ],
            ]),
        ];

        if (! $updating) {
            $fields[] = $this->field('status', 'string', required: false, default: 'draft', allowedValues: ['draft', 'pending', 'approved']);
        }

        return $fields;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function seriesFields(): array
    {
        return [
            $this->field('title', 'string', required: true, maxLength: 255),
            $this->field('slug', 'string', required: true, maxLength: 255, meta: [
                'mutation_semantics' => 'replace_scalar',
                'normalization' => ['trim' => true],
                'uniqueness_scope' => 'series.slug',
            ]),
            $this->field('description', 'string', required: false, maxLength: 5000, meta: $this->trimmedStringMutationMeta()),
            $this->field('visibility', 'string', required: true, default: 'public', allowedValues: ['public', 'unlisted', 'private']),
            $this->field('status', 'string', required: false, default: 'active', allowedValues: ['active', 'inactive']),
            $this->field('languages', 'array<string>', required: false, meta: $this->relationCollectionMeta(
                'languages',
                submittedArray: 'replace_relation_sync',
                safeClientStrategy: 'omit_field_to_preserve_or_send_full_relation_ids',
            )),
            $this->field('cover', 'file', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb()),
            $this->field('gallery', 'array<file>', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb()),
            $this->field('clear_cover', 'boolean', required: false, default: false),
            $this->field('clear_gallery', 'boolean', required: false, default: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function eventKeyPersonItemSchema(): array
    {
        return [
            'type' => 'object',
            'required_fields' => ['role_code'],
            'at_least_one_of' => ['involveable_id', 'display_name'],
            'empty_or_invalid_entries_discarded' => true,
            'fields' => [
                $this->field('role_code', 'string', required: false, allowedValues: $this->enumValues(EventKeyPersonRole::class), meta: [
                    'required_with' => ['involveable_id', 'display_name'],
                    'disallowed_values' => [EventKeyPersonRole::Speaker->value],
                ]),
                $this->field('involveable_type', 'string', required: false, allowedValues: ['person'], meta: [
                    'required_with' => ['involveable_id'],
                ]),
                $this->field('involveable_id', 'string', required: false, meta: [
                    'required_without' => ['display_name'],
                ]),
                $this->field('display_name', 'string', required: false, maxLength: 255, meta: [
                    'required_without' => ['involveable_id'],
                ]),
                $this->field('visibility', 'string', required: false, default: 'public', allowedValues: ['public', 'private']),
                $this->field('notes', 'string', required: false, maxLength: 500),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function eventRules(bool $updating): array
    {
        $required = $updating ? 'sometimes' : 'required';
        $maxUploadSize = 'max:'.$this->maxUploadSizeKb();

        $rules = [
            'title' => [$required, 'string', 'max:255'],
            'description' => ['nullable'],
            'event_date' => [$required, 'date'],
            'prayer_time' => [$required, Rule::enum(EventPrayerTime::class)],
            'custom_time' => ['nullable', 'string', 'max:32', 'required_if:prayer_time,'.EventPrayerTime::LainWaktu->value],
            'end_time' => ['nullable', 'string', 'max:32'],
            'timezone' => [$required, 'string', 'max:64'],
            'event_format' => [$required, Rule::enum(EventFormat::class)],
            'visibility' => [$required, Rule::enum(EventVisibility::class)],
            'event_url' => ['nullable', 'url', 'max:255'],
            'live_url' => ['nullable', 'url', 'max:255'],
            'recording_url' => ['nullable', 'url', 'max:255'],
            'gender' => [$required, Rule::enum(EventGenderRestriction::class)],
            'age_group' => [$required, 'array', 'min:1'],
            'age_group.*' => [Rule::enum(EventAgeGroup::class)],
            'children_allowed' => ['sometimes', 'boolean'],
            'is_muslim_only' => ['sometimes', 'boolean'],
            'languages' => ['nullable', 'array'],
            'languages.*' => ['string', 'exists:languages,id'],
            'event_category_ids' => [$required, 'array', 'min:1'],
            'event_category_ids.*' => ['uuid', Rule::in(array_keys(app(EventCategoryCatalog::class)->options()))],
            'domain_tags' => ['nullable', 'array'],
            'domain_tags.*' => $this->eventTermRules(EventTaxonomyCode::Domain),
            'discipline_tags' => ['nullable', 'array'],
            'discipline_tags.*' => $this->eventTermRules(EventTaxonomyCode::Discipline),
            'source_tags' => ['nullable', 'array'],
            'source_tags.*' => $this->eventTermRules(EventTaxonomyCode::Source),
            'issue_tags' => ['nullable', 'array'],
            'issue_tags.*' => $this->eventTermRules(EventTaxonomyCode::Issue),
            'references' => ['nullable', 'array'],
            'references.*' => ['uuid', 'exists:references,id'],
            'series' => ['nullable', 'array'],
            'series.*' => ['uuid', Rule::exists((new Series)->getTable(), 'id')],
            'primary_organizer_id' => [
                $required,
                'uuid',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value)) {
                        return;
                    }

                    if (
                        \App\Shared\Support\MorphAliasModel::classFor('institution')::query()->whereKey($value)->exists()
                        || \App\Shared\Support\MorphAliasModel::classFor('person')::query()->whereKey($value)->exists()
                    ) {
                        return;
                    }

                    $fail(__('The selected primary organizer is invalid.'));
                },
            ],
            'institution_id' => ['nullable', 'uuid', 'exists:institutions,id'],
            'venue_id' => ['nullable', 'uuid', 'exists:venues,id'],
            'submission_country_id' => ['nullable', 'uuid', Rule::exists(ModelResolver::countryClass(), 'id')],
            'space_ids' => ['nullable', 'array'],
            'space_ids.*' => ['uuid', 'exists:venue_spaces,id'],
            'persons' => ['nullable', 'array'],
            'persons.*' => ['uuid', 'exists:persons,id'],
            'other_key_people' => ['nullable', 'array'],
            'other_key_people.*.role_code' => ['required_with:other_key_people.*.display_name,other_key_people.*.involveable_id', Rule::enum(EventKeyPersonRole::class)],
            'other_key_people.*.involveable_type' => ['nullable', Rule::in(['person']), 'required_with:other_key_people.*.involveable_id'],
            'other_key_people.*.involveable_id' => ['nullable', 'uuid', 'exists:persons,id', 'required_without:other_key_people.*.display_name'],
            'other_key_people.*.display_name' => ['nullable', 'string', 'max:255', 'required_without:other_key_people.*.involveable_id'],
            'other_key_people.*.visibility' => ['sometimes', Rule::in(['public', 'private'])],
            'other_key_people.*.notes' => ['nullable', 'string', 'max:500'],
            'cover' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'dimensions:ratio=16/9', $maxUploadSize],
            'poster' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'dimensions:ratio=3/4', $maxUploadSize],
            'gallery' => ['nullable', 'array', 'max:10'],
            'gallery.*' => ['file', 'mimetypes:image/jpeg,image/png,image/webp', $maxUploadSize],
            'clear_cover' => ['sometimes', 'boolean'],
            'clear_poster' => ['sometimes', 'boolean'],
            'clear_gallery' => ['sometimes', 'boolean'],
            'registration_required' => ['sometimes', 'boolean'],
            'registration_mode' => ['sometimes', Rule::enum(RegistrationScope::class)],
        ];

        if (! $updating) {
            $rules['status'] = ['sometimes', Rule::in(['draft', 'pending', 'approved'])];
        }

        return $rules;
    }

    /**
     * @return list<mixed>
     */
    private function eventTermRules(EventTaxonomyCode $taxonomy): array
    {
        $taxonomyId = EventTaxonomy::query()
            ->where('code', $taxonomy->value)
            ->value('id');

        return [
            'uuid',
            Rule::exists((new EventTerm)->getTable(), 'id')
                ->where('event_taxonomy_id', $taxonomyId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function seriesRules(bool $updating): array
    {
        $required = $updating ? 'required' : 'required';
        $maxUploadSize = 'max:'.$this->maxUploadSizeKb();

        return [
            'title' => [$required, 'string', 'max:255'],
            'slug' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'visibility' => [$required, Rule::in(['public', 'unlisted', 'private'])],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'languages' => ['nullable', 'array'],
            'languages.*' => ['string', 'exists:languages,id'],
            'cover' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp', $maxUploadSize],
            'gallery' => ['nullable', 'array'],
            'gallery.*' => ['file', 'mimetypes:image/jpeg,image/png,image/webp', $maxUploadSize],
            'clear_cover' => ['sometimes', 'boolean'],
            'clear_gallery' => ['sometimes', 'boolean'],
        ];
    }
}
