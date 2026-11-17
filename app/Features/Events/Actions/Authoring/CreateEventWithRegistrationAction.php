<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Authoring;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Enums\RegistrationMode;
use AIArmada\Seating\Enums\SeatingMode;
use AIArmada\Ticketing\Enums\PricingMode;
use App\Features\Events\Api\AdmissionEventCreator;
use App\Features\Events\Api\AdvancedEventCreator;
use App\Features\Events\Api\EventAuthoring;
use App\Models\Event;
use Illuminate\Support\Facades\DB;

final readonly class CreateEventWithRegistrationAction implements AdmissionEventCreator
{
    public function __construct(
        private AdvancedEventCreator $createEventWithInitialOccurrence,
        private EventAuthoring $eventAuthoring,
    ) {}

    /**
     * @param  array<string, mixed>  $form
     * @param  array<int, array<string, mixed>>  $tickets
     * @param  array<string, mixed>  $seating
     */
    public function create(
        string $actorId,
        array $form,
        string $startsAt,
        string $endsAt,
        string $timezone,
        ?string $primaryOrganizerType,
        ?string $primaryOrganizerId,
        ?string $locationInstitutionId,
        ?string $organizationId,
        string $registrationMode,
        string $pricingMode,
        array $tickets,
        array $seating = [],
        ?string $locationVenueId = null,
    ): string {
        $registrationMode = RegistrationMode::from($registrationMode);
        $pricingMode = PricingMode::from($pricingMode);
        $tickets = $this->normalizeTicketSeating($tickets);
        $seating = $this->ticketSeatingEnabled() ? $seating : [];

        return DB::transaction(function () use (
            $actorId,
            $form,
            $startsAt,
            $endsAt,
            $timezone,
            $primaryOrganizerType,
            $primaryOrganizerId,
            $locationInstitutionId,
            $organizationId,
            $registrationMode,
            $pricingMode,
            $tickets,
            $seating,
            $locationVenueId,
        ): string {
            $eventId = $this->createEventWithInitialOccurrence->create(
                actorId: $actorId,
                form: $form,
                startsAt: $startsAt,
                endsAt: $endsAt,
                timezone: $timezone,
                primaryOrganizerType: $primaryOrganizerType,
                primaryOrganizerId: $primaryOrganizerId,
                locationInstitutionId: $locationInstitutionId,
                organizationId: $organizationId,
                locationVenueId: $locationVenueId,
            );
            $event = Event::query()->withoutOwnerScope()->findOrFail($eventId);

            return OwnerContext::withOwner($event->owner, function () use (
                $event,
                $registrationMode,
                $pricingMode,
                $tickets,
                $seating,
            ): string {
                $this->eventAuthoring->configureAdmission(
                    (string) $event->getKey(),
                    [
                        'registration_mode' => $registrationMode,
                        'pricing_mode' => $pricingMode,
                        'tickets' => $tickets,
                        'seating' => $seating,
                    ],
                );

                return (string) $event->getKey();
            });
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
