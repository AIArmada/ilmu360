<?php

namespace App\Services;

use App\Actions\Events\GenerateEventSlugAction;
use App\Enums\EventKeyPersonRole;
use App\Models\Event;
use App\Models\EventKeyPerson;

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

        foreach ($this->normalizeSpeakerIds($speakerIds) as $speakerId) {
            EventKeyPerson::query()->create([
                'event_id' => $event->id,
                'speaker_id' => $speakerId,
                'role' => EventKeyPersonRole::Speaker->value,
                'order_column' => $order++,
                'is_public' => true,
            ]);
        }

        foreach ($this->normalizeKeyPeople($otherKeyPeople) as $keyPerson) {
            EventKeyPerson::query()->create([
                'event_id' => $event->id,
                'speaker_id' => $keyPerson['speaker_id'],
                'role' => $keyPerson['role'],
                'name' => $keyPerson['name'],
                'order_column' => $order++,
                'is_public' => $keyPerson['is_public'],
                'notes' => $keyPerson['notes'],
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
     * @return list<array{role: string, speaker_id: ?string, name: ?string, is_public: bool, notes: ?string}>
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

                $name = is_string($keyPerson['name'] ?? null)
                    ? trim($keyPerson['name'])
                    : null;

                if ($speakerId === null && ($name === null || $name === '')) {
                    return null;
                }

                return [
                    'role' => $role,
                    'speaker_id' => $speakerId,
                    'name' => $name !== '' ? $name : null,
                    'is_public' => (bool) ($keyPerson['is_public'] ?? true),
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
