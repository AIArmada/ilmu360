<?php

declare(strict_types=1);

namespace App\Support\Api\Member;

use App\Models\User;
use App\Support\Api\ApiPagination;
use App\Support\Api\ResourceSearchDispatcher;
use App\Support\ApiDocumentation\ApiDocumentationUrlResolver;
use App\Support\Timezone\UserDateTimeFormatter;
use Carbon\CarbonInterface;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class MemberResourceService
{
    public function __construct(
        private readonly MemberResourceRegistry $registry,
        private readonly MemberResourceMutationService $mutationService,
        private readonly ApiDocumentationUrlResolver $urlResolver,
        private readonly ResourceSearchDispatcher $resourceSearchDispatcher,
    ) {}

    /**
     * @return array{data: array<string, mixed>}
     */
    public function manifest(bool $compact = false): array
    {
        $resources = array_values(array_map(
            $this->registry->metadata(...),
            $this->registry->accessibleResources(),
        ));

        return [
            'data' => [
                'version' => '2026-04-21',
                'audience' => 'member',
                'panel' => 'ahli',
                'docs' => [
                    'ui' => $this->urlResolver->docsUrl(),
                    'openapi' => $this->urlResolver->docsJsonUrl(),
                    'api_base' => $this->urlResolver->apiBaseUrl(),
                ],
                'write_workflow' => [
                    'fetch_update_schema_tool' => 'member-get-write-schema',
                    'list_related_records_tool' => 'member-list-related-records',
                    'update_tool' => 'member-update-record',
                ],
                'workflow_tools' => [
                    'list_contribution_requests' => [
                        'tool' => 'member-list-contribution-requests',
                    ],
                    'approve_contribution_request' => [
                        'tool' => 'member-approve-contribution-request',
                    ],
                    'reject_contribution_request' => [
                        'tool' => 'member-reject-contribution-request',
                    ],
                    'cancel_contribution_request' => [
                        'tool' => 'member-cancel-contribution-request',
                    ],
                    'list_membership_claims' => [
                        'tool' => 'member-list-membership-claims',
                    ],
                    'submit_membership_claim' => [
                        'tool' => 'member-submit-membership-claim',
                    ],
                    'cancel_membership_claim' => [
                        'tool' => 'member-cancel-membership-claim',
                    ],
                ],
                'rules' => [
                    'Use the returned resource keys to scope follow-up MCP reads to your own memberships and related records.',
                    'Resource visibility follows the Ahli workspace boundary and live membership relationships, not the full admin panel.',
                    'Member MCP writes are limited to schema-guided updates for records the current user can already edit through the Ahli surface.',
                    'Fetch the exact update schema before every member write because required fields remain resource-specific.',
                    'Use validate_only=true to preview and normalize a member write without persisting it.',
                    'Use the relation keys returned by member resource metadata when traversing one-level related records.',
                    'Use workflow_tools for Ahli queue flows such as contribution approvals and membership claims; resources cover scoped CRUD only.',
                    'Use mcp_tools for MCP clients; panel_routes are browser destinations and are not MCP call surfaces.',
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
        $resourceClass = $this->resolveAccessibleResource($resourceKey);

        return [
            'data' => [
                'resource' => $this->registry->metadata($resourceClass),
            ],
        ];
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listRecords(
        string $resourceKey,
        string $search = '',
        int $page = 1,
        int $perPage = 15,
        ?string $startsAfter = null,
        ?string $startsBefore = null,
        ?string $startsOnLocalDate = null,
    ): array {
        $resourceClass = $this->resolveAccessibleResource($resourceKey);
        $query = $this->registry->queryFor($resourceClass);
        $normalizedSearch = trim($search);

        if ($normalizedSearch !== '') {
            $this->applySearch($query, $resourceClass, $normalizedSearch);
        }

        $this->applyDateFilters(
            $query,
            $resourceClass,
            $startsAfter,
            $startsBefore,
            $startsOnLocalDate,
        );

        $this->applyDefaultOrdering($query, $resourceClass);

        $records = $query->paginate(
            perPage: ApiPagination::normalizePerPage($perPage, default: 15, max: 100),
            page: max($page, 1),
        );

        return [
            'data' => array_values(array_map(
                fn (Model $record): array => $this->registry->serializeRecord($resourceClass, $record),
                $records->items(),
            )),
            'meta' => $this->recordsMeta($resourceClass, $records, $normalizedSearch),
        ];
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listRelatedRecords(string $resourceKey, string $recordKey, string $relation, string $search = '', int $page = 1, int $perPage = 15): array
    {
        $resourceClass = $this->resolveAccessibleResource($resourceKey);
        $record = $this->registry->resolveRecord($resourceClass, $recordKey);
        $resourceMeta = $this->registry->metadata($resourceClass);
        $relationName = Str::snake(trim($relation));

        abort_unless(in_array($relationName, $resourceMeta['relations'], true), 404);

        $relationMethod = Str::camel($relationName);

        if (! method_exists($record, $relationMethod)) {
            throw new NotFoundHttpException;
        }

        /** @var Relation<Model, Model, mixed> $relationQuery */
        $relationQuery = $record->{$relationMethod}();
        $query = $relationQuery->getQuery();
        $relatedModel = $relationQuery->getRelated();
        $relatedResourceClass = $this->registry->resolveForModel($relatedModel::class);
        $query->select($relatedModel->qualifyColumn('*'));
        $normalizedSearch = trim($search);

        if ($normalizedSearch !== '') {
            if ($relatedResourceClass !== null) {
                $this->applySearch($query, $relatedResourceClass, $normalizedSearch);
            } else {
                $this->applyGenericSearch($query, $relatedModel, $normalizedSearch);
            }
        }

        if ($relatedResourceClass !== null) {
            $this->applyDefaultOrdering($query, $relatedResourceClass);
        } else {
            $this->applyDefaultOrderingForModel($query, $relatedModel);
        }

        $records = $query->paginate(
            perPage: ApiPagination::normalizePerPage($perPage, default: 15, max: 100),
            page: max($page, 1),
        );

        return [
            'data' => array_values(array_map(
                fn (Model $relatedRecord): array => $this->serializeRelatedRecord($relatedResourceClass, $relatedRecord),
                $records->items(),
            )),
            'meta' => [
                'resource' => $resourceMeta,
                'parent_record' => $this->registry->serializeRecordDetail($resourceClass, $record),
                'relation' => [
                    'name' => $relationName,
                    'method' => $relationMethod,
                    'related_model_class' => $relatedModel::class,
                    'related_resource' => $relatedResourceClass !== null ? $this->registry->metadata($relatedResourceClass) : null,
                ],
                'search' => $normalizedSearch !== '' ? $normalizedSearch : null,
                'pagination' => ApiPagination::paginationMeta($records),
            ],
        ];
    }

    /**
     * @return array{data: array{resource: array<string, mixed>, record: array<string, mixed>}}
     */
    public function showRecord(string $resourceKey, string $recordKey): array
    {
        $resourceClass = $this->resolveAccessibleResource($resourceKey);
        $record = $this->registry->resolveRecord($resourceClass, $recordKey);

        return [
            'data' => [
                'resource' => $this->registry->metadata($resourceClass),
                'record' => $this->registry->serializeRecordDetail($resourceClass, $record),
            ],
        ];
    }

    /**
     * @return array{data: array{resource: array<string, mixed>, schema: array<string, mixed>}}
     */
    public function writeSchema(string $resourceKey, string $recordKey, ?User $actor = null): array
    {
        $resourceClass = $this->resolveWritableResource($resourceKey, $actor);

        abort_unless($actor instanceof User, 403);

        $record = $this->registry->resolveRecord($resourceClass, trim($recordKey));
        abort_unless($actor->can('update', $record), 403);

        return [
            'data' => [
                'resource' => $this->registry->metadata($resourceClass),
                'schema' => $this->mutationService->schema($resourceClass, $resourceKey, $record),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateRecord(string $resourceKey, string $recordKey, array $payload, ?User $actor = null, bool $validateOnly = false): array
    {
        $resourceClass = $this->resolveWritableResource($resourceKey, $actor);

        abort_unless($actor instanceof User, 403);

        $record = $this->registry->resolveRecord($resourceClass, $recordKey);
        abort_unless($actor->can('update', $record), 403);

        $validated = Validator::make(
            $payload,
            $this->mutationService->rules($resourceClass),
        )->validate();

        if (is_array($payload['address'] ?? null) && ! array_key_exists('address', $validated)) {
            $validated['address'] = $payload['address'];
        }

        $validated = $this->mutationService->normalizeValidatedPayload($resourceClass, $validated, $record);

        if ($validateOnly) {
            return $this->previewWriteRecord($resourceClass, $validated, $record);
        }

        $record = $this->mutationService->update($resourceClass, $record, $validated, $actor);

        return [
            'data' => [
                'resource' => $this->registry->metadata($resourceClass),
                'record' => $this->registry->serializeRecordDetail($resourceClass, $record),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{data: array{resource: array<string, mixed>, preview: array<string, mixed>}}
     */
    private function previewWriteRecord(string $resourceClass, array $validated, Model $record): array
    {
        $preview = $this->mutationService->previewNormalizedPayload($validated);

        return [
            'data' => [
                'resource' => $this->registry->metadata($resourceClass),
                'preview' => array_merge(
                    [
                        'validate_only' => true,
                        'operation' => 'update',
                        'current_record' => $this->registry->serializeRecordDetail($resourceClass, $record),
                    ],
                    $preview,
                ),
            ],
        ];
    }

    public function hasAnyWritableResourceAccess(?User $user = null): bool
    {
        if (! $user instanceof User || ! $user->hasMemberMcpAccess()) {
            return false;
        }

        return array_any($this->registry->resources(), fn ($resourceClass) => $this->mutationService->supports($resourceClass) && $this->mutationService->canWriteResource($resourceClass, $user));
    }

    /**
     * @param  Builder<Model>  $query
     * @param  class-string<resource>  $resourceClass
     */
    public function applyDefaultOrdering(Builder $query, string $resourceClass): void
    {
        $model = $query->getModel();
        $table = $model->getTable();
        $recordTitleAttribute = $resourceClass::getRecordTitleAttribute();

        if (is_string($recordTitleAttribute) && $recordTitleAttribute !== '' && Schema::hasColumn($table, $recordTitleAttribute)) {
            $sortColumn = $recordTitleAttribute;
        } elseif (Schema::hasColumn($table, 'created_at')) {
            $sortColumn = 'created_at';
        } else {
            $sortColumn = $model->getKeyName();
        }

        $query->orderBy($model->qualifyColumn($sortColumn), $sortColumn === 'created_at' ? 'desc' : 'asc');
    }

    /**
     * @return array{
     *   id: string,
     *   route_key: string,
     *   title: string|null,
     *   attributes: array<string, mixed>,
     *   abilities?: array<string, bool>,
     *   panel_routes?: array<string, string|null>
     * }
     */
    private function serializeRelatedRecord(?string $resourceClass, Model $record): array
    {
        if ($resourceClass !== null) {
            return $this->registry->serializeRecord($resourceClass, $record);
        }

        return [
            'id' => (string) $record->getKey(),
            'route_key' => (string) $record->getRouteKey(),
            'title' => $this->genericRecordTitle($record),
            'attributes' => $this->serializeGenericAttributes($record),
        ];
    }

    private function genericRecordTitle(Model $record): string
    {
        $table = $record->getTable();
        $candidates = [
            'title',
            'name',
            'label',
            'slug',
            'email',
            $record->getRouteKeyName(),
        ];

        foreach (array_values(array_unique(array_filter($candidates, static fn (string $column): bool => $column !== ''))) as $column) {
            if (! Schema::hasColumn($table, $column)) {
                continue;
            }

            $value = $record->getAttribute($column);

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }

            if (is_scalar($value) && trim((string) $value) !== '') {
                return (string) $value;
            }
        }

        return (string) $record->getRouteKey();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeGenericAttributes(Model $record): array
    {
        $attributes = $record->toArray();

        if ($record instanceof User) {
            return Arr::except($attributes, [
                'email',
                'email_verified_at',
                'phone',
                'phone_verified_at',
                'daily_prayer_institution_id',
                'friday_prayer_institution_id',
            ]);
        }

        return $attributes;
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function applyDefaultOrderingForModel(Builder $query, Model $model): void
    {
        $table = $model->getTable();
        $candidates = [
            'name',
            'title',
            'label',
            'slug',
        ];

        foreach ($candidates as $candidate) {
            if (! Schema::hasColumn($table, $candidate)) {
                continue;
            }

            $query->orderBy($model->qualifyColumn($candidate));

            return;
        }

        if (Schema::hasColumn($table, 'created_at')) {
            $query->orderBy($model->qualifyColumn('created_at'), 'desc');

            return;
        }

        $query->orderBy($model->qualifyColumn($model->getKeyName()));
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function applyGenericSearch(Builder $query, Model $model, string $search): void
    {
        $table = $model->getTable();
        $candidates = [
            'title',
            'name',
            'label',
            'slug',
            'email',
            'description',
            $model->getRouteKeyName(),
        ];
        $columns = array_values(array_filter(array_unique($candidates), static fn (string $column): bool => Schema::hasColumn($table, $column)));

        if ($columns === []) {
            return;
        }

        $query->where(function (Builder $builder) use ($columns, $search, $model): void {
            foreach ($columns as $index => $column) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $builder->{$method}($model->qualifyColumn($column), 'like', "%{$search}%");
            }
        });
    }

    /**
     * @param  Builder<Model>  $query
     * @param  class-string<resource>  $resourceClass
     */
    protected function applySearch(Builder $query, string $resourceClass, string $search): void
    {
        if ($this->resourceSearchDispatcher->apply($query, $search)) {
            return;
        }

        $columns = $this->registry->searchableColumns($resourceClass);

        if ($columns === []) {
            return;
        }

        $model = $query->getModel();

        $query->where(function (Builder $builder) use ($columns, $search, $model): void {
            foreach ($columns as $index => $column) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $builder->{$method}($model->qualifyColumn($column), 'like', "%{$search}%");
            }
        });
    }

    /**
     * @param  Builder<Model>  $query
     * @param  class-string<resource>  $resourceClass
     */
    protected function applyDateFilters(
        Builder $query,
        string $resourceClass,
        ?string $startsAfter,
        ?string $startsBefore,
        ?string $startsOnLocalDate,
    ): void {
        $resource = $this->registry->metadata($resourceClass);

        if (! is_array($resource['date_semantics'] ?? null)) {
            return;
        }

        $model = $query->getModel();
        $startsAtColumn = $model->qualifyColumn('starts_at');

        if (is_string($startsAfter) && trim($startsAfter) !== '') {
            $parsedStartsAfter = UserDateTimeFormatter::parseUserDateToUtc($startsAfter, false);

            if ($parsedStartsAfter instanceof CarbonInterface) {
                $query->where($startsAtColumn, '>=', $parsedStartsAfter);
            }
        }

        if (is_string($startsBefore) && trim($startsBefore) !== '') {
            $parsedStartsBefore = UserDateTimeFormatter::parseUserDateToUtc($startsBefore, true);

            if ($parsedStartsBefore instanceof CarbonInterface) {
                $query->where($startsAtColumn, '<=', $parsedStartsBefore);
            }
        }

        if (is_string($startsOnLocalDate) && trim($startsOnLocalDate) !== '') {
            $startsOnLocalDateStart = UserDateTimeFormatter::parseUserDateToUtc($startsOnLocalDate, false);
            $startsOnLocalDateEnd = UserDateTimeFormatter::parseUserDateToUtc($startsOnLocalDate, true);

            if ($startsOnLocalDateStart instanceof CarbonInterface && $startsOnLocalDateEnd instanceof CarbonInterface) {
                $query->whereBetween($startsAtColumn, [$startsOnLocalDateStart, $startsOnLocalDateEnd]);
            }
        }
    }

    /**
     * @param  class-string<resource>  $resourceClass
     * @param  LengthAwarePaginator<int, Model>  $records
     * @return array<string, mixed>
     */
    protected function recordsMeta(string $resourceClass, LengthAwarePaginator $records, string $search): array
    {
        return [
            'resource' => $this->registry->metadata($resourceClass),
            'search' => $search !== '' ? $search : null,
            'pagination' => ApiPagination::paginationMeta($records),
        ];
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
            'write_support' => $resource['write_support'],
            'panel_routes' => [
                'index' => $resource['panel_routes']['index'],
                'view_template' => $resource['panel_routes']['view_template'],
                'edit_template' => $resource['panel_routes']['edit_template'],
            ],
            'mcp_tools' => $resource['mcp_tools'],
            'timezone_sensitive' => $resource['timezone_sensitive'],
            'date_semantics' => $resource['date_semantics'],
        ];
    }

    /**
     * @return class-string<resource>
     */
    protected function resolveAccessibleResource(string $resourceKey): string
    {
        $resourceClass = $this->registry->resolve($resourceKey);

        if (! is_string($resourceClass)) {
            throw new NotFoundHttpException;
        }

        abort_unless($this->registry->canAccessResource($resourceClass), 403);

        return $resourceClass;
    }

    /**
     * @return class-string<resource>
     */
    protected function resolveWritableResource(string $resourceKey, ?User $actor = null): string
    {
        $resourceClass = $this->resolveAccessibleResource($resourceKey);

        if (! $this->mutationService->supports($resourceClass)) {
            throw new NotFoundHttpException;
        }

        abort_unless($actor instanceof User && $this->mutationService->canWriteResource($resourceClass, $actor), 403);

        return $resourceClass;
    }
}
