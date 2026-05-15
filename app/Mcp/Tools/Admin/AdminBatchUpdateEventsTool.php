<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Admin;

use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventPrayerTime;
use App\Enums\EventType;
use App\Enums\EventVisibility;
use App\Enums\RegistrationMode;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Space;
use App\Models\Speaker;
use App\Models\Venue;
use App\Support\Api\Admin\AdminResourceService;
use Generator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
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
class AdminBatchUpdateEventsTool extends AbstractAdminWriteTool
{
    protected string $name = 'admin-batch-update-events';

    protected string $title = 'Batch Update Events';

    protected string $description = 'Use this MCP-only event wrapper to update multiple existing events in one request. Each item identifies the target event via event_key and resolves organizer, location, speakers, and references by route keys before calling the shared admin event update path. speaker_keys and reference_keys are full-sync aliases for the underlying speakers/references UUID arrays: omit the field or pass null to preserve existing relationships; pass [] to detach all; pass a non-empty array to replace all. Items are processed independently; the response contains a per-row result with status updated, validation_failed, unresolved_key, not_found, or error. Set validate_only=true to preview all rows without persisting. Include external_row_id per item for idempotency tracking and safe retries after interruption. Maximum 50 events per batch.';

    public function __construct(
        private readonly AdminResourceService $resourceService,
    ) {}

    public function handle(Request $request): Generator
    {
        yield Response::notification('notifications/message', [
            'level' => 'info',
            'data' => 'Validating and batch-updating events...',
        ]);

        yield $this->safeResponse(function () use ($request): ResponseFactory {
            $actor = $this->authorizeAdmin($request);

            $validated = $this->validateArguments($request, [
                'items' => ['required', 'array', 'min:1', 'max:50'],
                'items.*' => ['array'],
                'validate_only' => ['sometimes', 'boolean'],
            ]);

            $validateOnly = (bool) ($validated['validate_only'] ?? false);
            $resourceKey = 'events';

            /** @var list<array<string, mixed>> $rawItems */
            $rawItems = $validated['items'];

            /** @var array<int, array<string, mixed>> $resolvedItems */
            $resolvedItems = [];

            /** @var array<int, array<string, mixed>> $unresolvedResults */
            $unresolvedResults = [];

            foreach ($rawItems as $index => $item) {
                if (! is_array($item)) {
                    $item = [];
                }

                $externalRowId = isset($item['external_row_id']) && is_string($item['external_row_id'])
                    ? $item['external_row_id']
                    : null;

                $eventKey = isset($item['event_key']) && is_string($item['event_key']) && trim($item['event_key']) !== ''
                    ? trim($item['event_key'])
                    : null;

                /** @var array<string, mixed> $itemPayload */
                $itemPayload = isset($item['payload']) && is_array($item['payload']) ? $item['payload'] : $item;

                foreach (['external_row_id', 'event_key'] as $reservedKey) {
                    if (array_key_exists($reservedKey, $itemPayload)) {
                        unset($itemPayload[$reservedKey]);
                    }
                }

                if ($eventKey === null) {
                    $result = [
                        'row' => $index,
                        'status' => 'error',
                        'message' => 'Missing required field: event_key.',
                    ];

                    if ($externalRowId !== null) {
                        $result['external_row_id'] = $externalRowId;
                    }

                    $unresolvedResults[$index] = $result;

                    continue;
                }

                try {
                    $builtPayload = $this->buildEventPayload($itemPayload);

                    $resolvedItem = [
                        'record_key' => $eventKey,
                        'payload' => $builtPayload,
                    ];

                    if ($externalRowId !== null) {
                        $resolvedItem['external_row_id'] = $externalRowId;
                    }

                    $resolvedItems[$index] = $resolvedItem;
                } catch (ValidationException $exception) {
                    $result = [
                        'row' => $index,
                        'status' => 'unresolved_key',
                        'errors' => $exception->errors(),
                    ];

                    if ($externalRowId !== null) {
                        $result['external_row_id'] = $externalRowId;
                    }

                    $unresolvedResults[$index] = $result;
                }
            }

            // Build service items list (only resolved items, preserving original indices for re-mapping)
            $serviceItems = array_values($resolvedItems);

            // Map service row index (0-based position in $serviceItems) back to original index
            $serviceIndexToOriginalIndex = array_keys($resolvedItems);

            $batchResult = $this->resourceService->batchUpdateRecords(
                resourceKey: $resourceKey,
                items: $serviceItems,
                actor: $actor,
                validateOnly: $validateOnly,
            );

            // Re-map service result row indices to original indices
            /** @var array<int, array<string, mixed>> $finalResults */
            $finalResults = [];

            foreach ($batchResult['data']['results'] ?? [] as $result) {
                $serviceIndex = $result['row'] ?? 0;
                $originalIndex = $serviceIndexToOriginalIndex[$serviceIndex] ?? $serviceIndex;
                $result['row'] = $originalIndex;
                $finalResults[$originalIndex] = $result;
            }

            // Merge unresolved-key and missing-event-key failures at their original positions
            foreach ($unresolvedResults as $originalIndex => $unresolvedResult) {
                $finalResults[$originalIndex] = $unresolvedResult;
            }

            ksort($finalResults);
            $batchResult['data']['results'] = array_values($finalResults);

            // Recalculate summary with unresolved counts
            $unresolvedCount = count($unresolvedResults);
            $summary = $batchResult['data']['summary'] ?? [];

            /** @var array<string, int|bool> $summary */
            $summary['total'] = count($rawItems);

            if ($unresolvedCount > 0) {
                $summary['unresolved_key'] = ($summary['unresolved_key'] ?? 0) + $unresolvedCount;
            }

            $batchResult['data']['summary'] = $summary;

            return Response::structured($batchResult);
        });
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function buildEventPayload(array $item): array
    {
        $payload = $item;

        unset(
            $payload['validate_only'],
            $payload['organizer_key'],
            $payload['institution_key'],
            $payload['venue_key'],
            $payload['space_key'],
            $payload['speaker_keys'],
            $payload['reference_keys'],
        );

        $organizerType = $this->normalizeOrganizerType($item['organizer_type'] ?? null);

        if ($organizerType !== null) {
            $payload['organizer_type'] = $organizerType;
        }

        $organizerKey = $this->normalizeOptionalString($item['organizer_key'] ?? null);

        if ($organizerKey !== null) {
            $payload['organizer_id'] = $this->resolveRecordIdentifier(
                field: 'organizer_key',
                modelClass: $organizerType === Speaker::class ? Speaker::class : Institution::class,
                key: $organizerKey,
            );
        }

        $institutionKey = $this->normalizeOptionalString($item['institution_key'] ?? null);

        if ($institutionKey !== null) {
            $payload['institution_id'] = $this->resolveRecordIdentifier(
                field: 'institution_key',
                modelClass: Institution::class,
                key: $institutionKey,
            );
        }

        $venueKey = $this->normalizeOptionalString($item['venue_key'] ?? null);

        if ($venueKey !== null) {
            $payload['venue_id'] = $this->resolveRecordIdentifier(
                field: 'venue_key',
                modelClass: Venue::class,
                key: $venueKey,
            );
        }

        $spaceKey = $this->normalizeOptionalString($item['space_key'] ?? null);

        if ($spaceKey !== null) {
            $payload['space_id'] = $this->resolveRecordIdentifier(
                field: 'space_key',
                modelClass: Space::class,
                key: $spaceKey,
            );
        }

        $speakerKeys = array_values(array_filter(
            array_map(
                $this->normalizeOptionalString(...),
                (array) ($item['speaker_keys'] ?? [])
            ),
            static fn (?string $v): bool => $v !== null,
        ));

        if (array_key_exists('speaker_keys', $item) && is_array($item['speaker_keys'])) {
            $payload['speakers'] = array_values(array_map(
                fn (string $key): string => $this->resolveRecordIdentifier(
                    field: 'speaker_keys',
                    modelClass: Speaker::class,
                    key: $key,
                ),
                $speakerKeys,
            ));
        }

        $referenceKeys = array_values(array_filter(
            array_map(
                $this->normalizeOptionalString(...),
                (array) ($item['reference_keys'] ?? [])
            ),
            static fn (?string $v): bool => $v !== null,
        ));

        if (array_key_exists('reference_keys', $item) && is_array($item['reference_keys'])) {
            $payload['references'] = array_values(array_map(
                fn (string $key): string => $this->resolveRecordIdentifier(
                    field: 'reference_keys',
                    modelClass: Reference::class,
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
        $eventItemSchema = $schema->object([
            'event_key' => $schema->string()->required()->description('Route key of the event to update (slug or UUID).'),
            'external_row_id' => $schema->string()->nullable()->description('Optional caller-assigned row identifier for idempotency tracking and safe retries.'),
            'title' => $schema->string()->max(255),
            'description' => $schema->string()->nullable(),
            'event_date' => $schema->string()->description('Local event date in YYYY-MM-DD format.'),
            'prayer_time' => $schema->string()->enum($this->enumValues(EventPrayerTime::class)),
            'custom_time' => $schema->string()->nullable()->description('Required when prayer_time is lain_waktu. HH:MM preferred.'),
            'end_time' => $schema->string()->nullable()->description('Optional end time (HH:MM).'),
            'timezone' => $schema->string(),
            'event_format' => $schema->string()->enum($this->enumValues(EventFormat::class)),
            'visibility' => $schema->string()->enum($this->enumValues(EventVisibility::class)),
            'event_url' => $schema->string()->nullable(),
            'live_url' => $schema->string()->nullable(),
            'recording_url' => $schema->string()->nullable(),
            'gender' => $schema->string()->enum($this->enumValues(EventGenderRestriction::class)),
            'age_group' => $schema->array()->items($schema->string()->enum($this->enumValues(EventAgeGroup::class))),
            'children_allowed' => $schema->boolean(),
            'is_muslim_only' => $schema->boolean(),
            'event_type' => $schema->array()->items($schema->string()->enum($this->enumValues(EventType::class))),
            'organizer_type' => $schema->string()->enum(['institution', 'speaker', Institution::class, Speaker::class])->description('Organizer model type.'),
            'organizer_key' => $schema->string()->nullable()->description('Organizer route key (slug preferred, UUID allowed).'),
            'institution_key' => $schema->string()->nullable()->description('Institution route key (slug preferred, UUID allowed).'),
            'venue_key' => $schema->string()->nullable()->description('Venue route key (slug preferred, UUID allowed).'),
            'space_key' => $schema->string()->nullable()->description('Space route key (slug preferred, UUID allowed).'),
            'speaker_keys' => $schema->array()->items($schema->string())->nullable()->description('MCP-only route-key alias for the underlying speakers UUID array. Omit or pass null to preserve currently attached speakers. Pass [] to detach all speakers. Pass a non-empty array of speaker slugs/UUIDs to replace all attached speakers.'),
            'reference_keys' => $schema->array()->items($schema->string())->nullable()->description('MCP-only route-key alias for the underlying references UUID array. Omit or pass null to preserve currently linked references. Pass [] to detach all references. Pass a non-empty array of reference slugs/UUIDs to replace all linked references.'),
            'languages' => $schema->array()->items($schema->integer())->nullable(),
            'domain_tags' => $schema->array()->items($schema->string())->nullable(),
            'discipline_tags' => $schema->array()->items($schema->string())->nullable(),
            'source_tags' => $schema->array()->items($schema->string())->nullable(),
            'issue_tags' => $schema->array()->items($schema->string())->nullable(),
            'other_key_people' => $schema->array()->items(
                $schema->object([
                    'role' => $schema->string()->required(),
                    'speaker_id' => $schema->string()->nullable(),
                    'name' => $schema->string()->nullable(),
                    'is_public' => $schema->boolean()->default(true),
                    'notes' => $schema->string()->nullable(),
                ])
            )->nullable(),
            'series' => $schema->array()->items($schema->string())->nullable(),
            'status' => $schema->string()->enum(['draft', 'pending', 'approved']),
            'registration_required' => $schema->boolean(),
            'registration_mode' => $schema->string()->enum($this->enumValues(RegistrationMode::class)),
            'is_priority' => $schema->boolean(),
            'is_featured' => $schema->boolean(),
            'is_active' => $schema->boolean(),
        ]);

        return [
            'items' => $schema->array()->required()->min(1)->max(50)->items($eventItemSchema)->description('Array of event items to update. Each item must include event_key. Maximum 50 events per batch.'),
            'validate_only' => $schema->boolean()->default(false)->description('When true, validates all items without persisting. Returns per-row preview or validation error details.'),
        ];
    }
}
