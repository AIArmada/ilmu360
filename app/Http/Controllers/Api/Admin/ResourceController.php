<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Api\Admin\AdminResourceService;
use App\Support\Api\Admin\AdminValidateOnlyRemediationPlanner;
use App\Support\Api\Admin\AdminWriteValidationFeedback;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

#[Group(
    'Admin Resource',
    'Generic authenticated admin resource interface. '
    .'Read support is resource-key driven, while create and update are schema-driven and only available for resources whose `write_support.schema` flag is true.',
)]
class ResourceController extends Controller
{
    public function __construct(
        private readonly AdminResourceService $resourceService,
        private readonly AdminWriteValidationFeedback $validationFeedback,
    ) {}

    #[PathParameter('resourceKey', 'Admin resource key from `GET /admin/manifest`, for example `events`, `institutions`, or `speakers`.', example: 'events')]
    #[Endpoint(
        title: 'Get admin resource metadata',
        description: 'Returns metadata for a single admin resource, including read and write support flags and related API routes.',
    )]
    public function show(string $resourceKey): JsonResponse
    {
        return response()->json($this->resourceService->resourceMeta($resourceKey));
    }

    #[PathParameter('resourceKey', 'Admin resource key from `GET /admin/manifest`, for example `events`, `institutions`, or `speakers`.', example: 'events')]
    #[QueryParameter('search', 'Optional free-text search across the resource\'s searchable columns.', required: false, type: 'string', infer: false, example: 'maghrib')]
    #[QueryParameter('filter[status]', 'Optional status filter for resources that expose a status filter. Speakers accept `pending`, `verified`, and `rejected`; events accept `draft`, `pending`, `needs_changes`, `approved`, `cancelled`, and `rejected`.', required: false, type: 'string', infer: false, example: 'verified')]
    #[QueryParameter('filter[is_active]', 'Optional active-state filter for resources that expose it. Use `true` or `false`.', required: false, type: 'boolean', infer: false, example: true)]
    #[QueryParameter('filter[has_events]', 'Optional event-history filter for speaker resources. Use `true` for speakers linked to at least one event and `false` for speakers with no linked events.', required: false, type: 'boolean', infer: false, example: true)]
    #[QueryParameter('filter[visibility]', 'Optional visibility filter for event resources. Accepts `public`, `private`, or `unlisted`.', required: false, type: 'string', infer: false, example: 'public')]
    #[QueryParameter('filter[event_structure]', 'Optional event-structure filter for event resources.', required: false, type: 'string', infer: false, example: 'standalone')]
    #[QueryParameter('filter[event_format]', 'Optional event-format filter for event resources. Accepts `physical`, `online`, or `hybrid`.', required: false, type: 'string', infer: false, example: 'online')]
    #[QueryParameter('filter[event_type]', 'Optional event-type filter for event resources. Pass a single value or repeat the parameter for multiple event types.', required: false, type: 'string', infer: false, example: 'kuliah_ceramah')]
    #[QueryParameter('filter[timing_mode]', 'Optional timing-mode filter for event resources. Accepts `absolute` or `prayer_relative`.', required: false, type: 'string', infer: false, example: 'prayer_relative')]
    #[QueryParameter('filter[prayer_reference]', 'Optional prayer-reference filter for prayer-relative event resources.', required: false, type: 'string', infer: false, example: 'maghrib')]
    #[QueryParameter('starts_after', 'Optional date filter for date-aware admin resources. Interpreted in the resolved request timezone and converted to UTC before querying.', required: false, type: 'string', infer: false, example: '2026-04-12')]
    #[QueryParameter('starts_before', 'Optional date filter for date-aware admin resources. Interpreted in the resolved request timezone and converted to UTC before querying.', required: false, type: 'string', infer: false, example: '2026-04-12')]
    #[QueryParameter('starts_on_local_date', 'Optional local-date filter for date-aware admin resources. Interpreted in the resolved request timezone and converted to UTC day boundaries before querying.', required: false, type: 'string', infer: false, example: '2026-04-12')]
    #[QueryParameter('page', 'Pagination page number.', required: false, type: 'integer', infer: false, default: 1, example: 1)]
    #[QueryParameter('per_page', 'Pagination page size. Values are clamped to the server\'s allowed range.', required: false, type: 'integer', infer: false, default: 15, example: 15)]
    #[Endpoint(
        title: 'List admin resource records',
        description: 'Lists records for a single admin resource. '
            .'This is the read entrypoint for generic admin collections.',
    )]
    public function indexRecords(Request $request, string $resourceKey): JsonResponse
    {
        return response()->json($this->resourceService->listRecords(
            resourceKey: $resourceKey,
            search: (string) $request->query('search', ''),
            page: $request->integer('page', 1),
            perPage: $request->integer('per_page', 15),
            filters: $this->queryFilters($request),
            startsAfter: $this->queryString($request, 'starts_after'),
            startsBefore: $this->queryString($request, 'starts_before'),
            startsOnLocalDate: $this->queryString($request, 'starts_on_local_date'),
        ));
    }

    #[PathParameter('resourceKey', 'Admin resource key from `GET /admin/manifest`, for example `events`, `institutions`, or `speakers`.', example: 'events')]
    #[PathParameter('recordKey', 'Existing admin record route key returned by the collection or record endpoints.', example: '0195b86a-3c15-73fa-a2d8-5a45f6a7f701')]
    #[PathParameter('relation', 'Admin relation key from the resource metadata `relations` list. Use the exact key returned by `GET /admin/{resourceKey}/meta`.', example: 'speakers')]
    #[QueryParameter('search', 'Optional free-text search across the related resource or related model columns.', required: false, type: 'string', infer: false, example: 'maghrib')]
    #[QueryParameter('page', 'Pagination page number.', required: false, type: 'integer', infer: false, default: 1, example: 1)]
    #[QueryParameter('per_page', 'Pagination page size. Values are clamped to the server\'s allowed range.', required: false, type: 'integer', infer: false, default: 15, example: 15)]
    #[Endpoint(
        title: 'List admin related records',
        description: 'Lists the records attached to one named relation on a specific admin record. '
            .'Use the relation keys from `GET /admin/{resourceKey}/meta` to discover what this endpoint accepts.',
    )]
    public function relatedRecords(Request $request, string $resourceKey, string $recordKey, string $relation): JsonResponse
    {
        return response()->json($this->resourceService->listRelatedRecords(
            resourceKey: $resourceKey,
            recordKey: $recordKey,
            relation: $relation,
            search: (string) $request->query('search', ''),
            page: $request->integer('page', 1),
            perPage: $request->integer('per_page', 15),
        ));
    }

    #[PathParameter('resourceKey', 'Admin resource key from `GET /admin/manifest`, for example `events`, `institutions`, or `speakers`.', example: 'events')]
    #[QueryParameter('operation', 'Schema mode. Use `create` for new records or `update` for existing records.', required: false, type: 'string', infer: false, default: 'create', example: 'update')]
    #[QueryParameter('recordKey', 'Required when `operation=update`. Use the record route key returned by the admin collection or record endpoints.', required: false, type: 'string', infer: false, example: '0195b86a-3c15-73fa-a2d8-5a45f6a7f701')]
    #[Endpoint(
        title: 'Get admin write schema',
        description: 'Returns the exact create or update contract for a writable admin resource, including defaults, required flags, media state, and conditional rules. '
            .'Treat this response as authoritative for the selected resource and operation, because fields may be intentionally absent or prohibited even if older clients previously sent them. '
            .'Use this endpoint before calling the generic admin create or update routes because those mutation payloads are resource-specific and not statically described in the OpenAPI body schema.',
    )]
    public function schema(Request $request, string $resourceKey): JsonResponse
    {
        return response()->json($this->resourceService->writeSchema(
            resourceKey: $resourceKey,
            operation: (string) $request->query('operation', 'create'),
            recordKey: $request->query('recordKey'),
            actor: $this->currentUser($request),
        ));
    }

    #[PathParameter('resourceKey', 'Writable admin resource key from `GET /admin/manifest`.', example: 'speakers')]
    #[QueryParameter('validate_only', 'When true, validates and normalizes the payload without persisting changes. Successful responses return a preview envelope; validation failures return schema-driven `feedback` hints plus remediation details such as `fix_plan`, `remaining_blockers`, `normalized_payload_preview`, and `can_retry`.', required: false, type: 'boolean', infer: false, default: false, example: false)]
    #[QueryParameter('apply_defaults', 'When true together with `validate_only`, the server applies schema defaults before validating and returns the candidate normalized payload in validation feedback.', required: false, type: 'boolean', infer: false, default: false, example: false)]
    #[Endpoint(
        title: 'Create an admin resource record',
        description: 'Creates a record for a writable admin resource. '
            .'Set `validate_only=true` to preview the normalized payload and warning envelope without persisting the write. '
            .'Add `apply_defaults=true` during previews when you want validation failures to include a server-side autofill candidate payload. '
            .'If validation fails in validate-only mode, the API also returns remediation metadata so clients can auto-apply safe defaults and retry. '
            .'The request body is dynamic and depends on `resourceKey`, so fetch `GET /admin/{resourceKey}/schema?operation=create` first to obtain the canonical required and optional fields.',
    )]
    public function storeRecord(Request $request, string $resourceKey): JsonResponse
    {
        $actor = $this->currentUser($request);
        $payload = $request->all();
        $validateOnly = $request->boolean('validate_only');
        $applyDefaults = $validateOnly && $request->boolean('apply_defaults');
        $schemaResponse = null;

        if ($applyDefaults) {
            $schemaResponse = $this->resourceService->writeSchema(
                resourceKey: $resourceKey,
                operation: 'create',
                actor: $actor,
            );
            $payload = $this->validationFeedback->payloadWithSchemaDefaults($payload, $schemaResponse);
        }

        try {
            return response()->json(
                $this->resourceService->storeRecord(
                    resourceKey: $resourceKey,
                    payload: $payload,
                    actor: $actor,
                    validateOnly: $validateOnly,
                ),
                $validateOnly ? 200 : 201,
            );
        } catch (ValidationException $exception) {
            $schemaResponse ??= $this->resourceService->writeSchema(
                resourceKey: $resourceKey,
                operation: 'create',
                actor: $actor,
            );

            return $this->validationErrorResponse(
                exception: $exception,
                payload: $payload,
                schemaResponse: $schemaResponse,
                operation: 'create',
                validateOnly: $validateOnly,
                applyDefaults: $applyDefaults,
            );
        }
    }

    #[PathParameter('resourceKey', 'Writable admin resource key from `GET /admin/manifest`.', example: 'speakers')]
    #[PathParameter('recordKey', 'Existing admin record route key returned by the collection or record endpoints.', example: '0195b86a-3c15-73fa-a2d8-5a45f6a7f701')]
    #[QueryParameter('validate_only', 'When true, validates and normalizes the payload without persisting changes. Successful responses include the current record snapshot and destructive media clear-flag warnings; validation failures return schema-driven `feedback` hints plus remediation details such as `fix_plan`, `remaining_blockers`, `normalized_payload_preview`, and `can_retry`.', required: false, type: 'boolean', infer: false, default: false, example: false)]
    #[QueryParameter('apply_defaults', 'When true together with `validate_only`, the server applies schema defaults before validating and returns the candidate normalized payload in validation feedback.', required: false, type: 'boolean', infer: false, default: false, example: false)]
    #[Endpoint(
        title: 'Update an admin resource record',
        description: 'Updates a record for a writable admin resource. '
            .'Set `validate_only=true` to preview the normalized payload, current record snapshot, and warning envelope without persisting the write. '
            .'Add `apply_defaults=true` during previews when you want validation failures to include a server-side autofill candidate payload. '
            .'If validation fails in validate-only mode, the API also returns remediation metadata so clients can auto-apply safe defaults and retry. '
            .'The request body is dynamic and depends on both `resourceKey` and the existing record, so fetch `GET /admin/{resourceKey}/schema?operation=update&recordKey={recordKey}` first.',
    )]
    public function updateRecord(Request $request, string $resourceKey, string $recordKey): JsonResponse
    {
        $actor = $this->currentUser($request);
        $payload = $request->all();
        $validateOnly = $request->boolean('validate_only');
        $applyDefaults = $validateOnly && $request->boolean('apply_defaults');
        $schemaResponse = null;

        if ($applyDefaults) {
            $schemaResponse = $this->resourceService->writeSchema(
                resourceKey: $resourceKey,
                operation: 'update',
                recordKey: $recordKey,
                actor: $actor,
            );
            $payload = $this->validationFeedback->payloadWithSchemaDefaults($payload, $schemaResponse);
        }

        try {
            return response()->json($this->resourceService->updateRecord(
                resourceKey: $resourceKey,
                recordKey: $recordKey,
                payload: $payload,
                actor: $actor,
                validateOnly: $validateOnly,
            ));
        } catch (ValidationException $exception) {
            $schemaResponse ??= $this->resourceService->writeSchema(
                resourceKey: $resourceKey,
                operation: 'update',
                recordKey: $recordKey,
                actor: $actor,
            );

            return $this->validationErrorResponse(
                exception: $exception,
                payload: $payload,
                schemaResponse: $schemaResponse,
                operation: 'update',
                validateOnly: $validateOnly,
                applyDefaults: $applyDefaults,
            );
        }
    }

    #[PathParameter('resourceKey', 'Admin resource key from `GET /admin/manifest`.', example: 'events')]
    #[PathParameter('recordKey', 'Existing admin record route key returned by the collection endpoint.', example: '0195b86a-3c15-73fa-a2d8-5a45f6a7f701')]
    #[Endpoint(
        title: 'Get an admin resource record',
        description: 'Returns a single admin record together with its serialized attributes and ability flags.',
    )]
    public function showRecord(string $resourceKey, string $recordKey): JsonResponse
    {
        return response()->json($this->resourceService->showRecord($resourceKey, $recordKey, includeResourceMeta: true));
    }

    #[PathParameter('resourceKey', 'Writable admin resource key from `GET /admin/manifest`.', example: 'speakers')]
    #[QueryParameter('validate_only', 'When true, validates all items without persisting any changes. Returns per-row preview or validation error details.', required: false, type: 'boolean', infer: false, default: false, example: false)]
    #[Endpoint(
        title: 'Batch create admin resource records',
        description: 'Creates multiple records for a writable admin resource in a single request. '
            .'Each item is processed independently. The response includes a per-row result list with statuses: `created`, `validation_failed`, or `error`. '
            .'Set `validate_only=true` to preview normalized payloads and catch validation errors upfront without persisting any records. '
            .'Optionally include `external_row_id` in each item payload for idempotency tracking and safe retries. '
            .'The request body must contain a `items` array. Each item may include an optional `external_row_id` for retry tracking and a `payload` object matching the create schema for the resource.',
    )]
    public function batchStoreRecords(Request $request, string $resourceKey): JsonResponse
    {
        $actor = $this->currentUser($request);
        $validateOnly = $request->boolean('validate_only');

        /** @var list<array<string, mixed>> $items */
        $items = $request->input('items', []);

        if (! is_array($items)) {
            return response()->json([
                'message' => 'The items field must be an array.',
                'errors' => ['items' => ['The items field must be an array.']],
            ], 422);
        }

        return response()->json(
            $this->resourceService->batchStoreRecords(
                resourceKey: $resourceKey,
                items: $items,
                actor: $actor,
                validateOnly: $validateOnly,
            ),
            $validateOnly ? 200 : 201,
        );
    }

    #[PathParameter('resourceKey', 'Writable admin resource key from `GET /admin/manifest`.', example: 'speakers')]
    #[QueryParameter('validate_only', 'When true, validates all items without persisting any changes. Returns per-row preview or validation error details.', required: false, type: 'boolean', infer: false, default: false, example: false)]
    #[Endpoint(
        title: 'Batch update admin resource records',
        description: 'Updates multiple records for a writable admin resource in a single request. '
            .'Each item is processed independently. The response includes a per-row result list with statuses: `updated`, `validation_failed`, `not_found`, or `error`. '
            .'Set `validate_only=true` to preview normalized payloads and catch validation errors upfront without persisting any changes. '
            .'The request body must contain an `items` array. Each item must include a `record_key` and a `payload` object matching the update schema for the resource. '
            .'An optional `external_row_id` per item enables idempotency tracking and safe retries.',
    )]
    public function batchUpdateRecords(Request $request, string $resourceKey): JsonResponse
    {
        $actor = $this->currentUser($request);
        $validateOnly = $request->boolean('validate_only');

        /** @var list<array<string, mixed>> $items */
        $items = $request->input('items', []);

        if (! is_array($items)) {
            return response()->json([
                'message' => 'The items field must be an array.',
                'errors' => ['items' => ['The items field must be an array.']],
            ], 422);
        }

        return response()->json(
            $this->resourceService->batchUpdateRecords(
                resourceKey: $resourceKey,
                items: $items,
                actor: $actor,
                validateOnly: $validateOnly,
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function queryFilters(Request $request): array
    {
        $filterBag = $request->query('filter');

        return is_array($filterBag) ? $filterBag : [];
    }

    private function queryString(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : null;
    }

    private function currentUser(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $schemaResponse
     */
    private function validationErrorResponse(
        ValidationException $exception,
        array $payload,
        array $schemaResponse,
        string $operation,
        bool $validateOnly,
        bool $applyDefaults,
    ): JsonResponse {
        $candidatePayload = $validateOnly && $applyDefaults
            ? $payload
            : null;

        $details = [
            'feedback' => $this->validationFeedback->feedback(
                exception: $exception,
                payload: $payload,
                schemaResponse: $schemaResponse,
                operation: $operation,
                validateOnly: $validateOnly,
                applyDefaults: $applyDefaults,
                candidatePayload: $candidatePayload,
            ),
        ];

        if ($validateOnly) {
            $details = [
                ...$details,
                ...app(AdminValidateOnlyRemediationPlanner::class)->build(
                    payload: $payload,
                    schemaResponse: $schemaResponse,
                    errors: $exception->errors(),
                ),
            ];
        }

        return response()->json([
            'message' => $this->validationFeedback->message($exception),
            'errors' => $exception->errors(),
            'error' => [
                'code' => 'validation_error',
                'details' => $details,
            ],
        ], 422);
    }
}
