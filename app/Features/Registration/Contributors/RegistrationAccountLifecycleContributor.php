<?php

declare(strict_types=1);

namespace App\Features\Registration\Contributors;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Models\EventAttendanceLog;
use AIArmada\Events\Models\EventRegistrationAnswer;
use AIArmada\Events\Models\EventRegistrationItem;
use AIArmada\Events\Models\EventRegistrationParticipant;
use App\Platform\Models\MorphModelResolver;
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
 *
 * The registration and attendance fact models are resolved through their morph
 * aliases so this contributor imports no root model classes. The Events-owned
 * event ownership and event_submissions submitter links it rewrites are
 * account-lifecycle cross-owner writes recorded for the final architecture
 * exceptions; they are not claimed by the Registration manifest.
 */
final readonly class RegistrationAccountLifecycleContributor implements AccountLifecycleContributor
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
        $userClass = MorphModelResolver::resolve('user');
        $user = $userClass::query()->find($accountId);

        if (! $user instanceof Model) {
            return [];
        }

        $userTypes = $this->userTypes($user);

        $eventClass = MorphModelResolver::resolve('event');
        $submissionClass = MorphModelResolver::resolve('event_submission');
        $registrationClass = MorphModelResolver::resolve('registration');
        $checkinClass = MorphModelResolver::resolve('checkin');

        $registrationIds = $registrationClass::query()
            ->whereIn('registrant_type', $userTypes)
            ->where('registrant_id', $accountId)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        $checkinIds = $checkinClass::query()
            ->whereIn('attendee_type', $userTypes)
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
            'owned_event_ids' => $eventClass::query()
                ->whereIn('owner_type', $userTypes)
                ->where('owner_id', $accountId)
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->values()
                ->all(),
            'submitted_event_ids' => $submissionClass::query()
                ->whereIn('submitter_type', $userTypes)
                ->where('submitter_id', $accountId)
                ->pluck('event_id')
                ->filter()
                ->map(static fn (mixed $id): string => (string) $id)
                ->unique()
                ->values()
                ->all(),
            'event_submission_ids' => $submissionClass::query()
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
        $userClass = MorphModelResolver::resolve('user');
        $user = $userClass::query()->find($accountId);

        if (! $user instanceof Model) {
            return;
        }

        $userTypes = $this->userTypes($user);

        $submissionClass = MorphModelResolver::resolve('event_submission');
        $registrationClass = MorphModelResolver::resolve('registration');
        $checkinClass = MorphModelResolver::resolve('checkin');

        $this->clearEventOwnership($user);

        $submissionClass::query()
            ->whereIn('submitter_type', $userTypes)
            ->where('submitter_id', $accountId)
            ->update(['submitter_type' => null, 'submitter_id' => null]);

        $checkinClass::query()
            ->where('verified_by_user_id', $accountId)
            ->update(['verified_by_user_id' => null]);

        // Event facts are retained; only the personal registration link is
        // detached so the admission and purchaser history remain intact.
        $registrationClass::query()
            ->whereIn('registrant_type', $userTypes)
            ->where('registrant_id', $accountId)
            ->update(['registrant_type' => null, 'registrant_id' => null]);

        // Attendance and check-in log rows survive; only the account's
        // attendee link is detached.
        $checkinClass::query()
            ->whereIn('attendee_type', $userTypes)
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

    private function clearEventOwnership(Model $user): void
    {
        $eventClass = MorphModelResolver::resolve('event');

        OwnerContext::withOwner($user, function () use ($eventClass, $user): void {
            $eventClass::query()
                ->whereIn('owner_type', $this->userTypes($user))
                ->where('owner_id', $user->getKey())
                ->get()
                ->each(function (Model $event): void {
                    $event->owner_type = null;
                    $event->owner_id = null;
                    $event->saveQuietly();
                });
        });
    }

    /**
     * @param  list<string>  $ids
     */
    private function restoreEventOwnership(Model $user, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $eventClass = MorphModelResolver::resolve('event');

        OwnerContext::withOwner($user, function () use ($eventClass, $ids, $user): void {
            $eventClass::query()
                ->whereKey($ids)
                ->get()
                ->each(function (Model $event) use ($user): void {
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
    private function restoreRegistrations(string $accountId, Model $user, array $snapshot): void
    {
        $registrationClass = MorphModelResolver::resolve('registration');

        $this->restoreLegacyRows($registrationClass, $snapshot['registrations'] ?? []);

        $ids = $this->stringIds($snapshot['registration_ids'] ?? []);

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

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function restoreCheckins(string $accountId, Model $user, array $snapshot): void
    {
        $checkinClass = MorphModelResolver::resolve('checkin');

        $this->restoreLegacyRows($checkinClass, $snapshot['event_checkins'] ?? []);

        $ids = $this->stringIds($snapshot['event_checkin_ids'] ?? []);

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
    private function restoreSubmissionLinks(string $accountId, Model $user, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $submissionClass = MorphModelResolver::resolve('event_submission');

        $submissionClass::query()
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

        $checkinClass = MorphModelResolver::resolve('checkin');

        $checkinClass::query()
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
    private function userTypes(Model $user): array
    {
        $userClass = MorphModelResolver::resolve('user');

        return array_values(array_unique([
            $user->getMorphClass(),
            MorphAlias::of($userClass) ?? $userClass,
            $userClass,
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
