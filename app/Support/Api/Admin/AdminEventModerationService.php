<?php

declare(strict_types=1);

namespace App\Support\Api\Admin;

use App\Filament\Resources\Events\EventResource;
use App\Models\Event;
use App\Models\User;
use App\Services\ModerationService;
use App\Support\Moderation\EventModerationWorkflow;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final readonly class AdminEventModerationService
{
    public function __construct(
        private AdminResourceRegistry $registry,
        private ModerationService $moderationService,
    ) {}

    public function canModerate(?User $actor = null): bool
    {
        return $actor instanceof User && $actor->hasAnyRole(['super_admin', 'moderator']);
    }

    /**
     * @return array{data: array{resource: array<string, mixed>, record: array<string, mixed>, schema: array<string, mixed>}}
     */
    public function schema(string $recordKey, ?User $actor = null): array
    {
        abort_unless($this->canModerate($actor), 403);

        $event = $this->resolveEvent($recordKey);
        $availableActions = EventModerationWorkflow::availableActions($event);
        $defaultAction = array_key_first($availableActions);

        return [
            'data' => [
                'resource' => $this->registry->metadata(EventResource::class),
                'record' => $this->registry->serializeRecordDetail(EventResource::class, $event),
                'schema' => [
                    'action' => 'moderate_event',
                    'method' => 'POST',
                    'endpoint' => route('api.admin.events.moderate', ['recordKey' => $event->getRouteKey()], false),
                    'defaults' => [
                        'action' => $defaultAction,
                        'reason_code' => null,
                        'note' => null,
                    ],
                    'available_actions' => array_values(array_map(
                        static fn (string $key, array $definition): array => ['key' => $key] + $definition,
                        array_keys($availableActions),
                        array_values($availableActions),
                    )),
                    'fields' => [
                        [
                            'name' => 'action',
                            'type' => 'string',
                            'required' => true,
                            'default' => $defaultAction,
                            'allowed_values' => array_keys($availableActions),
                        ],
                        [
                            'name' => 'reason_code',
                            'type' => 'string',
                            'required' => false,
                            'allowed_values' => array_keys(EventModerationWorkflow::reasonOptions()),
                        ],
                        [
                            'name' => 'note',
                            'type' => 'string',
                            'required' => false,
                            'max_length' => 2000,
                        ],
                    ],
                    'conditional_rules' => [
                        [
                            'field' => 'reason_code',
                            'required_when' => ['action' => ['request_changes', 'reject']],
                        ],
                        [
                            'field' => 'note',
                            'required_when' => ['action' => ['request_changes', 'reject']],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{data: array{resource: array<string, mixed>, record: array<string, mixed>}}
     */
    public function moderate(string $recordKey, array $payload, ?User $actor = null): array
    {
        abort_unless($this->canModerate($actor), 403);
        abort_unless($actor instanceof User, 403);

        $event = $this->resolveEvent($recordKey);
        $availableActions = EventModerationWorkflow::availableActions($event);

        $validated = Validator::make($payload, [
            'action' => ['required', 'string', Rule::in(array_keys($availableActions))],
            'reason_code' => [
                'nullable',
                'string',
                Rule::in(array_keys(EventModerationWorkflow::reasonOptions())),
                Rule::requiredIf(static fn (): bool => in_array(($payload['action'] ?? null), ['request_changes', 'reject'], true)),
            ],
            'note' => [
                Rule::requiredIf(static fn (): bool => in_array(($payload['action'] ?? null), ['request_changes', 'reject'], true)),
                'nullable',
                'string',
                'max:2000',
            ],
        ])->validate();

        $action = (string) $validated['action'];
        $note = filled($validated['note'] ?? null) ? (string) $validated['note'] : null;
        $reasonCode = filled($validated['reason_code'] ?? null) ? (string) $validated['reason_code'] : null;

        match ($action) {
            'submit_for_moderation' => $this->moderationService->submitForModeration($event),
            'approve' => $this->moderationService->approve($event, $actor, $note),
            'request_changes' => $this->moderationService->requestChanges($event, $actor, (string) $reasonCode, (string) $note),
            'reject' => $this->moderationService->reject($event, $actor, (string) $reasonCode, (string) $note),
            'cancel' => $this->moderationService->cancel($event, $actor, $note),
            'reconsider' => $this->moderationService->reconsider($event, $actor, $note),
            'remoderate' => $this->moderationService->remoderate($event, $actor, $note),
            'revert_to_draft' => $this->moderationService->revertToDraft($event, $actor, $note),
            default => throw new \InvalidArgumentException('Unsupported event moderation action.'),
        };

        $event = $event->fresh() ?? $event;

        return [
            'data' => [
                'resource' => $this->registry->metadata(EventResource::class),
                'record' => $this->registry->serializeRecordDetail(EventResource::class, $event),
            ],
        ];
    }

    private function resolveEvent(string $recordKey): Event
    {
        /** @var Event $event */
        $event = $this->registry->resolveRecord(EventResource::class, $recordKey);

        return $event;
    }
}
