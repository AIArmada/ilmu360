<?php

declare(strict_types=1);

namespace App\Features\Communications\Support\NotificationInbox;

use AIArmada\Communications\Models\NotificationInbox;
use App\Features\Communications\Api\NotificationInboxMessageSnapshot;
use BackedEnum;
use Carbon\CarbonInterface;
use UnitEnum;

final readonly class NotificationInboxMessageSnapshotFactory
{
    public function fromModel(NotificationInbox $message): NotificationInboxMessageSnapshot
    {
        /** @var array<string, mixed> $data */
        $data = $message->data ?? [];

        return new NotificationInboxMessageSnapshot(
            id: (string) $message->getKey(),
            family: $this->enumValue($message->family),
            trigger: $this->enumValue($message->trigger),
            title: (string) $message->title,
            body: $message->body,
            actionUrl: $data['action_url'] ?? null,
            entityType: $data['entity_type'] ?? null,
            entityId: $data['entity_id'] ?? null,
            priority: $this->enumValue($message->priority),
            occurredAt: $data['occurred_at'] ?? null,
            readAt: $message->read_at instanceof CarbonInterface ? $message->read_at->toIso8601String() : null,
            channelsAttempted: $data['channels_attempted'] ?? [],
            meta: $data['meta'] ?? [],
            createdAt: $message->created_at instanceof CarbonInterface ? $message->created_at->toIso8601String() : null,
        );
    }

    private function enumValue(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        return is_string($value) ? $value : (string) $value;
    }
}
