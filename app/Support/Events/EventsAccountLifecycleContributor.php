<?php

declare(strict_types=1);

namespace App\Support\Events;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Models\EventAttendanceLog;
use AIArmada\Events\Models\EventRegistrationAnswer;
use AIArmada\Events\Models\EventRegistrationItem;
use AIArmada\Events\Models\EventRegistrationParticipant;
use App\Models\Event;
use App\Models\EventCheckin;
use App\Models\EventSubmission;
use App\Models\Registration;
use App\Models\User;
use App\Shared\Auth\AccountLifecycleContributor;
use App\Shared\Support\MorphAlias;
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
 * Legacy (pre-cutover) snapshots physically deleted registration/check-in rows;
 * their captured rows are recreated on restore under the legacy keys.
 */
final readonly class EventsAccountLifecycleContributor implements AccountLifecycleContributor
{
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
        $user = User::query()->find($accountId);

        if (! $user instanceof User) {
            return [];
        }

        $userTypes = $this->userTypes($user);

        $registrationIds = Registration::query()
            ->whereIn('registrant_type', $userTypes)
            ->where('registrant_id', $accountId)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        $checkinIds = EventCheckin::query()
            ->whereIn('attendee_type', $userTypes)
            ->where('attendee_id', $accountId)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        $verifiedCheckinIds = EventCheckin::query()
            ->where('verified_by_user_id', $accountId)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        return [
            'owned_event_ids' => Event::query()
                ->whereIn('owner_type', $userTypes)
                ->where('owner_id', $accountId)
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->values()
                ->all(),
            'submitted_event_ids' => EventSubmission::query()
                ->whereIn('submitter_type', $userTypes)
                ->where('submitter_id', $accountId)
                ->pluck('event_id')
                ->filter()
                ->map(static fn (mixed $id): string => (string) $id)
                ->unique()
                ->values()
                ->all(),
            'event_submission_ids' => EventSubmission::query()
                ->whereIn('submitter_type', $userTypes)
                ->where('submitter_id', $accountId)
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->values()
                ->all(),
            'verified_event_checkin_ids' => $verifiedCheckinIds,
            'registration_ids' => $registrationIds,
            'event_checkin_ids' => $checkinIds,
            'retained_fact_counts' => $this->retainedFactCounts($registrationIds, $checkinIds),
        ];
    }

    public function detach(string $accountId): void
    {
        $user = User::query()->find($accountId);

        if (! $user instanceof User) {
            return;
        }

        $userTypes = $this->userTypes($user);

        $this->clearEventOwnership($user);

        EventSubmission::query()
            ->whereIn('submitter_type', $userTypes)
            ->where('submitter_id', $accountId)
            ->update(['submitter_type' => null, 'submitter_id' => null]);

        EventCheckin::query()
            ->where('verified_by_user_id', $accountId)
            ->update(['verified_by_user_id' => null]);

        // Event facts are retained; only the personal registration link is
        // detached so the admission and purchaser history remain intact.
        Registration::query()
            ->whereIn('registrant_type', $userTypes)
            ->where('registrant_id', $accountId)
            ->update(['registrant_type' => null, 'registrant_id' => null]);

        // Attendance and check-in log rows survive; only the account's
        // attendee link is detached.
        EventCheckin::query()
            ->whereIn('attendee_type', $userTypes)
            ->where('attendee_id', $accountId)
            ->update(['attendee_type' => null, 'attendee_id' => null]);
    }

    public function restore(string $accountId, array $snapshot): void
    {
        $user = User::query()->find($accountId);

        if (! $user instanceof User) {
            return;
        }

        $this->restoreEventOwnership($user, $this->stringIds($snapshot['owned_event_ids'] ?? []));

        $this->restoreRegistrations($accountId, $user, $snapshot);
        $this->restoreCheckins($accountId, $user, $snapshot);

        $this->restoreSubmissionLinks($accountId, $user, $this->stringIds($snapshot['event_submission_ids'] ?? []));
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
        return [
            'registrations' => count($registrationIds),
            'participants' => $registrationIds === [] ? 0 : EventRegistrationParticipant::query()->whereIn('event_registration_id', $registrationIds)->count(),
            'answers' => $registrationIds === [] ? 0 : EventRegistrationAnswer::query()->whereIn('event_registration_id', $registrationIds)->count(),
            'items' => $registrationIds === [] ? 0 : EventRegistrationItem::query()->whereIn('event_registration_id', $registrationIds)->count(),
            'checkins' => $checkinIds === [] ? 0 : EventCheckin::query()->whereKey($checkinIds)->count(),
            'attendance_logs' => $checkinIds === [] ? 0 : EventAttendanceLog::query()->whereIn('event_attendance_id', $checkinIds)->count(),
        ];
    }

    private function clearEventOwnership(User $user): void
    {
        OwnerContext::withOwner($user, function () use ($user): void {
            Event::query()
                ->whereIn('owner_type', $this->userTypes($user))
                ->where('owner_id', $user->getKey())
                ->get()
                ->each(function (Event $event): void {
                    $event->owner_type = null;
                    $event->owner_id = null;
                    $event->saveQuietly();
                });
        });
    }

    /**
     * @param  list<string>  $ids
     */
    private function restoreEventOwnership(User $user, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        OwnerContext::withOwner($user, function () use ($user, $ids): void {
            Event::query()
                ->whereKey($ids)
                ->get()
                ->each(function (Event $event) use ($user): void {
                    if ($event->owner_id !== null) {
                        return;
                    }

                    $event->owner_type = $user->getMorphClass();
                    $event->owner_id = $user->getKey();
                    $event->saveQuietly();
                });
        });
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function restoreRegistrations(string $accountId, User $user, array $snapshot): void
    {
        $this->restoreLegacyRows(Registration::class, $snapshot['registrations'] ?? []);

        $ids = $this->stringIds($snapshot['registration_ids'] ?? []);

        if ($ids === []) {
            return;
        }

        Registration::query()
            ->whereKey($ids)
            ->whereNull('registrant_id')
            ->update([
                'registrant_type' => $user->getMorphClass(),
                'registrant_id' => $accountId,
            ]);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function restoreCheckins(string $accountId, User $user, array $snapshot): void
    {
        $this->restoreLegacyRows(EventCheckin::class, $snapshot['event_checkins'] ?? []);

        $ids = $this->stringIds($snapshot['event_checkin_ids'] ?? []);

        if ($ids === []) {
            return;
        }

        EventCheckin::query()
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
    private function restoreSubmissionLinks(string $accountId, User $user, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        EventSubmission::query()
            ->whereKey($ids)
            ->whereNull('submitter_id')
            ->update([
                'submitter_type' => $user->getMorphClass(),
                'submitter_id' => $accountId,
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

        EventCheckin::query()
            ->whereKey($ids)
            ->whereNull('verified_by_user_id')
            ->update(['verified_by_user_id' => $accountId]);
    }

    /**
     * Recreate rows captured by legacy snapshots that deleted them.
     *
     * @param  class-string<Model>  $modelClass
     */
    private function restoreLegacyRows(string $modelClass, mixed $records): void
    {
        if (! is_array($records)) {
            return;
        }

        foreach ($records as $attributes) {
            if (! is_array($attributes)) {
                continue;
            }

            $id = $attributes[(new $modelClass)->getKeyName()] ?? null;

            if ($id === null || $id === '') {
                continue;
            }

            if ($modelClass::query()->whereKey($id)->exists()) {
                continue;
            }

            /** @var Model $model */
            $model = new $modelClass;
            $model->timestamps = false;
            $model->forceFill($attributes);
            $model->saveQuietly();
        }
    }

    /**
     * @return list<string>
     */
    private function userTypes(User $user): array
    {
        return array_values(array_unique([
            $user->getMorphClass(),
            MorphAlias::of(User::class) ?? User::class,
            User::class,
        ]));
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
