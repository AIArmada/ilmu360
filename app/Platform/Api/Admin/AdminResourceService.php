<?php

declare(strict_types=1);

namespace App\Platform\Api\Admin;

use App\Models\User;
use App\Platform\Api\Resources\AbstractAdminResourceWriter;
use App\Platform\Api\Resources\FilamentResourceDefinition;
use App\Platform\Api\Resources\ResourceActor;
use App\Platform\Api\Resources\ResourceActorResolver;
use App\Platform\Api\Resources\ResourceQuery;
use App\Platform\Api\Resources\ResourceReadSupport;
use App\Platform\Api\Resources\ResourceSurface;
use App\Platform\Api\SurfaceSyncPolicy;
use App\Platform\ApiDocumentation\ApiDocumentationUrlResolver;
use App\Platform\Documentation\DocumentationLibrary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AdminResourceService
{
    public function __construct(
        private readonly AdminResourceRegistry $registry,
        private readonly ApiDocumentationUrlResolver $urlResolver,
        private readonly DocumentationLibrary $documentationLibrary,
        private readonly ResourceActorResolver $actors,
    ) {}

    /**
     * @return array{data: array{resources: list<array<string, mixed>>}}
     */
    public function manifest(bool $compact = false, bool $writableOnly = false): array
    {
        $resources = array_values(array_filter(
            array_map(
                $this->registry->metadata(...),
                $this->registry->accessibleResources(),
            ),
            fn (array $resource): bool => ! $writableOnly || $resource['write_support']['schema'] === true,
        ));

        return [
            'data' => [
                'version' => '2026-04-21',
                'docs' => [
                    'ui' => $this->urlResolver->docsUrl(),
                    'openapi' => $this->urlResolver->docsJsonUrl(),
                    'api_base' => $this->urlResolver->apiBaseUrl(),
                    'catalog_endpoint' => $this->documentationLibrary->apiCatalogEndpoint(),
                    'document_endpoint_template' => $this->documentationLibrary->apiDocumentEndpointTemplate(),
                    'library' => $this->documentationLibrary->apiLibrary(),
                ],
                'surface_sync' => SurfaceSyncPolicy::manifest(),
                'write_workflow' => [
                    'discover_resources' => route('api.admin.manifest'),
                    'fetch_create_schema_template' => route('api.admin.resources.schema', ['resourceKey' => 'resourceKey'], false).'?operation=create',
                    'fetch_update_schema_template' => route('api.admin.resources.schema', ['resourceKey' => 'resourceKey'], false).'?operation=update&recordKey=recordKey',
                    'create_endpoint_template' => route('api.admin.resources.store', ['resourceKey' => 'resourceKey'], false),
                    'update_endpoint_template' => route('api.admin.resources.update', ['resourceKey' => 'resourceKey', 'recordKey' => 'recordKey'], false),
                ],
                'workflow_actions' => [
                    'moderate_event' => [
                        'schema_endpoint_template' => route('api.admin.events.moderation-schema', ['recordKey' => 'recordKey'], false),
                        'endpoint_template' => route('api.admin.events.moderate', ['recordKey' => 'recordKey'], false),
                        'mcp_schema_tool' => 'admin-get-event-moderation-schema',
                        'mcp_tool' => 'admin-moderate-event',
                    ],
                    'triage_report' => [
                        'schema_endpoint_template' => route('api.admin.reports.triage-schema', ['recordKey' => 'recordKey'], false),
                        'endpoint_template' => route('api.admin.reports.triage', ['recordKey' => 'recordKey'], false),
                        'mcp_schema_tool' => 'admin-get-report-triage-schema',
                        'mcp_tool' => 'admin-triage-report',
                    ],
                    'review_contribution_request' => [
                        'schema_endpoint_template' => route('api.admin.contribution-requests.review-schema', ['recordKey' => 'recordKey'], false),
                        'endpoint_template' => route('api.admin.contribution-requests.review', ['recordKey' => 'recordKey'], false),
                        'mcp_schema_tool' => 'admin-get-contribution-request-review-schema',
                        'mcp_tool' => 'admin-review-contribution-request',
                    ],
                    'review_membership_application' => [
                        'schema_endpoint_template' => route('api.admin.membership-applications.review-schema', ['recordKey' => 'recordKey'], false),
                        'endpoint_template' => route('api.admin.membership-applications.review', ['recordKey' => 'recordKey'], false),
                        'mcp_schema_tool' => 'admin-get-membership-application-review-schema',
                        'mcp_tool' => 'admin-review-membership-application',
                    ],
                ],
                'rules' => [
                    'Use resource keys returned by the manifest to select the correct admin schema and route family.',
                    'Use the admin record route_key returned by admin collection or detail payloads for record-specific paths.',
                    'Use the relation keys returned by admin resource metadata when traversing nested related records through the related-record route or MCP tool.',
                    'Fetch the exact schema before every create or update because required fields and catalogs are resource-specific.',
                    'Send validate_only=true to preview and normalize a write request without persisting it; inspect the returned warning envelope before retrying without the flag.',
                    'Use workflow_actions for explicit admin review, moderation, report-triage, and dedicated workflow-schema flows that are not part of generic CRUD.',
                    'Admin PUT requests are full schema-guided updates, not partial patches.',
                    'Use api_routes for HTTP API clients and mcp_tools for MCP clients; MCP tools take structured arguments instead of URL paths.',
                    'Use authenticated /admin/catalogs/* endpoints for dependent selectors referenced by schema catalog metadata.',
                ],
                'catalogs' => [
                    'countries' => route('api.admin.catalogs.countries'),
                    'states' => route('api.admin.catalogs.states'),
                    'cities' => route('api.admin.catalogs.cities'),
                    'administrative_district' => route('api.admin.catalogs.administrative-districts'),
                    'administrative_subdivision' => route('api.admin.catalogs.administrative-subdivisions'),
                ],
                'resources' => array_values(array_map(
                    fn (array $resource): array => $compact ? $this->summarizeResource($resource) : $resource,
                    $resources,
                )),
            ],
        ];
    }

    /**
     * @return array{data: array{resource: array<string, mixed>}}
     */
    public function resourceMeta(string $resourceKey): array
    {
        $definition = $this->resolveDefinition($resourceKey);

        return [
            'data' => [
                'resource' => $definition->metadata($this->currentActor()),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listRecords(
        string $resourceKey,
        string $search = '',
        int $page = 1,
        int $perPage = 15,
        array $filters = [],
        ?string $startsAfter = null,
        ?string $startsBefore = null,
        ?string $startsOnLocalDate = null,
    ): array {
        return $this->resolveDefinition($resourceKey)->list(
            $this->currentActor(),
            new ResourceQuery(
                search: $search,
                page: $page,
                perPage: $perPage,
                filters: $filters,
                startsAfter: $startsAfter,
                startsBefore: $startsBefore,
                startsOnLocalDate: $startsOnLocalDate,
            ),
        );
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listRelatedRecords(string $resourceKey, string $recordKey, string $relation, string $search = '', int $page = 1, int $perPage = 15): array
    {
        return $this->resolveDefinition($resourceKey)->related(
            $this->currentActor(),
            $recordKey,
            $relation,
            new ResourceQuery(search: $search, page: $page, perPage: $perPage),
        );
    }

    /**
     * @return array{data: array{resource?: array<string, mixed>, record: array<string, mixed>}}
     */
    public function showRecord(string $resourceKey, string $recordKey, bool $includeResourceMeta = false): array
    {
        $definition = $this->resolveDefinition($resourceKey);
        $data = $definition->show($this->currentActor(), $recordKey)['data'];

        if ($includeResourceMeta) {
            $data = ['resource' => $definition->metadata($this->currentActor()), ...$data];
        }

        return ['data' => $data];
    }

    /**
     * @return array{data: array{resource_key: string, record_key: string, media: list<array<string, mixed>>, media_count: int, card_image_url?: string|null, poster_url?: string|null}}
     */
    public function recordMedia(string $resourceKey, string $recordKey): array
    {
        return $this->resolveDefinition($resourceKey)->media($this->currentActor(), $recordKey);
    }

    /**
     * @return array{data: array{resource: array<string, mixed>, schema: array<string, mixed>}}
     */
    public function writeSchema(string $resourceKey, string $operation = 'create', ?string $recordKey = null, ?User $actor = null): array
    {
        $definition = $this->resolveWritableDefinition($resourceKey);
        $writer = $this->writerOf($definition);

        $schema = $writer->schema(
            $actor instanceof User ? $this->actors->fromUser($actor) : new ResourceActor('', $definition->surface()),
            $operation,
            $recordKey,
        );

        return [
            'data' => [
                'resource' => $definition->metadata($this->currentActor()),
                'schema' => $schema,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function storeRecord(string $resourceKey, array $payload, ?User $actor = null, bool $validateOnly = false): array
    {
        $definition = $this->resolveWritableDefinition($resourceKey);
        $writer = $this->writerOf($definition);
        $resourceActor = $this->actorFor($actor);

        if ($validateOnly) {
            return [
                'data' => [
                    'resource' => $definition->metadata($this->currentActor()),
                    'preview' => $writer->validateAndPreview($resourceActor, 'create', null, $payload),
                ],
            ];
        }

        $result = $writer->write($resourceActor, 'create', null, $payload);

        return [
            'data' => [
                'resource' => $definition->metadata($this->currentActor()),
                'record' => $result['record'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateRecord(string $resourceKey, string $recordKey, array $payload, ?User $actor = null, bool $validateOnly = false): array
    {
        $definition = $this->resolveWritableDefinition($resourceKey);
        $writer = $this->writerOf($definition);
        $resourceActor = $this->actorFor($actor);

        if ($validateOnly) {
            return [
                'data' => [
                    'resource' => $definition->metadata($this->currentActor()),
                    'preview' => $writer->validateAndPreview($resourceActor, 'update', $recordKey, $payload),
                ],
            ];
        }

        $result = $writer->write($resourceActor, 'update', $recordKey, $payload);

        return [
            'data' => [
                'resource' => $definition->metadata($this->currentActor()),
                'record' => $result['record'],
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public function batchStoreRecords(string $resourceKey, array $items, ?User $actor = null, bool $validateOnly = false): array
    {
        $definition = $this->resolveWritableDefinition($resourceKey);
        $writer = $this->writerOf($definition);
        $resourceClass = $definition->resourceClass();

        if (! $actor instanceof User || ! $actor->can('create', $resourceClass::getModel())) {
            abort(403);
        }

        $results = [];
        $created = 0;
        $validationFailed = 0;
        $errors = 0;

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                $item = [];
            }

            $externalRowId = isset($item['external_row_id']) && is_string($item['external_row_id'])
                ? $item['external_row_id']
                : null;

            /** @var array<string, mixed> $payload */
            $payload = isset($item['payload']) && is_array($item['payload']) ? $item['payload'] : $item;

            if (array_key_exists('external_row_id', $payload)) {
                unset($payload['external_row_id']);
            }

            try {
                $validated = $writer->validatePayload($payload, updating: false);

                if ($validateOnly) {
                    $preview = $writer->previewResponse('create', $validated, null, $actor);
                    $results[] = array_filter([
                        'row' => $index,
                        'external_row_id' => $externalRowId,
                        'status' => 'preview',
                        'preview' => $preview,
                    ], static fn (mixed $value): bool => $value !== null);
                    $created++;
                } else {
                    $record = $writer->storeModel($actor, $validated);
                    $result = [
                        'row' => $index,
                        'status' => 'created',
                        'record' => $this->registry->serializeRecordDetail($resourceClass, $record),
                    ];

                    if ($externalRowId !== null) {
                        $result['external_row_id'] = $externalRowId;
                    }

                    $results[] = $result;
                    $created++;
                }
            } catch (ValidationException $exception) {
                $result = [
                    'row' => $index,
                    'status' => 'validation_failed',
                    'errors' => $exception->errors(),
                ];

                if ($externalRowId !== null) {
                    $result['external_row_id'] = $externalRowId;
                }

                $results[] = $result;
                $validationFailed++;
            } catch (\Throwable $exception) {
                $result = [
                    'row' => $index,
                    'status' => 'error',
                    'message' => $exception->getMessage(),
                ];

                if ($externalRowId !== null) {
                    $result['external_row_id'] = $externalRowId;
                }

                $results[] = $result;
                $errors++;
            }
        }

        return [
            'data' => [
                'resource' => $definition->metadata($this->currentActor()),
                'validate_only' => $validateOnly,
                'results' => $results,
                'summary' => [
                    'total' => count($items),
                    $validateOnly ? 'previewed' : 'created' => $created,
                    'validation_failed' => $validationFailed,
                    'errors' => $errors,
                ],
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items  Each item must contain 'record_key' and 'payload'.
     * @return array<string, mixed>
     */
    public function batchUpdateRecords(string $resourceKey, array $items, ?User $actor = null, bool $validateOnly = false): array
    {
        $definition = $this->resolveWritableDefinition($resourceKey);
        $writer = $this->writerOf($definition);
        $resourceClass = $definition->resourceClass();

        if (! $actor instanceof User) {
            abort(403);
        }

        $results = [];
        $updated = 0;
        $validationFailed = 0;
        $errors = 0;
        $notFound = 0;

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                $item = [];
            }

            $recordKey = isset($item['record_key']) && is_string($item['record_key']) ? $item['record_key'] : null;
            $externalRowId = isset($item['external_row_id']) && is_string($item['external_row_id'])
                ? $item['external_row_id']
                : null;

            /** @var array<string, mixed> $payload */
            $payload = isset($item['payload']) && is_array($item['payload']) ? $item['payload'] : [];

            if ($recordKey === null || trim($recordKey) === '') {
                $result = [
                    'row' => $index,
                    'status' => 'error',
                    'message' => 'Missing required field: record_key.',
                ];

                if ($externalRowId !== null) {
                    $result['external_row_id'] = $externalRowId;
                }

                $results[] = $result;
                $errors++;

                continue;
            }

            try {
                $record = $this->registry->resolveRecord($resourceClass, $recordKey);

                if (! $actor->can('update', $record)) {
                    $result = [
                        'row' => $index,
                        'record_key' => $recordKey,
                        'status' => 'error',
                        'message' => 'Insufficient permissions to update this record.',
                    ];

                    if ($externalRowId !== null) {
                        $result['external_row_id'] = $externalRowId;
                    }

                    $results[] = $result;
                    $errors++;

                    continue;
                }

                $validated = $writer->validatePayload($payload, updating: true, record: $record);

                if ($validateOnly) {
                    $preview = $writer->previewResponse('update', $validated, $record, $actor);
                    $results[] = array_filter([
                        'row' => $index,
                        'record_key' => $recordKey,
                        'external_row_id' => $externalRowId,
                        'status' => 'preview',
                        'preview' => $preview,
                    ], static fn (mixed $value): bool => $value !== null);
                    $updated++;
                } else {
                    $record = $writer->updateModel($actor, $record, $validated);
                    $result = [
                        'row' => $index,
                        'record_key' => $recordKey,
                        'status' => 'updated',
                        'record' => $this->registry->serializeRecordDetail($resourceClass, $record),
                    ];

                    if ($externalRowId !== null) {
                        $result['external_row_id'] = $externalRowId;
                    }

                    $results[] = $result;
                    $updated++;
                }
            } catch (NotFoundHttpException) {
                $result = [
                    'row' => $index,
                    'record_key' => $recordKey,
                    'status' => 'not_found',
                    'message' => "Record not found: {$recordKey}.",
                ];

                if ($externalRowId !== null) {
                    $result['external_row_id'] = $externalRowId;
                }

                $results[] = $result;
                $notFound++;
            } catch (ValidationException $exception) {
                $result = [
                    'row' => $index,
                    'record_key' => $recordKey,
                    'status' => 'validation_failed',
                    'errors' => $exception->errors(),
                ];

                if ($externalRowId !== null) {
                    $result['external_row_id'] = $externalRowId;
                }

                $results[] = $result;
                $validationFailed++;
            } catch (\Throwable $exception) {
                $result = [
                    'row' => $index,
                    'record_key' => $recordKey,
                    'status' => 'error',
                    'message' => $exception->getMessage(),
                ];

                if ($externalRowId !== null) {
                    $result['external_row_id'] = $externalRowId;
                }

                $results[] = $result;
                $errors++;
            }
        }

        return [
            'data' => [
                'resource' => $definition->metadata($this->currentActor()),
                'validate_only' => $validateOnly,
                'results' => $results,
                'summary' => [
                    'total' => count($items),
                    $validateOnly ? 'previewed' : 'updated' => $updated,
                    'validation_failed' => $validationFailed,
                    'not_found' => $notFound,
                    'errors' => $errors,
                ],
            ],
        ];
    }

    public function hasAnyWritableResourceAccess(?User $user = null): bool
    {
        if (! $user instanceof User || ! $user->hasApplicationAdminAccess()) {
            return false;
        }

        foreach ($this->registry->resources() as $resourceClass) {
            if (! $this->registry->writer($resourceClass) instanceof AbstractAdminResourceWriter) {
                continue;
            }

            if ($user->can('create', $resourceClass::getModel())) {
                return true;
            }
        }

        return false;
    }

    /** @param Builder<Model> $query */
    public function applyDefaultOrdering(Builder $query, string $resourceClass): void
    {
        ResourceReadSupport::applyDefaultOrdering($query, $resourceClass);
    }

    /**
     * @param  array<string, mixed>  $resource
     * @return array<string, mixed>
     */
    protected function summarizeResource(array $resource): array
    {
        return [
            'key' => $resource['key'],
            'model_label' => $resource['model_label'],
            'plural_model_label' => $resource['plural_model_label'],
            'navigation_group' => $resource['navigation_group'],
            'pages' => $resource['pages'],
            'abilities' => $resource['abilities'],
            'filters' => $resource['filters'],
            'write_support' => $resource['write_support'],
            'api_routes' => [
                'collection' => $resource['api_routes']['collection'],
                'meta' => $resource['api_routes']['meta'],
                'schema' => $resource['api_routes']['schema'],
                'store' => $resource['api_routes']['store'],
                'related_collection' => $resource['api_routes']['related_collection'],
                'item_template' => $resource['api_routes']['item_template'],
                'update_template' => $resource['api_routes']['update_template'],
            ],
            'mcp_tools' => $resource['mcp_tools'],
            'timezone_sensitive' => $resource['timezone_sensitive'],
            'date_semantics' => $resource['date_semantics'],
        ];
    }

    private function resolveDefinition(string $resourceKey): FilamentResourceDefinition
    {
        $resourceClass = $this->registry->resolve($resourceKey);

        if (! is_string($resourceClass)) {
            throw new NotFoundHttpException;
        }

        abort_unless($this->registry->canAccessResource($resourceClass), 403);

        $definition = $this->registry->definition($resourceClass);

        if (! $definition instanceof FilamentResourceDefinition) {
            throw new NotFoundHttpException;
        }

        return $definition;
    }

    private function resolveWritableDefinition(string $resourceKey): FilamentResourceDefinition
    {
        $definition = $this->resolveDefinition($resourceKey);

        if (! $definition->writer() instanceof AbstractAdminResourceWriter) {
            throw new NotFoundHttpException;
        }

        return $definition;
    }

    private function writerOf(FilamentResourceDefinition $definition): AbstractAdminResourceWriter
    {
        $writer = $definition->writer();

        if (! $writer instanceof AbstractAdminResourceWriter) {
            throw new NotFoundHttpException;
        }

        return $writer;
    }

    private function currentActor(): ResourceActor
    {
        return $this->actors->current();
    }

    private function actorFor(?User $actor): ResourceActor
    {
        return $actor instanceof User
            ? $this->actors->fromUser($actor)
            : new ResourceActor('', ResourceSurface::Admin);
    }
}
