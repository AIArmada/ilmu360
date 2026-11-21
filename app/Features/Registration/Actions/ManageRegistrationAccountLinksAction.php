<?php

declare(strict_types=1);

namespace App\Features\Registration\Actions;

use App\Platform\Models\MorphModelResolver;

/**
 * Writes account identity links in retained registration and attendance history.
 */
final readonly class ManageRegistrationAccountLinksAction
{
    public function detach(string $accountId, string $userType): void
    {
        $registrationClass = MorphModelResolver::resolve('registration');
        $checkinClass = MorphModelResolver::resolve('checkin');

        $checkinClass::query()
            ->where('verified_by_user_id', $accountId)
            ->update(['verified_by_user_id' => null]);

        $registrationClass::query()
            ->where('registrant_type', $userType)
            ->where('registrant_id', $accountId)
            ->update(['registrant_type' => null, 'registrant_id' => null]);

        $checkinClass::query()
            ->where('attendee_type', $userType)
            ->where('attendee_id', $accountId)
            ->update(['attendee_type' => null, 'attendee_id' => null]);
    }

    /** @param array<string, mixed> $snapshot */
    public function restore(string $accountId, string $userType, array $snapshot): void
    {
        $this->restoreRegistrations($accountId, $userType, $this->stringIds($snapshot['registration_ids'] ?? []));
        $this->restoreCheckins($accountId, $userType, $this->stringIds($snapshot['event_checkin_ids'] ?? []));
        $this->restoreVerifiedCheckinLinks($accountId, $this->stringIds($snapshot['verified_event_checkin_ids'] ?? []));
    }

    /** @param list<string> $ids */
    private function restoreRegistrations(string $accountId, string $userType, array $ids): void
    {
        $registrationClass = MorphModelResolver::resolve('registration');

        if ($ids === []) {
            return;
        }

        $registrationClass::query()
            ->whereKey($ids)
            ->whereNull('registrant_id')
            ->update([
                'registrant_type' => $userType,
                'registrant_id' => $accountId,
            ]);
    }

    /** @param list<string> $ids */
    private function restoreCheckins(string $accountId, string $userType, array $ids): void
    {
        $checkinClass = MorphModelResolver::resolve('checkin');

        if ($ids === []) {
            return;
        }

        $checkinClass::query()
            ->whereKey($ids)
            ->whereNull('attendee_id')
            ->update([
                'attendee_type' => $userType,
                'attendee_id' => $accountId,
            ]);
    }

    /**
     * @param  list<string>  $ids
     */
    private function restoreVerifiedCheckinLinks(string $accountId, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $checkinClass = MorphModelResolver::resolve('checkin');

        $checkinClass::query()
            ->whereKey($ids)
            ->whereNull('verified_by_user_id')
            ->update(['verified_by_user_id' => $accountId]);
    }

    /**
     * @return list<string>
     */
    private function stringIds(mixed $ids): array
    {
        if (! is_array($ids)) {
            return [];
        }

        return collect($ids)
            ->filter(static fn (mixed $id): bool => is_string($id) || is_int($id))
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }
}
