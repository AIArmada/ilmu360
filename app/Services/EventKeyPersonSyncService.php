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
     * @param  list<array<string, mixed>>  $otherKeyPeople
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
                'involveable_type' => $keyPerson['speaker_id'] !== null ? 'speaker' : null,
                'involveable_id' => $keyPerson['speaker_id'],
                'role_code' => $keyPerson['role'],
                'sort_order' => $order++,
                'visibility' => $keyPerson['visibility'],
                'notes' => $keyPerson['notes'],
                'metadata' => $keyPerson['name'] !== null ? ['name' => $keyPerson['name']] : null,
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
     * @return list<array{role: string, speaker_id: ?string, name: ?string, visibility: string, notes: ?string}>
     */
    protected function normalizeKeyPeople(array $keyPeople): array
    {
        return collect($keyPeople)
            ->map(function (mixed $keyPerson): ?array {
                $role = $keyPerson['role'] ?? null;

                if (! is_string($role) || EventKeyPersonRole::tryFrom($role) === null || $role === EventKeyPersonRole::Speaker->value) {
                    return null;
                }

                $speakerId = is_string($keyPerson['speaker_id'] ?? null) && $keyPerson['speaker_id'] !== ''
                    ? $keyPerson['speaker_id']
                    : null;
                $name = is_string($keyPerson['name'] ?? null) && trim($keyPerson['name']) !== ''
                    ? trim($keyPerson['name'])
                    : null;

                if ($speakerId === null && $name === null) {
                    return null;
                }

                $visibility = $keyPerson['visibility'] ?? null;
                $isPublic = array_key_exists('is_public', $keyPerson)
                    ? filter_var($keyPerson['is_public'], FILTER_VALIDATE_BOOLEAN)
                    : $visibility !== 'private';

                return [
                    'role' => $role,
                    'speaker_id' => $speakerId,
                    'name' => $name,
                    'visibility' => $isPublic ? 'public' : 'private',
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
