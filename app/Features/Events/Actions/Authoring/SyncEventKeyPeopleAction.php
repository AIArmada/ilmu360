<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Authoring;

use AIArmada\Events\Features\Catalog\Actions\SyncEventInvolvementsAction;
use AIArmada\Events\Features\Catalog\Models\Event as PackageEvent;
use AIArmada\Events\Features\Catalog\Models\EventOccurrence;
use AIArmada\Events\Features\Catalog\Models\EventSession;
use App\Features\Events\Api\Enums\EventKeyPersonRole;
use Illuminate\Database\Eloquent\Model;

/**
 * Key-person vocabulary adapter over the package involvement synchronizer.
 *
 * Identity dedupe lives in SyncEventInvolvementsAction; this action owns the
 * app vocabulary mapping (speaker shortcut, role filtering, visibility).
 */
final readonly class SyncEventKeyPeopleAction
{
    public function __construct(
        private SyncEventInvolvementsAction $syncInvolvements,
        private GenerateEventSlugAction $generateEventSlug,
    ) {}

    /**
     * @param  list<string>|null  $personIds
     * @param  list<array<string, mixed>>|null  $otherKeyPeople
     */
    public function syncEvent(PackageEvent $event, ?array $personIds = [], ?array $otherKeyPeople = []): void
    {
        $speakerRole = EventKeyPersonRole::Speaker->value;
        $roleCodes = array_values(array_filter(
            $this->managedRoleCodes(),
            static fn (string $roleCode): bool => $roleCode === $speakerRole ? $personIds !== null : $otherKeyPeople !== null,
        ));

        if ($roleCodes === []) {
            return;
        }

        $this->syncInvolvements->handle($event, $this->normalizeRows($personIds ?? [], $otherKeyPeople ?? []), $roleCodes);

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
     * @param  list<string>  $personIds
     * @param  list<array<string, mixed>>  $otherKeyPeople
     */
    public function syncOccurrence(EventOccurrence $occurrence, array $personIds = [], array $otherKeyPeople = []): void
    {
        $this->syncInvolvements->handle($occurrence, $this->normalizeRows($personIds, $otherKeyPeople), $this->managedRoleCodes());
    }

    public function syncOccurrenceOrganizer(EventOccurrence $occurrence, Model $organizer): void
    {
        $this->syncInvolvements->handle($occurrence, [[
            'role_code' => 'organizer',
            'involveable_type' => $organizer->getMorphClass(),
            'involveable_id' => (string) $organizer->getKey(),
            'visibility' => 'public',
            'status' => 'active',
        ]], ['organizer']);
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
