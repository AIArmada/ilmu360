<?php

declare(strict_types=1);

namespace App\Platform\Api\Admin;

use App\Models\User;
use App\Platform\Api\Admin\Concerns\ResolvesAccessibleResource;
use App\Platform\Api\Admin\Contracts\RecordActionContributor;
use Illuminate\Database\Eloquent\Model;

final readonly class AdminRecordActionService
{
    use ResolvesAccessibleResource;

    public function __construct(
        private AdminResourceRegistry $registry,
        private RecordActionContributorRegistry $contributors,
    ) {}

    /**
     * @return array{
     *   data: array{
     *     resource: array<string, mixed>,
     *     record: array<string, mixed>,
     *     focus_actions: array{
     *       recommended_keys: list<string>,
     *       actions: list<array<string, mixed>>,
     *       notes: list<string>
     *     }
     *   }
     * }
     */
    public function describe(string $resourceKey, string $recordKey, ?User $actor = null): array
    {
        $resourceClass = $this->resolveAccessibleResource($resourceKey);
        $record = $this->registry->resolveRecord($resourceClass, $recordKey);
        $abilities = $this->registry->recordAbilities($resourceClass, $record);

        abort_unless(in_array(true, $abilities, true), 403);

        $resource = $this->registry->metadata($resourceClass);
        $recordDetail = $this->registry->serializeRecordDetail($resourceClass, $record);
        $resolvedRecordKey = is_string($recordDetail['route_key'] ?? null)
            ? $recordDetail['route_key']
            : (string) $record->getRouteKey();

        $actions = [
            ...$this->baseActions($resource, $resolvedRecordKey, $abilities),
            ...$this->contributedActions((string) ($resource['key'] ?? ''), $record, $actor),
        ];

        return [
            'data' => [
                'resource' => $resource,
                'record' => $recordDetail,
                'focus_actions' => [
                    'recommended_keys' => $this->recommendedActionKeys($actions),
                    'actions' => $actions,
                    'notes' => $this->notes($actions),
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $resource
     * @param  array<string, bool>  $abilities
     * @return list<array<string, mixed>>
     */
    private function baseActions(array $resource, string $recordKey, array $abilities): array
    {
        $resourceKey = (string) ($resource['key'] ?? '');
        $relations = array_values(array_filter(
            is_array($resource['relations'] ?? null) ? $resource['relations'] : [],
            static fn (mixed $relation): bool => is_string($relation) && $relation !== '',
        ));
        $canUpdate = (bool) data_get($resource, 'write_support.update', false)
            && (bool) ($abilities['edit'] ?? false);

        $actions = [
            [
                'key' => 'get_record',
                'label' => 'Refresh record detail',
                'category' => 'read',
                'description' => 'Reload this record detail before choosing the next MCP tool call.',
                'tool' => 'admin-get-record',
                'arguments' => [
                    'resource_key' => $resourceKey,
                    'record_key' => $recordKey,
                ],
            ],
        ];

        if ($relations !== []) {
            $actions[] = [
                'key' => 'list_related_records',
                'label' => 'List related records',
                'category' => 'relation',
                'description' => 'Traverse one exposed relation on this record by setting the relation argument to one of the available relation names.',
                'tool' => 'admin-list-related-records',
                'arguments' => [
                    'resource_key' => $resourceKey,
                    'record_key' => $recordKey,
                    'relation' => 'relation',
                    'search' => null,
                    'page' => 1,
                    'per_page' => 15,
                ],
                'available_relations' => $relations,
            ];
        }

        if ($canUpdate) {
            $actions[] = [
                'key' => 'get_update_schema',
                'label' => 'Fetch update schema',
                'category' => 'write',
                'description' => 'Fetch the exact update schema before previewing or persisting an admin update.',
                'tool' => 'admin-get-write-schema',
                'arguments' => [
                    'resource_key' => $resourceKey,
                    'operation' => 'update',
                    'record_key' => $recordKey,
                ],
            ];
            $actions[] = [
                'key' => 'update_record',
                'label' => 'Preview or update record',
                'category' => 'write',
                'description' => 'Preview a schema-guided update with validate_only=true, then set it to false when you are ready to persist the change.',
                'tool' => 'admin-update-record',
                'arguments' => [
                    'resource_key' => $resourceKey,
                    'record_key' => $recordKey,
                    'payload' => 'object',
                    'validate_only' => true,
                    'apply_defaults' => false,
                ],
                'requires' => ['get_update_schema'],
            ];
        }

        return $actions;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function contributedActions(string $resourceKey, Model $record, ?User $actor = null): array
    {
        $actions = [];

        foreach ($this->contributors->all() as $contributor) {
            if (! $contributor->supports(RecordActionContributor::SURFACE_ADMIN, $resourceKey, $record)) {
                continue;
            }

            $actions = [
                ...$actions,
                ...$contributor->actions(RecordActionContributor::SURFACE_ADMIN, $resourceKey, $record, $actor),
            ];
        }

        return $actions;
    }

    /**
     * @param  list<array<string, mixed>>  $actions
     * @return list<string>
     */
    private function recommendedActionKeys(array $actions): array
    {
        $recommended = [];

        foreach (['get_event_moderation_schema', 'moderate_event', 'generate_event_cover_image', 'generate_event_poster_image', 'get_report_triage_schema', 'triage_report', 'get_contribution_request_review_schema', 'review_contribution_request', 'get_membership_application_review_schema', 'review_membership_application', 'get_update_schema', 'list_related_records'] as $actionKey) {
            if ($this->hasAction($actions, $actionKey)) {
                $recommended[] = $actionKey;
            }
        }

        if ($recommended === []) {
            $recommended[] = 'get_record';
        }

        return array_values(array_unique($recommended));
    }

    /**
     * @param  list<array<string, mixed>>  $actions
     * @return list<string>
     */
    private function notes(array $actions): array
    {
        $notes = [
            'Use the record route_key returned here when chaining record-specific MCP calls.',
        ];

        if ($this->hasAction($actions, 'get_update_schema')) {
            $notes[] = 'Fetch the exact update schema before previewing or persisting an admin update.';
        }

        if ($this->hasAction($actions, 'list_related_records')) {
            $notes[] = 'For relation traversal, set relation to one of the available relation names exposed on this resource.';
        }

        if ($this->hasAction($actions, 'generate_event_cover_image') || $this->hasAction($actions, 'generate_event_poster_image')) {
            $notes[] = 'For event image generation, use the cover tool for 16:9 website/app covers and the poster tool for 3:4 external distribution posters.';
        }

        if ($this->hasWorkflowSchemaAction($actions)) {
            $notes[] = 'Use the explicit workflow schema tools to confirm defaults, allowed actions, and conditional rules before calling the matching workflow mutation tool.';
        }

        if ($this->hasWorkflowAction($actions)) {
            $notes[] = 'Prefer explicit workflow tools over generic update when this record is in a moderation, triage, or review flow.';
        }

        return $notes;
    }

    /**
     * @param  list<array<string, mixed>>  $actions
     */
    private function hasAction(array $actions, string $key): bool
    {
        return array_any($actions, fn ($action) => ($action['key'] ?? null) === $key);
    }

    /**
     * @param  list<array<string, mixed>>  $actions
     */
    private function hasWorkflowAction(array $actions): bool
    {
        return array_any($actions, fn ($action) => ($action['category'] ?? null) === 'workflow');
    }

    /**
     * @param  list<array<string, mixed>>  $actions
     */
    private function hasWorkflowSchemaAction(array $actions): bool
    {
        return array_any($actions, fn ($action) => ($action['category'] ?? null) === 'workflow_schema');
    }
}
