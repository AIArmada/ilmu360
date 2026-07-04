<?php

namespace App\Actions\Events;

use AIArmada\Events\Models\EventAccessPolicy;
use App\Enums\DawahShareOutcomeType;
use App\Enums\ScheduleState;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use App\Services\Notifications\EventNotificationService;
use App\Services\ShareTrackingService;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
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
        $event->loadMissing('accessPolicy');

        $accessPolicy = $event->accessPolicy;

        $this->ensureEventCanBeRegistered($event, $accessPolicy);
        $this->ensureGuestHasContact($attributes, $user);

        try {
            /** @var Registration $registration */
            $registration = DB::transaction(function () use ($event, $attributes, $user): Registration {
                /** @var Event $lockedEvent */
                $lockedEvent = Event::query()
                    ->whereKey($event->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $lockedEvent->load('accessPolicy');

                $this->ensureCapacityAvailable($lockedEvent, $lockedEvent->accessPolicy);
                $this->ensureNotAlreadyRegistered($lockedEvent, $attributes, $user);

                $registration = new Registration([
                    'event_id' => $lockedEvent->getKey(),
                    'registrant_type' => $user?->getMorphClass(),
                    'registrant_id' => $user?->getKey(),
                    'status' => 'confirmed',
                ]);
                $registration->stagePrimaryParticipant(
                    (string) $attributes['name'],
                    $this->nullableString($attributes['email'] ?? null),
                    $this->nullableString($attributes['phone'] ?? null),
                );
                $registration->save();

                $lockedEvent->update([
                    'registrations_count' => Registration::query()
                        ->where('event_id', $lockedEvent->getKey())
                        ->active()
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

    private function ensureEventCanBeRegistered(Event $event, ?EventAccessPolicy $accessPolicy): void
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

        if (! $accessPolicy instanceof EventAccessPolicy || ! $accessPolicy->registration_required) {
            throw ValidationException::withMessages([
                'registration' => 'This event does not require registration.',
            ]);
        }

        if ($accessPolicy->opens_at instanceof CarbonInterface && $accessPolicy->opens_at->isFuture()) {
            throw ValidationException::withMessages([
                'registration' => 'Registration has not opened yet.',
            ]);
        }

        if ($accessPolicy->closes_at instanceof CarbonInterface && $accessPolicy->closes_at->isPast()) {
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

    private function ensureCapacityAvailable(Event $event, ?EventAccessPolicy $accessPolicy): void
    {
        $capacity = $accessPolicy?->capacity;

        if (! $accessPolicy instanceof EventAccessPolicy || ! is_int($capacity)) {
            return;
        }

        $activeRegistrationsCount = Registration::query()
            ->where('event_id', $event->getKey())
            ->active()
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
            ->active();

        $existingRegistration = $user instanceof User
            ? (clone $existingRegistrationQuery)
                ->forUser($user)
                ->exists()
            : (clone $existingRegistrationQuery)
                ->forPrimaryContact(
                    $this->nullableString($attributes['email'] ?? null),
                    $this->nullableString($attributes['phone'] ?? null),
                )
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

        if (str_contains($message, 'event_registrations')) {
            return true;
        }

        return str_contains($message, 'event_registration')
            && str_contains($message, 'unique');
    }
}
