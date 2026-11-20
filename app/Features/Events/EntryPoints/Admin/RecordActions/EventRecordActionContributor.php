<?php

declare(strict_types=1);

namespace App\Features\Events\EntryPoints\Admin\RecordActions;

use App\Features\Events\Api\Orm\AdminEventModeration;
use App\Models\User;
use App\Platform\Api\Admin\Concerns\BuildsWorkflowActionDescriptors;
use App\Platform\Api\Admin\Contracts\RecordActionContributor;
use Illuminate\Database\Eloquent\Model;

/**
 * Contributes event image upload and moderation follow-up actions to the
 * admin and member record action surfaces.
 */
final readonly class EventRecordActionContributor implements RecordActionContributor
{
    use BuildsWorkflowActionDescriptors;

    public function __construct(
        private AdminEventModeration $eventModerationService,
    ) {}

    public function supports(string $surface, string $resourceKey, Model $record): bool
    {
        if ($resourceKey !== 'events') {
            return false;
        }

        if ($surface === RecordActionContributor::SURFACE_MEMBER) {
            return true;
        }

        return $surface === RecordActionContributor::SURFACE_ADMIN
            && $record->getMorphClass() === 'event';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function actions(string $surface, string $resourceKey, Model $record, ?User $actor = null): array
    {
        if (! $this->supports($surface, $resourceKey, $record)) {
            return [];
        }

        $recordKey = (string) $record->getRouteKey();

        if ($surface === RecordActionContributor::SURFACE_MEMBER) {
            return $this->memberImageGenerationActions($recordKey);
        }

        return [
            ...$this->eventImageGenerationActions($recordKey),
            ...$this->eventWorkflowActions($recordKey, $actor),
        ];
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
                'description' => 'Use the admin-event-poster-image-prompt prompt to get the engineered prompt and reference images, generate with ChatGPT image generation, then upload the 3:4 result here.',
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
    private function memberImageGenerationActions(string $recordKey): array
    {
        return [
            [
                'key' => 'generate_event_cover_image',
                'label' => 'Upload event cover image',
                'category' => 'creative_asset',
                'description' => 'Use the member-event-cover-image-prompt prompt to get the engineered prompt and reference images, generate with ChatGPT image generation, then upload the 16:9 result here.',
                'tool' => 'member-upload-event-cover-image',
                'arguments' => [
                    'event_key' => $recordKey,
                ],
                'prerequisite_prompt' => 'member-event-cover-image-prompt',
            ],
            [
                'key' => 'generate_event_poster_image',
                'label' => 'Upload event poster image',
                'category' => 'creative_asset',
                'description' => 'Use the member-event-poster-image-prompt prompt to get the engineered prompt and reference images, generate with ChatGPT image generation, then upload the 3:4 result here.',
                'tool' => 'member-upload-event-poster-image',
                'arguments' => [
                    'event_key' => $recordKey,
                ],
                'prerequisite_prompt' => 'member-event-poster-image-prompt',
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
}
