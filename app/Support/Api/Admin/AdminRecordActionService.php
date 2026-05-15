<?php

declare(strict_types=1);

namespace App\Support\Api\Admin;

use App\Filament\Resources\ContributionRequests\ContributionRequestResource;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\MembershipClaims\MembershipClaimResource;
use App\Filament\Resources\Reports\ReportResource;
use App\Models\ContributionRequest;
use App\Models\Event;
use App\Models\MembershipClaim;
use App\Models\Report;
use App\Models\User;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class AdminRecordActionService
{
    public function __construct(
        private AdminResourceRegistry $registry,
        private AdminEventModerationService $eventModerationService,
        private AdminReportTriageService $reportTriageService,
        private AdminContributionRequestReviewService $contributionRequestReviewService,
        private AdminMembershipClaimReviewService $membershipClaimReviewService,
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
            ...$this->workflowActions($resourceClass, $record, $resolvedRecordKey, $actor),
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
    private function workflowActions(string $resourceClass, Model $record, string $recordKey, ?User $actor = null): array
    {
        $actions = [];

        if ($resourceClass === EventResource::class && $record instanceof Event) {
            $actions = [...$actions, ...$this->eventImageGenerationActions($recordKey)];
            $actions = [...$actions, ...$this->eventWorkflowActions($recordKey, $actor)];
        }

        if ($resourceClass === ReportResource::class && $record instanceof Report) {
            $actions = [...$actions, ...$this->reportWorkflowActions($recordKey, $actor)];
        }

        if ($resourceClass === ContributionRequestResource::class && $record instanceof ContributionRequest) {
            $actions = [...$actions, ...$this->contributionRequestWorkflowActions($record, $recordKey, $actor)];
        }

        if ($resourceClass === MembershipClaimResource::class && $record instanceof MembershipClaim) {
            $actions = [...$actions, ...$this->membershipClaimWorkflowActions($record, $recordKey, $actor)];
        }

        return $actions;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function eventImageGenerationActions(string $recordKey): array
    {
        return [
            [
                'key' => 'generate_event_cover_image',
                'label' => 'Upload event cover image',
                'category' => 'creative_asset',
                'description' => 'Use the admin-event-cover-image-prompt prompt to get the engineered prompt and reference images, generate with ChatGPT image generation, then upload the 16:9 result here.',
                'tool' => 'admin-upload-event-cover-image',
                'arguments' => [
                    'event_key' => $recordKey,
                ],
                'prerequisite_prompt' => 'admin-event-cover-image-prompt',
            ],
            [
                'key' => 'generate_event_poster_image',
                'label' => 'Upload event poster image',
                'category' => 'creative_asset',
                'description' => 'Use the admin-event-poster-image-prompt prompt to get the engineered prompt and reference images, generate with ChatGPT image generation, then upload the 4:5 result here.',
                'tool' => 'admin-upload-event-poster-image',
                'arguments' => [
                    'event_key' => $recordKey,
                ],
                'prerequisite_prompt' => 'admin-event-poster-image-prompt',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function eventWorkflowActions(string $recordKey, ?User $actor = null): array
    {
        if (! $this->eventModerationService->canModerate($actor)) {
            return [];
        }

        $schema = $this->trySchema(fn (): array => $this->eventModerationService->schema($recordKey, $actor));

        if (! is_array($schema)) {
            return [];
        }

        return [
            $this->workflowSchemaActionDescriptor(
                key: 'get_event_moderation_schema',
                label: 'Fetch event moderation schema',
                description: 'Read the explicit moderation workflow schema for this event before choosing the action.',
                tool: 'admin-get-event-moderation-schema',
                recordKey: $recordKey,
                schema: $schema,
            ),
            $this->workflowActionDescriptor(
                key: 'moderate_event',
                label: 'Moderate event',
                description: 'Run one explicit moderation action that is currently valid for this event.',
                tool: 'admin-moderate-event',
                recordKey: $recordKey,
                schemaTool: 'admin-get-event-moderation-schema',
                schemaActionKey: 'get_event_moderation_schema',
                schema: $schema,
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reportWorkflowActions(string $recordKey, ?User $actor = null): array
    {
        if (! $this->reportTriageService->canTriage($actor)) {
            return [];
        }

        $schema = $this->trySchema(fn (): array => $this->reportTriageService->schema($recordKey, $actor));

        if (! is_array($schema)) {
            return [];
        }

        return [
            $this->workflowSchemaActionDescriptor(
                key: 'get_report_triage_schema',
                label: 'Fetch report triage schema',
                description: 'Read the explicit triage workflow schema for this report before choosing the action.',
                tool: 'admin-get-report-triage-schema',
                recordKey: $recordKey,
                schema: $schema,
            ),
            $this->workflowActionDescriptor(
                key: 'triage_report',
                label: 'Triage report',
                description: 'Run one explicit triage action that is currently valid for this report.',
                tool: 'admin-triage-report',
                recordKey: $recordKey,
                schemaTool: 'admin-get-report-triage-schema',
                schemaActionKey: 'get_report_triage_schema',
                schema: $schema,
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function contributionRequestWorkflowActions(ContributionRequest $record, string $recordKey, ?User $actor = null): array
    {
        if (! $record->isPending() || ! $this->contributionRequestReviewService->canReview($actor)) {
            return [];
        }

        $schema = $this->trySchema(fn (): array => $this->contributionRequestReviewService->schema($recordKey, $actor));

        if (! is_array($schema)) {
            return [];
        }

        return [
            $this->workflowSchemaActionDescriptor(
                key: 'get_contribution_request_review_schema',
                label: 'Fetch contribution request review schema',
                description: 'Read the explicit review schema for this contribution request before choosing the action.',
                tool: 'admin-get-contribution-request-review-schema',
                recordKey: $recordKey,
                schema: $schema,
            ),
            $this->workflowActionDescriptor(
                key: 'review_contribution_request',
                label: 'Review contribution request',
                description: 'Approve or reject this pending contribution request through the explicit review workflow.',
                tool: 'admin-review-contribution-request',
                recordKey: $recordKey,
                schemaTool: 'admin-get-contribution-request-review-schema',
                schemaActionKey: 'get_contribution_request_review_schema',
                schema: $schema,
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function membershipClaimWorkflowActions(MembershipClaim $record, string $recordKey, ?User $actor = null): array
    {
        if (! $record->isPending() || ! $this->membershipClaimReviewService->canReview($actor)) {
            return [];
        }

        $schema = $this->trySchema(fn (): array => $this->membershipClaimReviewService->schema($recordKey, $actor));

        if (! is_array($schema)) {
            return [];
        }

        return [
            $this->workflowSchemaActionDescriptor(
                key: 'get_membership_claim_review_schema',
                label: 'Fetch membership claim review schema',
                description: 'Read the explicit review schema for this membership claim before choosing the action.',
                tool: 'admin-get-membership-claim-review-schema',
                recordKey: $recordKey,
                schema: $schema,
            ),
            $this->workflowActionDescriptor(
                key: 'review_membership_claim',
                label: 'Review membership claim',
                description: 'Approve or reject this pending membership claim through the explicit review workflow.',
                tool: 'admin-review-membership-claim',
                recordKey: $recordKey,
                schemaTool: 'admin-get-membership-claim-review-schema',
                schemaActionKey: 'get_membership_claim_review_schema',
                schema: $schema,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function workflowSchemaActionDescriptor(string $key, string $label, string $description, string $tool, string $recordKey, array $schema): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'category' => 'workflow_schema',
            'description' => $description,
            'tool' => $tool,
            'arguments' => [
                'record_key' => $recordKey,
            ],
            'schema' => $this->normalizedSchemaPayload($schema),
        ];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>|null
     */
    private function workflowActionDescriptor(string $key, string $label, string $description, string $tool, string $recordKey, string $schemaTool, string $schemaActionKey, array $schema): ?array
    {
        $normalizedSchema = $this->normalizedSchemaPayload($schema);
        $defaults = $normalizedSchema['defaults'];
        $availableActions = $normalizedSchema['available_actions'];

        if ($availableActions === []) {
            return null;
        }

        return [
            'key' => $key,
            'label' => $label,
            'category' => 'workflow',
            'description' => $description,
            'tool' => $tool,
            'arguments' => array_merge(['record_key' => $recordKey], $defaults),
            'requires' => [$schemaActionKey],
            'schema_tool' => $schemaTool,
            'schema_tool_arguments' => [
                'record_key' => $recordKey,
            ],
            'schema_action_key' => $schemaActionKey,
            'schema' => [
                ...$normalizedSchema,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array{defaults: array<string, mixed>, fields: list<array<string, mixed>>, conditional_rules: list<array<string, mixed>>, available_actions: list<array<string, mixed>>}
     */
    private function normalizedSchemaPayload(array $schema): array
    {
        return [
            'defaults' => is_array($schema['defaults'] ?? null) ? $schema['defaults'] : [],
            'fields' => array_values(array_filter(
                is_array($schema['fields'] ?? null) ? $schema['fields'] : [],
                is_array(...),
            )),
            'conditional_rules' => array_values(array_filter(
                is_array($schema['conditional_rules'] ?? null) ? $schema['conditional_rules'] : [],
                is_array(...),
            )),
            'available_actions' => $this->extractAvailableActions($schema),
        ];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return list<array<string, mixed>>
     */
    private function extractAvailableActions(array $schema): array
    {
        $availableActions = array_values(array_filter(
            is_array($schema['available_actions'] ?? null) ? $schema['available_actions'] : [],
            is_array(...),
        ));

        if ($availableActions !== []) {
            return $availableActions;
        }

        $fields = is_array($schema['fields'] ?? null) ? $schema['fields'] : [];

        foreach ($fields as $field) {
            if (! is_array($field) || ($field['name'] ?? null) !== 'action') {
                continue;
            }

            $allowedValues = array_values(array_filter(
                is_array($field['allowed_values'] ?? null) ? $field['allowed_values'] : [],
                static fn (mixed $value): bool => is_string($value) && $value !== '',
            ));

            return array_map(
                static fn (string $value): array => [
                    'key' => $value,
                    'label' => Str::headline($value),
                ],
                $allowedValues,
            );
        }

        return [];
    }

    /**
     * @param  list<array<string, mixed>>  $actions
     * @return list<string>
     */
    private function recommendedActionKeys(array $actions): array
    {
        $recommended = [];

        foreach (['get_event_moderation_schema', 'moderate_event', 'generate_event_cover_image', 'generate_event_poster_image', 'get_report_triage_schema', 'triage_report', 'get_contribution_request_review_schema', 'review_contribution_request', 'get_membership_claim_review_schema', 'review_membership_claim', 'get_update_schema', 'list_related_records'] as $actionKey) {
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
            $notes[] = 'For event image generation, use the cover tool for 16:9 website/app covers and the poster tool for 4:5 external distribution posters.';
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

    /**
     * @return array<string, mixed>|null
     */
    private function trySchema(callable $resolver): ?array
    {
        try {
            $response = $resolver();
        } catch (HttpExceptionInterface) {
            return null;
        }

        $schema = data_get($response, 'data.schema');

        return is_array($schema) ? $schema : null;
    }

    /**
     * @return class-string<resource>
     */
    private function resolveAccessibleResource(string $resourceKey): string
    {
        $resourceClass = $this->registry->resolve($resourceKey);

        if (! is_string($resourceClass)) {
            throw new NotFoundHttpException;
        }

        abort_unless($this->registry->canAccessResource($resourceClass), 403);

        return $resourceClass;
    }
}
