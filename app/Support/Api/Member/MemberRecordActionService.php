<?php

declare(strict_types=1);

namespace App\Support\Api\Member;

use Filament\Resources\Resource;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class MemberRecordActionService
{
    public function __construct(
        private MemberResourceRegistry $registry,
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
    public function describe(string $resourceKey, string $recordKey): array
    {
        $resourceClass = $this->resolveAccessibleResource($resourceKey);
        $record = $this->registry->resolveRecord($resourceClass, $recordKey);
        $abilities = $this->registry->recordAbilities($record);

        abort_unless(in_array(true, $abilities, true), 403);

        $resource = $this->registry->metadata($resourceClass);
        $recordDetail = $this->registry->serializeRecordDetail($resourceClass, $record);
        $resolvedRecordKey = is_string($recordDetail['route_key'] ?? null)
            ? $recordDetail['route_key']
            : (string) $record->getRouteKey();
        $actions = $this->actions($resource, $resolvedRecordKey, $abilities);

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
    private function actions(array $resource, string $recordKey, array $abilities): array
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
                'description' => 'Reload this member-scoped record detail before making the next MCP call.',
                'tool' => 'member-get-record',
                'arguments' => [
                    'resource_key' => $resourceKey,
                    'record_key' => $recordKey,
                ],
            ],
        ];

        if ($resourceKey === 'events') {
            $actions[] = [
                'key' => 'generate_event_cover_image',
                'label' => 'Upload event cover image',
                'category' => 'creative_asset',
                'description' => 'Use the member-event-cover-image-prompt prompt to get the engineered prompt and reference images, generate with ChatGPT image generation, then upload the 16:9 result here.',
                'tool' => 'member-upload-event-cover-image',
                'arguments' => [
                    'event_key' => $recordKey,
                ],
                'prerequisite_prompt' => 'member-event-cover-image-prompt',
            ];
            $actions[] = [
                'key' => 'generate_event_poster_image',
                'label' => 'Upload event poster image',
                'category' => 'creative_asset',
                'description' => 'Use the member-event-poster-image-prompt prompt to get the engineered prompt and reference images, generate with ChatGPT image generation, then upload the 4:5 result here.',
                'tool' => 'member-upload-event-poster-image',
                'arguments' => [
                    'event_key' => $recordKey,
                ],
                'prerequisite_prompt' => 'member-event-poster-image-prompt',
            ];
        }

        if ($relations !== []) {
            $actions[] = [
                'key' => 'list_related_records',
                'label' => 'List related records',
                'category' => 'relation',
                'description' => 'Traverse one exposed relation on this member record by setting the relation argument to one of the available relation names.',
                'tool' => 'member-list-related-records',
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
                'description' => 'Fetch the exact member update schema before previewing or persisting an Ahli-scoped update.',
                'tool' => 'member-get-write-schema',
                'arguments' => [
                    'resource_key' => $resourceKey,
                    'record_key' => $recordKey,
                ],
            ];
            $actions[] = [
                'key' => 'update_record',
                'label' => 'Preview or update record',
                'category' => 'write',
                'description' => 'Preview a schema-guided member update with validate_only=true, then set it to false when you are ready to persist the change.',
                'tool' => 'member-update-record',
                'arguments' => [
                    'resource_key' => $resourceKey,
                    'record_key' => $recordKey,
                    'payload' => 'object',
                    'validate_only' => true,
                ],
                'requires' => ['get_update_schema'],
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

        foreach (['generate_event_cover_image', 'generate_event_poster_image', 'get_update_schema', 'list_related_records'] as $actionKey) {
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
            'Use the record route_key returned here when chaining member-scoped MCP calls.',
            'Contribution-request queues and membership claims remain dedicated member workflow tools rather than record-specific actions on this surface.',
        ];

        if ($this->hasAction($actions, 'get_update_schema')) {
            $notes[] = 'Fetch the exact update schema before previewing or persisting a member update.';
        }

        if ($this->hasAction($actions, 'list_related_records')) {
            $notes[] = 'For relation traversal, set relation to one of the available relation names exposed on this resource.';
        }

        if ($this->hasAction($actions, 'generate_event_cover_image') || $this->hasAction($actions, 'generate_event_poster_image')) {
            $notes[] = 'For event image generation, use the cover tool for 16:9 website/app covers and the poster tool for 4:5 external distribution posters.';
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
