<?php

namespace App\Actions\Events;

use App\Enums\EventVisibility;
use App\Enums\ScheduleState;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use Carbon\CarbonInterface;
use Lorisleiva\Actions\Concerns\AsAction;

final class ResolveEventCheckInStateAction
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
    public function handle(Event $event, User $user): array
    {
        if (! $event->is_active || $event->visibility !== EventVisibility::Public || ! in_array((string) $event->status, Event::ENGAGEABLE_STATUSES, true)) {
            return [
                'available' => false,
                'reason' => __('Majlis ini tidak tersedia untuk check-in.'),
                'method' => 'self_reported',
                'registration_id' => null,
            ];
        }

        if ($event->schedule_state === ScheduleState::Postponed) {
            return [
                'available' => false,
                'reason' => __('Tarikh majlis belum disahkan untuk check-in.'),
                'method' => 'self_reported',
                'registration_id' => null,
            ];
        }

        $startsAt = $event->starts_at;

        if (! $startsAt instanceof CarbonInterface) {
            return [
                'available' => false,
                'reason' => __('Masa majlis belum ditetapkan untuk check-in.'),
                'method' => 'self_reported',
                'registration_id' => null,
            ];
        }

        $eventTimezone = $event->timezone ?: 'Asia/Kuala_Lumpur';
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

        $registrationRequired = (bool) data_get($event, 'settings.registration_required', false);

        if (! $registrationRequired) {
            return [
                'available' => true,
                'reason' => null,
                'method' => 'self_reported',
                'registration_id' => null,
            ];
        }

        /** @var Registration|null $registration */
        $registration = Registration::query()
            ->where('event_id', $event->id)
            ->where('user_id', $user->id)
            ->where('status', '!=', 'cancelled')
            ->latest('created_at')
            ->first();

        if (! $registration instanceof Registration) {
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
            'registration_id' => $registration->id,
        ];
    }
}
