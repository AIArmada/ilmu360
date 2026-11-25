<?php

declare(strict_types=1);

namespace App\Features\Organizations\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Data\RegistrationMode;
use AIArmada\Organizations\Models\Organization;
use AIArmada\Ticketing\Enums\PricingMode;
use App\Features\Events\Api\AdmissionEventCreator;
use App\Features\Organizations\Api\OrganizationAccess;
use App\Features\Organizations\Api\OrganizationEventCreator;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

final readonly class CreateOrganizationEventAction implements OrganizationEventCreator
{
    public function __construct(
        private AdmissionEventCreator $admissionEventCreator,
        private OrganizationAccess $organizationAccess,
    ) {}

    /**
     * @param  array<string, mixed>  $form
     */
    public function create(string $actorId, string $organizationId, array $form): string
    {
        $this->organizationAccess->authorizeCreateEvent($actorId, $organizationId);
        $organization = Organization::query()->findOrFail($organizationId);

        $timezone = (string) ($form['timezone'] ?? config('app.timezone', 'UTC'));
        $startsAt = Carbon::parse((string) $form['starts_at'], $timezone)->utc();
        $endsAt = Carbon::parse((string) $form['ends_at'], $timezone)->utc();

        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            throw new InvalidArgumentException('Event end time must be after the start time.');
        }

        $registrationMode = RegistrationMode::from((string) $form['registration_mode']);
        $pricingMode = PricingMode::from((string) $form['pricing_mode']);

        return OwnerContext::withOwner($organization, fn (): string => $this->admissionEventCreator->create(
            actorId: $actorId,
            form: [
                'title' => (string) $form['title'],
                'description' => $form['description'] ?? null,
                'default_event_format' => (string) $form['delivery_mode'],
                'visibility' => (string) $form['visibility'],
                'registration_required' => $registrationMode !== RegistrationMode::None,
                'default_event_category_ids' => (array) ($form['event_category_ids'] ?? []),
                'check_in_enabled' => (bool) ($form['check_in_enabled'] ?? true),
                'participant_identity' => (string) ($form['participant_identity'] ?? 'none'),
                'refunds_enabled' => (bool) ($form['refunds_enabled'] ?? config('events.features.commerce.refunds_enabled_by_default', false)),
            ],
            startsAt: $startsAt->toIso8601String(),
            endsAt: $endsAt->toIso8601String(),
            timezone: $timezone,
            primaryOrganizerType: null,
            primaryOrganizerId: null,
            locationInstitutionId: null,
            organizationId: (string) $organization->getKey(),
            registrationMode: $registrationMode->value,
            pricingMode: $pricingMode->value,
            tickets: (array) ($form['tickets'] ?? []),
            seating: (array) ($form['seating'] ?? []),
        ));
    }
}
