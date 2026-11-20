<?php

declare(strict_types=1);

namespace App\Platform\Api\Member;

use App\Models\User;
use App\Platform\Api\Resources\ResourceActor;
use App\Platform\Api\Resources\ResourceActorResolver;
use App\Platform\Api\Resources\ResourceQuery;
use App\Platform\Api\Resources\ResourceReadSupport;
use App\Platform\Api\Resources\ResourceSurface;
use App\Platform\ApiDocumentation\ApiDocumentationUrlResolver;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class MemberResourceService
{
    public function __construct(
        private readonly MemberResourceRegistry $registry,
        private readonly MemberResourceMutationService $mutationService,
        private readonly ApiDocumentationUrlResolver $urlResolver,
        private readonly MemberWorkflowToolRegistry $workflowTools,
        private readonly ResourceActorResolver $actors,
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
                'workflow_tools' => $this->workflowTools->tools(),
                'rules' => [
                    'Use the returned resource keys to scope follow-up MCP reads to your own memberships and related records.',
                    'Resource visibility follows the Ahli workspace boundary and live membership relationships, not the full admin panel.',
                    'Member MCP writes are limited to schema-guided updates for records the current user can already edit through the Ahli surface.',
                    'Fetch the exact update schema before every member write because required fields remain resource-specific.',
                    'Use validate_only=true to preview and normalize a member write without persisting it.',
                    'Use the relation keys returned by member resource metadata when traversing one-level related records.',
                    'Use workflow_tools for Ahli queue flows such as contribution approvals and membership applications; resources cover scoped CRUD only.',
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
        $definition = $this->resolveAccessibleDefinition($resourceKey);

        return [
            'data' => [
                'resource' => $definition->metadata($this->actor()),
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
        return $this->resolveAccessibleDefinition($resourceKey)->list(
            $this->actor(),
            new ResourceQuery(
                search: $search,
                page: $page,
                perPage: $perPage,
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
        return $this->resolveAccessibleDefinition($resourceKey)->related(
            $this->actor(),
            $recordKey,
            $relation,
            new ResourceQuery(search: $search, page: $page, perPage: $perPage),
        );
    }

    /**
     * @return array{data: array{resource: array<string, mixed>, record: array<string, mixed>}}
     */
    public function showRecord(string $resourceKey, string $recordKey): array
    {
        return $this->resolveAccessibleDefinition($resourceKey)->show($this->actor(), $recordKey);
    }

    /**
     * @return array{data: array{resource: array<string, mixed>, schema: array<string, mixed>}}
     */
    public function writeSchema(string $resourceKey, string $recordKey, ?User $actor = null): array
    {
        $definition = $this->resolveWritableDefinition($resourceKey, $actor);
        $resourceActor = $this->actorFor($actor);

        $schema = $definition->schema($resourceActor, 'update', trim($recordKey));

        return [
            'data' => [
                'resource' => $definition->metadata($this->actor()),
                'schema' => $schema,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateRecord(string $resourceKey, string $recordKey, array $payload, ?User $actor = null, bool $validateOnly = false): array
    {
        $definition = $this->resolveWritableDefinition($resourceKey, $actor);
        $resourceActor = $this->actorFor($actor);

        if ($validateOnly) {
            return [
                'data' => [
                    'resource' => $definition->metadata($this->actor()),
                    'preview' => $definition->validateAndPreview($resourceActor, 'update', $recordKey, $payload),
                ],
            ];
        }

        $result = $definition->write($resourceActor, 'update', $recordKey, $payload);

        return [
            'data' => [
                'resource' => $definition->metadata($this->actor()),
                'record' => $result['record'],
            ],
        ];
    }

    public function hasAnyWritableResourceAccess(?User $user = null): bool
    {
        if (! $user instanceof User || ! $user->hasMemberMcpAccess()) {
            return false;
        }

        return array_any($this->registry->resources(), fn (string $resourceClass): bool => $this->mutationService->supports($resourceClass) && $this->mutationService->canWriteResource($resourceClass, $user));
    }

    /**
     * @param  Builder<Model>  $query
     * @param  class-string<resource>  $resourceClass
     */
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

    private function resolveAccessibleDefinition(string $resourceKey): AbstractMemberResourceDefinition
    {
        $definition = $this->registry->definitionForKey($resourceKey);

        if (! $definition instanceof AbstractMemberResourceDefinition) {
            throw new NotFoundHttpException;
        }

        abort_unless($definition->canAccess($this->actor()), 403);

        return $definition;
    }

    private function resolveWritableDefinition(string $resourceKey, ?User $actor = null): AbstractMemberResourceDefinition
    {
        $definition = $this->resolveAccessibleDefinition($resourceKey);

        if (! $this->mutationService->supports($definition->resourceClass())) {
            throw new NotFoundHttpException;
        }

        abort_unless($actor instanceof User && $this->mutationService->canWriteResource($definition->resourceClass(), $actor), 403);

        return $definition;
    }

    private function actor(): ResourceActor
    {
        return $this->actors->current(ResourceSurface::Member);
    }

    private function actorFor(?User $actor): ResourceActor
    {
        return $actor instanceof User
            ? $this->actors->fromUser($actor, ResourceSurface::Member)
            : new ResourceActor('', ResourceSurface::Member);
    }
}
