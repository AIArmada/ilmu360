<?php

declare(strict_types=1);

namespace App\Features\Events\EntryPoints\Mcp\Tools;

use App\Features\Events\Api\Enums\EventAgeGroup;
use App\Features\Events\Api\Enums\EventFormat;
use App\Features\Events\Api\Enums\EventGenderRestriction;
use App\Features\Events\Api\Enums\EventVisibility;
use App\Features\Events\Api\EventCategoryCatalog;
use App\Features\Events\Api\RegistrationScope;
use App\Features\Institutions\Api\InstitutionLookup;
use App\Features\Persons\Api\PersonLookup;
use App\Features\Places\Api\PlaceCatalog;
use App\Features\Prayer\Api\EventPrayerTime;
use App\Features\References\Api\ReferenceLookup;
use App\Platform\Api\Admin\AdminResourceService;
use App\Platform\Mcp\Tools\Admin\AbstractAdminWriteTool;
use App\Platform\Mcp\Tools\Concerns\DefinesMcpFileDescriptorSchema;
use Generator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsIdempotent(false)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class AdminUpdateEventTool extends AbstractAdminWriteTool
{
    use DefinesMcpFileDescriptorSchema;

    #[\Override]
    protected string $name = 'admin-update-event';

    #[\Override]
    protected string $title = 'Update Event';

    #[\Override]
    protected string $description = 'Use this MCP event tool to update an existing event record by event_key. It resolves organizer, location, persons, and references by human-readable route keys before calling the shared admin event update path. For person_keys and reference_keys, omit or pass null to preserve existing relationships; pass [] to detach all; pass a non-empty array of route keys (slugs or UUIDs) to replace all. apply_defaults is only honored together with validate_only=true for preview/autofill feedback and is ignored for persisted updates. Supports cover, poster, and gallery image descriptors in the same request; media clear flags are not supported, so omit fields to leave existing media unchanged. Do not use to create a new event; use admin-create-event instead.';

    public function __construct(
        private readonly AdminResourceService $resourceService,
        private readonly InstitutionLookup $institutionLookup,
        private readonly PersonLookup $personLookup,
        private readonly PlaceCatalog $placeCatalog,
        private readonly ReferenceLookup $referenceLookup,
    ) {}

    public function handle(Request $request): Generator
    {
        yield Response::notification('notifications/message', [
            'level' => 'info',
            'data' => 'Validating and updating event...',
        ]);

        yield $this->safeResponse(function () use ($request): ResponseFactory {
            $actor = $this->authorizeAdmin($request);

            $validated = $this->validateArguments($request, [
                'event_key' => ['required', 'string'],
                'title' => ['sometimes', 'string', 'max:255'],
                'description' => ['nullable'],
                'event_date' => ['sometimes', 'date'],
                'prayer_time' => ['sometimes', 'string'],
                'custom_time' => ['nullable', 'string', 'max:32'],
                'end_time' => ['nullable', 'string', 'max:32'],
                'timezone' => ['sometimes', 'string', 'max:64'],
                'event_format' => ['sometimes', 'string'],
                'visibility' => ['sometimes', 'string'],
                'event_url' => ['nullable', 'url', 'max:255'],
                'live_url' => ['nullable', 'url', 'max:255'],
                'recording_url' => ['nullable', 'url', 'max:255'],
                'gender' => ['sometimes', 'string'],
                'age_group' => ['sometimes', 'array', 'min:1'],
                'age_group.*' => ['string'],
                'children_allowed' => ['sometimes', 'boolean'],
                'is_muslim_only' => ['sometimes', 'boolean'],
                'event_category_ids' => ['sometimes', 'array', 'min:1'],
                'event_category_ids.*' => ['uuid'],
                'primary_organizer_key' => ['nullable', 'string'],
                'institution_key' => ['nullable', 'string'],
                'venue_key' => ['nullable', 'string'],
                'space_key' => ['nullable', 'string'],
                'person_keys' => ['sometimes', 'nullable', 'array'],
                'person_keys.*' => ['string'],
                'reference_keys' => ['sometimes', 'nullable', 'array'],
                'reference_keys.*' => ['string'],
                'languages' => ['sometimes', 'nullable', 'array'],
                'languages.*' => ['uuid'],
                'domain_tags' => ['sometimes', 'nullable', 'array'],
                'domain_tags.*' => ['string'],
                'discipline_tags' => ['sometimes', 'nullable', 'array'],
                'discipline_tags.*' => ['string'],
                'source_tags' => ['sometimes', 'nullable', 'array'],
                'source_tags.*' => ['string'],
                'issue_tags' => ['sometimes', 'nullable', 'array'],
                'issue_tags.*' => ['string'],
                'other_key_people' => ['sometimes', 'nullable', 'array'],
                'other_key_people.*.role_code' => ['required_with:other_key_people.*.display_name,other_key_people.*.involveable_id', 'string'],
                'other_key_people.*.involveable_type' => ['nullable', 'string'],
                'other_key_people.*.involveable_id' => ['nullable', 'string'],
                'other_key_people.*.display_name' => ['nullable', 'string', 'max:255'],
                'other_key_people.*.visibility' => ['sometimes', 'string', Rule::in(['public', 'private'])],
                'other_key_people.*.notes' => ['nullable', 'string', 'max:500'],
                'series' => ['sometimes', 'nullable', 'array'],
                'series.*' => ['string'],
                'cover' => ['sometimes', 'array'],
                'poster' => ['sometimes', 'array'],
                'gallery' => ['sometimes', 'array', 'max:10'],
                'gallery.*' => ['array'],
                'status' => ['sometimes', 'string', 'in:draft,pending,approved'],
                'registration_required' => ['sometimes', 'boolean'],
                'registration_mode' => ['sometimes', 'string'],
                'validate_only' => ['sometimes', 'boolean'],
                'apply_defaults' => ['sometimes', 'boolean'],
            ]);

            $eventKey = (string) $validated['event_key'];
            $validateOnly = (bool) ($validated['validate_only'] ?? false);
            $applyDefaults = (bool) ($validated['apply_defaults'] ?? false);

            $resourceKey = 'events';

            $schemaResponse = $this->resourceService->writeSchema(
                resourceKey: $resourceKey,
                operation: 'update',
                recordKey: $eventKey,
                actor: $actor,
            );

            $payload = $this->buildEventPayload($validated);
            $this->ensureDestructiveMediaClearFlagsAreUnsupported($payload);

            $normalizedMediaPayload = $this->normalizeMcpMediaPayload($payload, $schemaResponse);

            try {
                if ($validateOnly && $applyDefaults) {
                    $normalizedMediaPayload['payload'] = $this->payloadWithSchemaDefaults($normalizedMediaPayload['payload'], $schemaResponse);
                }

                /** @var array<string, mixed> $normalizedPayload */
                $normalizedPayload = $normalizedMediaPayload['payload'];

                return Response::structured($this->resourceService->updateRecord(
                    resourceKey: $resourceKey,
                    recordKey: $eventKey,
                    payload: $normalizedPayload,
                    actor: $actor,
                    validateOnly: $validateOnly,
                ));
            } catch (ValidationException $exception) {
                return $this->writeValidationErrorResponse(
                    exception: $exception,
                    payload: $payload,
                    schemaResponse: $schemaResponse,
                    resourceKey: $resourceKey,
                    operation: 'update',
                    validateOnly: $validateOnly,
                    applyDefaults: $applyDefaults,
                );
            } finally {
                $this->cleanupMcpMediaPayload($normalizedMediaPayload);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function buildEventPayload(array $validated): array
    {
        $payload = $validated;

        unset(
            $payload['event_key'],
            $payload['validate_only'],
            $payload['apply_defaults'],
            $payload['primary_organizer_key'],
            $payload['institution_key'],
            $payload['venue_key'],
            $payload['space_key'],
            $payload['person_keys'],
            $payload['reference_keys'],
        );

        if (array_key_exists('primary_organizer_key', $validated)) {
            $primaryOrganizerKey = $this->normalizeOptionalString($validated['primary_organizer_key'] ?? null);
            $payload['primary_organizer_id'] = $primaryOrganizerKey !== null
                ? $this->resolvePrimaryOrganizerIdentifier(
                    field: 'primary_organizer_key',
                    key: $primaryOrganizerKey,
                    institutionLookup: $this->institutionLookup->resolveIdentifier(...),
                    personLookup: $this->personLookup->resolveIdentifier(...),
                )
                : null;
        }

        $institutionKey = $this->normalizeOptionalString($validated['institution_key'] ?? null);

        if ($institutionKey !== null) {
            $payload['institution_id'] = $this->resolveRecordIdentifier(
                field: 'institution_key',
                lookup: $this->institutionLookup->resolveIdentifier(...),
                key: $institutionKey,
            );
        }

        $venueKey = $this->normalizeOptionalString($validated['venue_key'] ?? null);

        if ($venueKey !== null) {
            $payload['venue_id'] = $this->resolveRecordIdentifier(
                field: 'venue_key',
                lookup: $this->placeCatalog->resolveVenueIdentifier(...),
                key: $venueKey,
            );
        }

        $spaceKey = $this->normalizeOptionalString($validated['space_key'] ?? null);

        if ($spaceKey !== null) {
            $payload['space_id'] = $this->resolveRecordIdentifier(
                field: 'space_key',
                lookup: $this->placeCatalog->resolveSpaceIdentifier(...),
                key: $spaceKey,
            );
        }

        $personKeys = array_values(array_filter(
            array_map(
                $this->normalizeOptionalString(...),
                (array) ($validated['person_keys'] ?? [])
            ),
            static fn (?string $v): bool => $v !== null,
        ));

        if (array_key_exists('person_keys', $validated) && is_array($validated['person_keys'])) {
            $payload['persons'] = array_values(array_map(
                fn (string $key): string => $this->resolveRecordIdentifier(
                    field: 'person_keys',
                    lookup: $this->personLookup->resolveIdentifier(...),
                    key: $key,
                ),
                $personKeys,
            ));
        }

        $referenceKeys = array_values(array_filter(
            array_map(
                $this->normalizeOptionalString(...),
                (array) ($validated['reference_keys'] ?? [])
            ),
            static fn (?string $v): bool => $v !== null,
        ));

        if (array_key_exists('reference_keys', $validated) && is_array($validated['reference_keys'])) {
            $payload['references'] = array_values(array_map(
                fn (string $key): string => $this->resolveRecordIdentifier(
                    field: 'reference_keys',
                    lookup: $this->referenceLookup->resolveIdentifier(...),
                    key: $key,
                ),
                $referenceKeys,
            ));
        }

        return $payload;
    }

    /**
     * @return array<string, Type>
     */
    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'event_key' => $schema->string()->required()->min(1)->description('Route key of the event to update (slug or UUID).'),
            'title' => $schema->string()->max(255),
            'description' => $schema->string()->nullable(),
            'event_date' => $schema->string()->description('Local event date in YYYY-MM-DD format.'),
            'prayer_time' => $schema->string()->enum($this->enumValues(EventPrayerTime::class)),
            'custom_time' => $schema->string()->nullable()->description('Required when prayer_time is lain_waktu. HH:MM preferred.'),
            'end_time' => $schema->string()->nullable()->description('Optional end time (HH:MM).'),
            'timezone' => $schema->string(),
            'event_format' => $schema->string()->enum($this->enumValues(EventFormat::class)),
            'visibility' => $schema->string()->enum($this->enumValues(EventVisibility::class)),
            'event_url' => $schema->string()->nullable()->description('Public event URL when applicable.'),
            'live_url' => $schema->string()->nullable()->description('Livestream URL when applicable.'),
            'recording_url' => $schema->string()->nullable()->description('Recording URL when applicable.'),
            'gender' => $schema->string()->enum($this->enumValues(EventGenderRestriction::class)),
            'age_group' => $schema->array()->items($schema->string()->enum($this->enumValues(EventAgeGroup::class))),
            'children_allowed' => $schema->boolean(),
            'is_muslim_only' => $schema->boolean(),
            'event_category_ids' => $schema->array()->items($schema->string()->enum(array_keys(app(EventCategoryCatalog::class)->options()))),
            'primary_organizer_key' => $schema->string()->nullable()->description('Primary organizer route key (institution or person slug preferred, UUID allowed). Omit to preserve the current organizer.'),
            'institution_key' => $schema->string()->nullable()->description('Institution route key (slug preferred, UUID allowed).'),
            'venue_key' => $schema->string()->nullable()->description('Venue route key (slug preferred, UUID allowed).'),
            'space_key' => $schema->string()->nullable()->description('Space route key (slug preferred, UUID allowed).'),
            'person_keys' => $schema->array()->items($schema->string())->nullable()->description('Person route keys (slugs or UUIDs). Omit or pass null to preserve currently attached persons. Pass [] to detach all persons. Pass a non-empty array to replace all attached persons.'),
            'reference_keys' => $schema->array()->items($schema->string())->nullable()->description('Reference route keys (slugs or UUIDs). Omit or pass null to preserve currently linked references. Pass [] to detach all references. Pass a non-empty array to replace all linked references.'),
            'languages' => $schema->array()->items($schema->string())->nullable()->description('Array of language record UUIDs.'),
            'domain_tags' => $schema->array()->items($schema->string())->nullable()->description('Array of domain/category tag UUIDs (max 3).'),
            'discipline_tags' => $schema->array()->items($schema->string())->nullable()->description('Array of discipline/field-of-study tag UUIDs.'),
            'source_tags' => $schema->array()->items($schema->string())->nullable()->description('Array of source tag UUIDs.'),
            'issue_tags' => $schema->array()->items($schema->string())->nullable()->description('Array of issue/theme tag UUIDs.'),
            'other_key_people' => $schema->array()->items(
                $schema->object([
                    'role_code' => $schema->string()->required()->description('One of the non-speaker EventKeyPersonRole values.'),
                    'involveable_type' => $schema->string()->nullable()->description('Morph type for the linked profile, such as person.'),
                    'involveable_id' => $schema->string()->nullable()->description('UUID of the linked profile. Required when display_name is omitted.'),
                    'display_name' => $schema->string()->nullable()->description('Display name. Required when involveable_id is omitted.'),
                    'visibility' => $schema->string()->enum(['public', 'private'])->default('public'),
                    'notes' => $schema->string()->nullable(),
                ])
            )->nullable()->description('Optional non-speaker key people (moderators, khatib, imam, bilal, PIC, etc.).'),
            'series' => $schema->array()->items($schema->string())->nullable()->description('Array of series UUIDs to link the event to.'),
            'cover' => $this->fileDescriptorSchema($schema)
                ->nullable()
                ->description('Optional event cover image descriptor (16:9). Pass filename and exactly one content source.'),
            'poster' => $this->fileDescriptorSchema($schema)
                ->nullable()
                ->description('Optional event poster image descriptor (3:4). Pass filename and exactly one content source.'),
            'gallery' => $schema->array()->items($this->fileDescriptorSchema($schema))
                ->nullable()->description('Optional gallery image descriptors (max 10 items).'),
            'status' => $schema->string()->enum(['draft', 'pending', 'approved']),
            'registration_required' => $schema->boolean(),
            'registration_mode' => $schema->string()->enum($this->enumValues(RegistrationScope::class)),
            'validate_only' => $schema->boolean()->default(false),
            'apply_defaults' => $schema->boolean()->default(false)->description('Preview-only helper. Honored only when validate_only=true to merge schema defaults into validation feedback; ignored for persisted updates.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        $tool = parent::toArray();

        $tool['_meta'] = array_merge(
            is_array($tool['_meta'] ?? null) ? $tool['_meta'] : [],
            [
                'openai/note' => 'You may send event fields and image descriptors together in one call. For cover, poster, and gallery descriptors, pass filename and exactly one of content_base64, content_url, or download_url.',
            ],
        );

        return $tool;
    }
}
