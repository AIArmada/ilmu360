<?php

namespace App\Data\Api\Notification;

use App\Enums\NotificationFamily;
use App\Enums\NotificationPriority;
use App\Enums\NotificationTrigger;
use App\Models\NotificationMessage;
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

    public static function fromModel(NotificationMessage $message): self
    {
        $family = $message->family;
        $trigger = $message->trigger;
        $priority = $message->priority;
        $occurredAt = $message->occurred_at;
        $readAt = $message->read_at;

        return new self(
            id: (string) $message->id,
            family: $family instanceof NotificationFamily ? $family->value : (string) $family,
            trigger: $trigger instanceof NotificationTrigger ? $trigger->value : (string) $trigger,
            title: (string) $message->title,
            body: (string) $message->body,
            action_url: $message->action_url,
            entity_type: $message->entity_type,
            entity_id: $message->entity_id,
            priority: $priority instanceof NotificationPriority ? $priority->value : (string) $priority,
            occurred_at: $occurredAt instanceof CarbonInterface ? $occurredAt->toIso8601String() : null,
            read_at: $readAt instanceof CarbonInterface ? $readAt->toIso8601String() : null,
            channels_attempted: $message->channels_attempted ?? [],
            meta: $message->meta ?? [],
        );
    }
}
