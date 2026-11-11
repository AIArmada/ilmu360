<?php

declare(strict_types=1);

namespace App\Features\Registration\Queries;

use AIArmada\Events\Models\Event as PackageEvent;
use App\Features\Registration\Api\AttendanceState;
use App\Platform\Models\MorphModelResolver;
use App\Shared\Events\Enums\EventVisibility;
use Carbon\CarbonInterface;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Resolves self-check-in availability for the Registration feature.
 *
 * Event and user identities resolve through morph aliases so the query does
 * not depend on the root namespace while those models are still being moved.
 * The decision matrix, messages and returned shape are unchanged from the
 * original root action.
 */
final readonly class ResolveAttendanceState implements AttendanceState
{
    use AsAction;

    /**
     * @return array{
     *   available: bool,
     *   reason: string|null,
     *   method: 'self_reported'|'registered_self_checkin',
     *   registration_id: string|null
     * }
     */
    public function forUser(string $eventId, string $userId): array
    {
        $eventClass = MorphModelResolver::resolve('event');
        $registrationClass = MorphModelResolver::resolve('registration');
        $userClass = MorphModelResolver::resolve('user');

        /** @var PackageEvent $event */
        $event = $eventClass::query()->findOrFail($eventId);
        $user = $userClass::query()->findOrFail($userId);

        /** @var list<string> $engageableStatuses */
        $engageableStatuses = constant($eventClass.'::ENGAGEABLE_STATUSES');

        if (! $event->occurrences()->exists() || $event->published_at === null || $event->getAttribute('visibility') !== EventVisibility::Public || ! in_array((string) $event->getAttribute('status'), $engageableStatuses, true)) {
            return [
                'available' => false,
                'reason' => __('Majlis ini tidak tersedia untuk check-in.'),
                'method' => 'self_reported',
                'registration_id' => null,
            ];
        }

        $checkInEnabled = data_get(
            is_array($event->metadata) ? $event->metadata : [],
            'registration.check_in_enabled',
        );

        if ($checkInEnabled === false) {
            return [
                'available' => false,
                'reason' => __('Check-in tidak diaktifkan untuk majlis ini.'),
                'method' => 'self_reported',
                'registration_id' => null,
            ];
        }

        $primaryOccurrence = $event->primaryOccurrence;

        if ($primaryOccurrence !== null && in_array((string) $primaryOccurrence->getAttribute('status'), ['postponed', 'rescheduled'], true)) {
            return [
                'available' => false,
                'reason' => __('Tarikh majlis belum disahkan untuk check-in.'),
                'method' => 'self_reported',
                'registration_id' => null,
            ];
        }

        $startsAt = $event->getAttribute('starts_at');

        if (! $startsAt instanceof CarbonInterface) {
            return [
                'available' => false,
                'reason' => __('Masa majlis belum ditetapkan untuk check-in.'),
                'method' => 'self_reported',
                'registration_id' => null,
            ];
        }

        $eventTimezone = $event->timezone ?: (string) config('app.timezone', 'UTC');
        $windowStartsAt = $startsAt->copy()->setTimezone($eventTimezone)->subHours(2);
        $windowEndsAt = $startsAt->copy()->setTimezone($eventTimezone)->addHours(8);
        $now = now($eventTimezone);

        if ($now->lt($windowStartsAt)) {
            return [
                'available' => false,
                'reason' => __('Check-in dibuka 2 jam sebelum majlis bermula.'),
                'method' => 'self_reported',
                'registration_id' => null,
            ];
        }

        if ($now->gt($windowEndsAt)) {
            return [
                'available' => false,
                'reason' => __('Tempoh check-in telah tamat.'),
                'method' => 'self_reported',
                'registration_id' => null,
            ];
        }

        $registrationRequired = (bool) data_get($event, 'accessPolicy.registration_required', false);

        if (! $registrationRequired) {
            return [
                'available' => true,
                'reason' => null,
                'method' => 'self_reported',
                'registration_id' => null,
            ];
        }

        $registration = $registrationClass::query()
            ->where('event_id', $event->getKey())
            ->where('registrant_type', $user->getMorphClass())
            ->where('registrant_id', (string) $user->getKey())
            ->where('status', '!=', 'cancelled')
            ->latest('created_at')
            ->first();

        if ($registration === null) {
            return [
                'available' => false,
                'reason' => __('Majlis ini memerlukan pendaftaran sebelum check-in.'),
                'method' => 'registered_self_checkin',
                'registration_id' => null,
            ];
        }

        return [
            'available' => true,
            'reason' => null,
            'method' => 'registered_self_checkin',
            'registration_id' => (string) $registration->getKey(),
        ];
    }
}
