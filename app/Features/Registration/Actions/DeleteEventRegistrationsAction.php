<?php

declare(strict_types=1);

namespace App\Features\Registration\Actions;

use AIArmada\Events\Models\EventRegistrationParticipant;
use App\Features\Registration\Api\EventRegistrationDeletion;
use App\Features\Registration\Models\Registration;
use App\Features\Registration\Persistence\RegistrationWriter;
use App\Platform\Models\MorphModelResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Deletes event registrations followed by remaining event check-ins.
 *
 * Per-model deletion preserves the Registration and EventCheckin model hooks
 * that remove participant-owned and attendance-owned child records. The
 * Registration writer keeps each registration delete transactional.
 */
final readonly class DeleteEventRegistrationsAction implements EventRegistrationDeletion
{
    public function __construct(
        private RegistrationWriter $registrationWriter,
    ) {}

    public function assertEventRegistrationsCanBeDeleted(string $eventId): void
    {
        $registrationClass = MorphModelResolver::resolve('registration');
        $eventRegistrationIds = $registrationClass::query()
            ->where('event_id', $eventId)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $registrationId): string => (string) $registrationId)
            ->all();

        $this->leafFirstRegistrationIds($registrationClass, $eventRegistrationIds);
    }

    public function deleteParticipantChildrenBeforeVendorCascade(string $registrationId): void
    {
        DB::transaction(function () use ($registrationId): void {
            $registrationClass = MorphModelResolver::resolve('registration');
            $registrationIds = $this->leafFirstRegistrationIds($registrationClass, [$registrationId]);

            foreach ($registrationIds as $descendantRegistrationId) {
                if ($descendantRegistrationId === $registrationId) {
                    continue;
                }

                $descendantRegistration = $registrationClass::query()
                    ->whereKey($descendantRegistrationId)
                    ->first();

                if ($descendantRegistration instanceof Registration
                    && $this->registrationWriter->delete($descendantRegistration) !== true) {
                    throw new LogicException('A descendant registration could not be deleted through the app model.');
                }
            }

            EventRegistrationParticipant::query()
                ->where('event_registration_id', $registrationId)
                ->each(function (EventRegistrationParticipant $participant): void {
                    $participant->answers()->each(fn ($answer): bool => (bool) $answer->delete());
                    $participant->attendances()->each(fn ($attendance): bool => (bool) $attendance->delete());
                    $participant->delete();
                });
        });
    }

    public function deleteForEvent(string $eventId): void
    {
        DB::transaction(function () use ($eventId): void {
            $registrationClass = MorphModelResolver::resolve('registration');
            $eventRegistrationIds = $registrationClass::query()
                ->where('event_id', $eventId)
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn (mixed $registrationId): string => (string) $registrationId)
                ->all();
            $registrationIds = $this->leafFirstRegistrationIds($registrationClass, $eventRegistrationIds);

            foreach ($registrationIds as $registrationId) {
                $registration = $registrationClass::query()
                    ->whereKey($registrationId)
                    ->first();

                if ($registration instanceof Registration && $this->registrationWriter->delete($registration) !== true) {
                    throw new LogicException('A registration could not be deleted through the app model.');
                }
            }

            $checkinClass = MorphModelResolver::resolve('checkin');

            $checkinClass::query()
                ->where('event_id', $eventId)
                ->get()
                ->each(static function (Model $checkin): void {
                    $checkin->delete();
                });
        });
    }

    /**
     * @param  class-string<Model>  $registrationClass
     * @param  list<string>  $rootRegistrationIds
     * @return list<string>
     */
    private function leafFirstRegistrationIds(string $registrationClass, array $rootRegistrationIds): array
    {
        /** @var array<string, true> $registrationIds */
        $registrationIds = [];

        /** @var list<string> $pendingRegistrationIds */
        $pendingRegistrationIds = array_values(array_unique($rootRegistrationIds));

        /** @var array<string, list<string>> $childrenByParentId */
        $childrenByParentId = [];

        /** @var array<string, string> $parentByChildId */
        $parentByChildId = [];

        while (($currentRegistrationId = array_pop($pendingRegistrationIds)) !== null) {
            if (isset($registrationIds[$currentRegistrationId])) {
                continue;
            }

            $registrationIds[$currentRegistrationId] = true;

            $parentRegistration = $registrationClass::query()
                ->whereKey($currentRegistrationId)
                ->first(['id', 'event_id']);
            $childRegistrations = $registrationClass::query()
                ->where('parent_registration_id', $currentRegistrationId)
                ->orderBy('id')
                ->get(['id', 'event_id']);

            if (! $parentRegistration instanceof Model && $childRegistrations->isNotEmpty()) {
                throw new LogicException('A registration with descendants could not be resolved.');
            }

            $childRegistrationIds = [];

            foreach ($childRegistrations as $childRegistration) {
                if ($childRegistration->getAttribute('event_id') !== $parentRegistration?->getAttribute('event_id')) {
                    throw new LogicException('A child registration belongs to a different event than its parent.');
                }

                $childRegistrationIds[] = (string) $childRegistration->getKey();
            }

            $childrenByParentId[$currentRegistrationId] = $childRegistrationIds;

            foreach ($childRegistrationIds as $childRegistrationId) {
                $parentByChildId[$childRegistrationId] = $currentRegistrationId;
                $pendingRegistrationIds[] = $childRegistrationId;
            }
        }

        /** @var array<string, int> $remainingChildCount */
        $remainingChildCount = array_fill_keys(array_keys($registrationIds), 0);

        foreach ($childrenByParentId as $parentRegistrationId => $childRegistrationIds) {
            $remainingChildCount[$parentRegistrationId] = count($childRegistrationIds);
        }

        $readyRegistrationIds = [];

        foreach ($remainingChildCount as $registrationId => $childCount) {
            if ($childCount === 0) {
                $readyRegistrationIds[] = $registrationId;
            }
        }

        $leafFirstRegistrationIds = [];

        while ($readyRegistrationIds !== []) {
            sort($readyRegistrationIds, SORT_STRING);
            $registrationId = array_shift($readyRegistrationIds);

            if (! is_string($registrationId)) {
                continue;
            }

            $leafFirstRegistrationIds[] = $registrationId;
            $parentRegistrationId = $parentByChildId[$registrationId] ?? null;

            if ($parentRegistrationId === null) {
                continue;
            }

            $remainingChildCount[$parentRegistrationId]--;

            if ($remainingChildCount[$parentRegistrationId] === 0) {
                $readyRegistrationIds[] = $parentRegistrationId;
            }
        }

        if (count($leafFirstRegistrationIds) !== count($registrationIds)) {
            throw new LogicException('A registration parent cycle prevents safe recursive deletion.');
        }

        return $leafFirstRegistrationIds;
    }
}
