<?php

namespace App\Features\Communications\EntryPoints\Http\Data;

use App\Features\Communications\Api\NotificationInboxMessageSnapshot;
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

    public static function fromSnapshot(NotificationInboxMessageSnapshot $message): self
    {
        return new self(
            id: $message->id,
            family: $message->family,
            trigger: $message->trigger,
            title: $message->title,
            body: (string) $message->body,
            action_url: $message->actionUrl,
            entity_type: $message->entityType,
            entity_id: $message->entityId,
            priority: $message->priority,
            occurred_at: $message->occurredAt,
            read_at: $message->readAt,
            channels_attempted: $message->channelsAttempted,
            meta: $message->meta,
        );
    }
}
