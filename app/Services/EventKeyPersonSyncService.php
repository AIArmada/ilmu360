<?php

namespace App\Services;

use AIArmada\Events\Actions\SyncEventInvolvementsAction;
use AIArmada\Events\Models\EventSession;
use App\Actions\Events\GenerateEventSlugAction;
use App\Enums\EventKeyPersonRole;
use App\Models\Event;

class EventKeyPersonSyncService
{
    public function __construct(
        private readonly SyncEventInvolvementsAction $syncInvolvements,
        private readonly GenerateEventSlugAction $generateEventSlug,
    ) {}

    /**
     * Canonical key-person rows: role_code, involveable_type,
     * involveable_id, display_name, visibility, notes.
     *
     * @param  list<string>  $personIds
     * @param  list<array<string, mixed>>  $otherKeyPeople
     */
    public function sync(Event $event, array $personIds = [], array $otherKeyPeople = []): void
    {
        $this->syncInvolvements->handle($event, $this->normalizeRows($personIds, $otherKeyPeople), $this->managedRoleCodes());

        $this->generateEventSlug->syncEventSlug($event);
    }

    /**
     * @param  list<string>  $personIds
     * @param  list<array<string, mixed>>  $otherKeyPeople
     */
    public function syncSession(EventSession $session, array $personIds = [], array $otherKeyPeople = []): void
    {
        $this->syncInvolvements->handle($session, $this->normalizeRows($personIds, $otherKeyPeople), $this->managedRoleCodes());
    }

    /**
     * @return list<string>
     */
    private function managedRoleCodes(): array
    {
        return collect(EventKeyPersonRole::cases())
            ->map(static fn (EventKeyPersonRole $role): string => $role->value)
            ->values()
            ->all();
    }

    /**
     * App owns vocabulary mapping only (speaker shortcut, role filtering,
     * visibility normalization). Identity dedupe lives in the generic
     * SyncEventInvolvementsAction so duplicate rows are handled once.
     *
     * @param  list<string>  $personIds
     * @param  list<array<string, mixed>>  $otherKeyPeople
     * @return list<array{role_code: string, involveable_type: ?string, involveable_id: ?string, display_name: ?string, visibility: string, status: string, notes: ?string}>
     */
    private function normalizeRows(array $personIds, array $otherKeyPeople): array
    {
        $rows = [];

        foreach ($personIds as $personId) {
            if (! is_string($personId) || $personId === '') {
                continue;
            }

            $rows[] = [
                'role_code' => EventKeyPersonRole::Speaker->value,
                'involveable_type' => 'person',
                'involveable_id' => $personId,
                'display_name' => null,
                'visibility' => 'public',
                'status' => 'active',
                'notes' => null,
            ];
        }

        foreach ($otherKeyPeople as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $roleCode = $entry['role_code'] ?? null;

            if (! is_string($roleCode) || EventKeyPersonRole::tryFrom($roleCode) === null) {
                continue;
            }

            if ($roleCode === EventKeyPersonRole::Speaker->value) {
                continue;
            }

            $personId = $entry['involveable_id'] ?? null;
            $personId = is_string($personId) && $personId !== '' ? $personId : null;
            $displayName = isset($entry['display_name']) && is_string($entry['display_name']) ? trim($entry['display_name']) : '';
            $notes = isset($entry['notes']) && is_string($entry['notes']) ? trim($entry['notes']) : '';
            $visibility = $entry['visibility'] ?? 'public';

            if ($personId === null && $displayName === '') {
                continue;
            }

            $rows[] = [
                'role_code' => $roleCode,
                'involveable_type' => $personId === null ? null : 'person',
                'involveable_id' => $personId,
                'display_name' => $displayName !== '' ? $displayName : null,
                'visibility' => $visibility === 'private' ? 'private' : 'public',
                'status' => 'active',
                'notes' => $notes !== '' ? $notes : null,
            ];
        }

        return $rows;
    }
}
