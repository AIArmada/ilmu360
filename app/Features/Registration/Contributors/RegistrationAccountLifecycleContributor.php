<?php

declare(strict_types=1);

namespace App\Features\Registration\Contributors;

use AIArmada\Events\Models\EventAttendanceLog;
use AIArmada\Events\Models\EventRegistrationAnswer;
use AIArmada\Events\Models\EventRegistrationItem;
use AIArmada\Events\Models\EventRegistrationParticipant;
use App\Features\Events\Api\EventAccountOwnership;
use App\Platform\Models\MorphModelResolver;
use App\Shared\Auth\AccountLifecycleContributor;
use Illuminate\Database\Eloquent\Model;

/**
 * Preserves an account's event facts across deletion and restoration.
 *
 * Registrations, participants, answers, items, attendance, and check-in logs
 * are retained as operational/financial history. Only the account's personal
 * links to those facts are detached, and restored only while the link is still
 * unassigned. The snapshot records the retained fact counts so deletion leaves
 * an explicit audit trail instead of silently discarding history.
 *
 * The registration and attendance fact models are resolved through their morph
 * aliases so this contributor imports no root model classes. Events-owned
 * ownership and submission links are delegated through EventAccountOwnership.
 */
final readonly class RegistrationAccountLifecycleContributor implements AccountLifecycleContributor
{
    public function __construct(private EventAccountOwnership $eventOwnership) {}

    public function key(): string
    {
        return 'events';
    }

    public function priority(): int
    {
        return 70;
    }

    public function snapshot(string $accountId): array
    {
        $userClass = MorphModelResolver::resolve('user');
        $user = $userClass::query()->find($accountId);

        if (! $user instanceof Model) {
            return [];
        }

        $registrationClass = MorphModelResolver::resolve('registration');
        $checkinClass = MorphModelResolver::resolve('checkin');
        $userType = $user->getMorphClass();

        $registrationIds = $registrationClass::query()
            ->where('registrant_type', $userType)
            ->where('registrant_id', $accountId)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        $checkinIds = $checkinClass::query()
            ->where('attendee_type', $userType)
            ->where('attendee_id', $accountId)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        $verifiedCheckinIds = $checkinClass::query()
            ->where('verified_by_user_id', $accountId)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        return [
            ...$this->eventOwnership->snapshot($accountId),
            'verified_event_checkin_ids' => $verifiedCheckinIds,
            'registration_ids' => $registrationIds,
            'event_checkin_ids' => $checkinIds,
            'retained_fact_counts' => $this->retainedFactCounts($registrationIds, $checkinIds),
        ];
    }

    public function detach(string $accountId): void
    {
        $userClass = MorphModelResolver::resolve('user');
        $user = $userClass::query()->find($accountId);

        if (! $user instanceof Model) {
            return;
        }

        $registrationClass = MorphModelResolver::resolve('registration');
        $checkinClass = MorphModelResolver::resolve('checkin');
        $userType = $user->getMorphClass();

        $this->eventOwnership->detach($accountId);

        $checkinClass::query()
            ->where('verified_by_user_id', $accountId)
            ->update(['verified_by_user_id' => null]);

        // Event facts are retained; only the personal registration link is
        // detached so the admission and purchaser history remain intact.
        $registrationClass::query()
            ->where('registrant_type', $userType)
            ->where('registrant_id', $accountId)
            ->update(['registrant_type' => null, 'registrant_id' => null]);

        // Attendance and check-in log rows survive; only the account's
        // attendee link is detached.
        $checkinClass::query()
            ->where('attendee_type', $userType)
            ->where('attendee_id', $accountId)
            ->update(['attendee_type' => null, 'attendee_id' => null]);
    }

    public function restore(string $accountId, array $snapshot): void
    {
        $userClass = MorphModelResolver::resolve('user');
        $user = $userClass::query()->find($accountId);

        if (! $user instanceof Model) {
            return;
        }

        $this->eventOwnership->restore($accountId, $snapshot);

        $this->restoreRegistrations($accountId, $user, $this->stringIds($snapshot['registration_ids'] ?? []));
        $this->restoreCheckins($accountId, $user, $this->stringIds($snapshot['event_checkin_ids'] ?? []));

        $this->restoreVerifiedCheckinLinks($accountId, $this->stringIds($snapshot['verified_event_checkin_ids'] ?? []));
    }

    public function afterRestore(string $accountId): void {}

    /**
     * @param  list<string>  $registrationIds
     * @param  list<string>  $checkinIds
     * @return array<string, int>
     */
    private function retainedFactCounts(array $registrationIds, array $checkinIds): array
    {
        $checkinClass = MorphModelResolver::resolve('checkin');

        return [
            'registrations' => count($registrationIds),
            'participants' => $registrationIds === [] ? 0 : EventRegistrationParticipant::query()->whereIn('event_registration_id', $registrationIds)->count(),
            'answers' => $registrationIds === [] ? 0 : EventRegistrationAnswer::query()->whereIn('event_registration_id', $registrationIds)->count(),
            'items' => $registrationIds === [] ? 0 : EventRegistrationItem::query()->whereIn('event_registration_id', $registrationIds)->count(),
            'checkins' => $checkinIds === [] ? 0 : $checkinClass::query()->whereKey($checkinIds)->count(),
            'attendance_logs' => $checkinIds === [] ? 0 : EventAttendanceLog::query()->whereIn('event_attendance_id', $checkinIds)->count(),
        ];
    }

    /** @param list<string> $ids */
    private function restoreRegistrations(string $accountId, Model $user, array $ids): void
    {
        $registrationClass = MorphModelResolver::resolve('registration');

        if ($ids === []) {
            return;
        }

        $registrationClass::query()
            ->whereKey($ids)
            ->whereNull('registrant_id')
            ->update([
                'registrant_type' => $user->getMorphClass(),
                'registrant_id' => $accountId,
            ]);
    }

    /** @param list<string> $ids */
    private function restoreCheckins(string $accountId, Model $user, array $ids): void
    {
        $checkinClass = MorphModelResolver::resolve('checkin');

        if ($ids === []) {
            return;
        }

        $checkinClass::query()
            ->whereKey($ids)
            ->whereNull('attendee_id')
            ->update([
                'attendee_type' => $user->getMorphClass(),
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
