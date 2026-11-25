<?php

declare(strict_types=1);

namespace App\Features\Registration\Contributors;

use AIArmada\Events\Features\Attendance\Models\EventAttendanceLog;
use AIArmada\Events\Features\Registrations\Models\EventRegistrationAnswer;
use AIArmada\Events\Features\Registrations\Models\EventRegistrationItem;
use AIArmada\Events\Features\Registrations\Models\EventRegistrationParticipant;
use App\Features\Events\Api\EventAccountOwnership;
use App\Features\Registration\Actions\ManageRegistrationAccountLinksAction;
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
    public function __construct(
        private EventAccountOwnership $eventOwnership,
        private ManageRegistrationAccountLinksAction $links,
    ) {}

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

        $this->eventOwnership->detach($accountId);
        $this->links->detach($accountId, (string) $user->getMorphClass());
    }

    public function restore(string $accountId, array $snapshot): void
    {
        $userClass = MorphModelResolver::resolve('user');
        $user = $userClass::query()->find($accountId);
        if (! $user instanceof Model) {
            return;
        }

        $this->eventOwnership->restore($accountId, $snapshot);
        $this->links->restore($accountId, (string) $user->getMorphClass(), $snapshot);
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
}
