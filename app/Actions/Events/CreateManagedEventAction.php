<?php

declare(strict_types=1);

namespace App\Actions\Events;

use AIArmada\Events\Enums\RegistrationMode;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Organizations\Models\Organization;
use AIArmada\Seating\Enums\SeatingMode;
use AIArmada\Ticketing\Enums\PricingMode;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

final class CreateManagedEventAction
{
    use AsAction;

    public function __construct(
        private readonly CreateAdvancedEventAction $createAdvancedEvent,
        private readonly ConfigureEventRegistrationAction $configureEventRegistration,
    ) {}

    /**
     * @param  array<string, mixed>  $form
     * @param  array<int, array<string, mixed>>  $tickets
     * @param  array<string, mixed>  $seating
     */
    public function handle(
        User $user,
        array $form,
        Carbon $startsAt,
        Carbon $endsAt,
        string $timezone,
        Institution|Person|null $primaryOrganizer,
        ?string $locationInstitutionId,
        ?Organization $organization,
        RegistrationMode $registrationMode,
        PricingMode $pricingMode,
        array $tickets,
        array $seating = [],
        ?string $locationVenueId = null,
    ): Event {
        $tickets = $this->normalizeTicketSeating($tickets);
        $seating = $this->ticketSeatingEnabled() ? $seating : [];

        return DB::transaction(function () use (
            $user,
            $form,
            $startsAt,
            $endsAt,
            $timezone,
            $primaryOrganizer,
            $locationInstitutionId,
            $organization,
            $registrationMode,
            $pricingMode,
            $tickets,
            $seating,
            $locationVenueId,
        ): Event {
            $event = $this->createAdvancedEvent->handle(
                user: $user,
                form: $form,
                startsAt: $startsAt,
                endsAt: $endsAt,
                timezone: $timezone,
                primaryOrganizer: $primaryOrganizer,
                locationInstitutionId: $locationInstitutionId,
                organization: $organization,
                locationVenueId: $locationVenueId,
            );

            return OwnerContext::withOwner($event->owner, fn (): Event => $this->configureEventRegistration->handle(
                event: $event,
                registrationMode: $registrationMode,
                pricingMode: $pricingMode,
                tickets: $tickets,
                seating: $seating,
            ));
        });
    }

    /**
     * The package supports assigned seating, but the application deliberately
     * keeps that capability out of the v1 event-creation workflow. Keeping the
     * guard here makes every application entry point obey the same policy while
     * leaving the reusable package API ready for a later release.
     *
     * @param  array<int, array<string, mixed>>  $tickets
     * @return array<int, array<string, mixed>>
     */
    private function normalizeTicketSeating(array $tickets): array
    {
        if ($this->ticketSeatingEnabled()) {
            return $tickets;
        }

        return array_map(
            static fn (array $ticket): array => array_replace($ticket, [
                'seating_mode' => SeatingMode::None->value,
            ]),
            array_values($tickets),
        );
    }

    private function ticketSeatingEnabled(): bool
    {
        return (bool) config('events.features.commerce.ticket_seating_enabled', false);
    }
}
