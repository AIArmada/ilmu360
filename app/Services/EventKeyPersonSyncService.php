<?php

namespace App\Services;

use App\Actions\Events\GenerateEventSlugAction;
use App\Enums\EventKeyPersonRole;
use App\Models\Event;
use App\Models\EventKeyPerson;
use Illuminate\Support\Str;

class EventKeyPersonSyncService
{
    public function __construct(
        private readonly GenerateEventSlugAction $generateEventSlugAction,
    ) {}

    /**
     * @param  list<string>  $speakerIds
     * @param  list<array<string, mixed>>  $otherKeyPeople  Canonical key-person rows.
     */
    public function sync(Event $event, array $speakerIds = [], array $otherKeyPeople = []): void
    {
        $event->keyPeople()->delete();

        $order = 1;

        $base = ['status' => 'active', 'visibility' => 'public'];

        foreach ($this->normalizeSpeakerIds($speakerIds) as $speakerId) {
            EventKeyPerson::query()->forceCreate($base + [
                'id' => (string) Str::uuid(),
                'event_id' => $event->id,
                'involveable_type' => 'speaker',
                'involveable_id' => $speakerId,
                'role_code' => EventKeyPersonRole::Speaker->value,
                'sort_order' => $order++,
            ]);
        }

        foreach ($this->normalizeKeyPeople($otherKeyPeople) as $keyPerson) {
            EventKeyPerson::query()->forceCreate($base + [
                'id' => (string) Str::uuid(),
                'event_id' => $event->id,
                'involveable_type' => $keyPerson['involveable_type'],
                'involveable_id' => $keyPerson['involveable_id'],
                'role_code' => $keyPerson['role_code'],
                'sort_order' => $order++,
                'visibility' => $keyPerson['visibility'],
                'notes' => $keyPerson['notes'],
                'display_name' => $keyPerson['display_name'],
            ]);
        }

        $this->generateEventSlugAction->syncEventSlugsForTitle($event->title);
    }

    /**
     * @param  list<string|int|mixed>  $speakerIds
     * @return list<string>
     */
    protected function normalizeSpeakerIds(array $speakerIds): array
    {
        return collect($speakerIds)
            ->filter(fn (mixed $speakerId): bool => is_string($speakerId) && $speakerId !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $keyPeople
     * @return list<array{role_code: string, involveable_type: ?string, involveable_id: ?string, display_name: ?string, visibility: string, notes: ?string}>
     */
    protected function normalizeKeyPeople(array $keyPeople): array
    {
        return collect($keyPeople)
            ->map(function (mixed $keyPerson): ?array {
                $role = $keyPerson['role_code'] ?? null;

                if (! is_string($role) || EventKeyPersonRole::tryFrom($role) === null || $role === EventKeyPersonRole::Speaker->value) {
                    return null;
                }

                $involveableId = is_string($keyPerson['involveable_id'] ?? null) && $keyPerson['involveable_id'] !== ''
                    ? $keyPerson['involveable_id']
                    : null;
                $displayName = is_string($keyPerson['display_name'] ?? null) && trim($keyPerson['display_name']) !== ''
                    ? trim($keyPerson['display_name'])
                    : null;

                if ($involveableId === null && $displayName === null) {
                    return null;
                }

                $visibility = $keyPerson['visibility'] ?? null;
                $visibility = is_string($visibility) && in_array($visibility, ['public', 'private'], true)
                    ? $visibility
                    : 'public';

                return [
                    'role_code' => $role,
                    'involveable_type' => $involveableId === null ? null : 'speaker',
                    'involveable_id' => $involveableId,
                    'display_name' => $displayName,
                    'visibility' => $visibility,
                    'notes' => is_string($keyPerson['notes'] ?? null) && trim($keyPerson['notes']) !== ''
                        ? trim($keyPerson['notes'])
                        : null,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
