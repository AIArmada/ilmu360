<?php

declare(strict_types=1);

namespace App\Actions\Events;

use AIArmada\Events\Enums\RegistrationMode;
use AIArmada\Organizations\Models\Organization;
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

            return $this->configureEventRegistration->handle(
                event: $event,
                registrationMode: $registrationMode,
                pricingMode: $pricingMode,
                tickets: $tickets,
                seating: $seating,
            );
        });
    }
}
