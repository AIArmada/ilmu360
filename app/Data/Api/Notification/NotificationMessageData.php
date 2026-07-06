<?php

namespace App\Data\Api\Notification;

use AIArmada\Communications\Enums\NotificationFamily;
use AIArmada\Communications\Enums\NotificationPriority;
use AIArmada\Communications\Enums\NotificationTrigger;
use AIArmada\Communications\Models\NotificationInbox;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

class NotificationMessageData extends Data
{
    /**
     * @param  array<int, string>  $channels_attempted
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $id,
        public string $family,
        public string $trigger,
        public string $title,
        public string $body,
        public ?string $action_url,
        public ?string $entity_type,
        public ?string $entity_id,
        public string $priority,
        public ?string $occurred_at,
        public ?string $read_at,
        public array $channels_attempted,
        public array $meta,
    ) {}

    public static function fromModel(NotificationInbox $message): self
    {
        $family = $message->family;
        $trigger = $message->trigger;
        $priority = $message->priority;
        $readAt = $message->read_at;
        $data = $message->data ?? [];

        return new self(
            id: (string) $message->id,
            family: $family instanceof NotificationFamily ? $family->value : (string) $family,
            trigger: $trigger instanceof NotificationTrigger ? $trigger->value : (string) $trigger,
            title: (string) $message->title,
            body: (string) $message->body,
            action_url: $data['action_url'] ?? null,
            entity_type: $data['entity_type'] ?? null,
            entity_id: $data['entity_id'] ?? null,
            priority: $priority instanceof NotificationPriority ? $priority->value : (string) $priority,
            occurred_at: $data['occurred_at'] ?? null,
            read_at: $readAt instanceof CarbonInterface ? $readAt->toIso8601String() : null,
            channels_attempted: $data['channels_attempted'] ?? [],
            meta: $data['meta'] ?? [],
        );
    }
}
