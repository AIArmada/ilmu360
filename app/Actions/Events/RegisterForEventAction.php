<?php

namespace App\Actions\Events;

use App\Enums\DawahShareOutcomeType;
use App\Enums\ScheduleState;
use App\Models\Event;
use App\Models\EventSettings;
use App\Models\Registration;
use App\Models\User;
use App\Services\Notifications\EventNotificationService;
use App\Services\ShareTrackingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class RegisterForEventAction
{
    use AsAction;

    public function __construct(
        private ShareTrackingService $shareTrackingService,
        private EventNotificationService $eventNotificationService,
    ) {}

    /**
     * @param  array{name: string, email?: string|null, phone?: string|null}  $attributes
     */
    public function handle(Event $event, array $attributes, ?User $user = null, ?Request $request = null): Registration
    {
        $event->loadMissing('settings');

        $settings = $event->settings;

        $this->ensureEventCanBeRegistered($event, $settings);
        $this->ensureGuestHasContact($attributes, $user);

        try {
            /** @var Registration $registration */
            $registration = DB::transaction(function () use ($event, $attributes, $user): Registration {
                /** @var Event $lockedEvent */
                $lockedEvent = Event::query()
                    ->whereKey($event->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $lockedEvent->load('settings');

                $this->ensureCapacityAvailable($lockedEvent, $lockedEvent->settings);
                $this->ensureNotAlreadyRegistered($lockedEvent, $attributes, $user);

                $registration = Registration::query()->create([
                    'event_id' => $lockedEvent->getKey(),
                    'user_id' => $user?->getKey(),
                    'name' => (string) $attributes['name'],
                    'email' => $this->nullableString($attributes['email'] ?? null),
                    'phone' => $this->nullableString($attributes['phone'] ?? null),
                    'status' => 'registered',
                ]);

                $lockedEvent->update([
                    'registrations_count' => Registration::query()
                        ->where('event_id', $lockedEvent->getKey())
                        ->where('status', '!=', 'cancelled')
                        ->count(),
                ]);

                return $registration;
            }, 3);
        } catch (QueryException $exception) {
            if ($this->isRegistrationDuplicateException($exception)) {
                throw ValidationException::withMessages([
                    'registration' => 'You are already registered for this event.',
                ]);
            }

            throw $exception;
        }

        $this->shareTrackingService->recordOutcome(
            type: DawahShareOutcomeType::EventRegistration,
            outcomeKey: 'event_registration:registration:'.$registration->id,
            subject: $event,
            actor: $user,
            request: $request ?? request(),
            metadata: [
                'registration_id' => $registration->id,
                'guest' => ! $user instanceof User,
            ],
        );

        if ($user instanceof User) {
            $this->eventNotificationService->notifyRegistrationConfirmed($registration);
        }

        return $registration;
    }

    private function ensureEventCanBeRegistered(Event $event, ?EventSettings $settings): void
    {
        if (! in_array((string) $event->status, ['approved', 'pending'], true)) {
            throw ValidationException::withMessages([
                'registration' => 'This event is not available for registration.',
            ]);
        }

        if ($event->schedule_state === ScheduleState::Postponed) {
            throw ValidationException::withMessages([
                'registration' => 'Registration is paused until the new event date is confirmed.',
            ]);
        }

        if (! $settings instanceof EventSettings || ! $settings->registration_required) {
            throw ValidationException::withMessages([
                'registration' => 'This event does not require registration.',
            ]);
        }

        if ($settings->registration_opens_at instanceof Carbon && $settings->registration_opens_at->isFuture()) {
            throw ValidationException::withMessages([
                'registration' => 'Registration has not opened yet.',
            ]);
        }

        if ($settings->registration_closes_at instanceof Carbon && $settings->registration_closes_at->isPast()) {
            throw ValidationException::withMessages([
                'registration' => 'Registration has closed.',
            ]);
        }
    }

    /**
     * @param  array{name: string, email?: string|null, phone?: string|null}  $attributes
     */
    private function ensureGuestHasContact(array $attributes, ?User $user): void
    {
        if ($user instanceof User) {
            return;
        }

        if ($this->nullableString($attributes['email'] ?? null) !== null || $this->nullableString($attributes['phone'] ?? null) !== null) {
            return;
        }

        throw ValidationException::withMessages([
            'contact' => 'Please provide either email or phone number.',
        ]);
    }

    private function ensureCapacityAvailable(Event $event, ?EventSettings $settings): void
    {
        $capacity = $settings?->capacity;

        if (! $settings instanceof EventSettings || ! is_int($capacity)) {
            return;
        }

        $activeRegistrationsCount = Registration::query()
            ->where('event_id', $event->getKey())
            ->where('status', '!=', 'cancelled')
            ->count();

        if ($activeRegistrationsCount >= $capacity) {
            throw ValidationException::withMessages([
                'registration' => 'This event is full.',
            ]);
        }
    }

    /**
     * @param  array{name: string, email?: string|null, phone?: string|null}  $attributes
     */
    private function ensureNotAlreadyRegistered(Event $event, array $attributes, ?User $user): void
    {
        $existingRegistrationQuery = Registration::query()
            ->where('event_id', $event->getKey())
            ->where('status', '!=', 'cancelled');

        $existingRegistration = $user instanceof User
            ? (clone $existingRegistrationQuery)
                ->where('user_id', $user->getKey())
                ->exists()
            : (clone $existingRegistrationQuery)
                ->where(function (Builder $query) use ($attributes): void {
                    $email = $this->nullableString($attributes['email'] ?? null);
                    $phone = $this->nullableString($attributes['phone'] ?? null);

                    if ($email !== null) {
                        $query->where('email', $email);
                    }

                    if ($phone !== null) {
                        if ($email !== null) {
                            $query->orWhere('phone', $phone);
                        } else {
                            $query->where('phone', $phone);
                        }
                    }
                })
                ->exists();

        if ($existingRegistration) {
            throw ValidationException::withMessages([
                'registration' => 'You are already registered for this event.',
            ]);
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function isRegistrationDuplicateException(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'registrations_event_id_email_unique')) {
            return true;
        }

        return str_contains($message, 'registrations')
            && str_contains($message, 'unique');
    }
}
