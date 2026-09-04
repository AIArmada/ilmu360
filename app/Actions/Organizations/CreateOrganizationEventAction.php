<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Enums\RegistrationMode;
use AIArmada\Organizations\Models\Organization;
use AIArmada\Ticketing\Enums\PricingMode;
use App\Actions\Events\CreateManagedEventAction;
use App\Models\Event;
use App\Models\User;
use App\Support\Authz\OrganizationEventAccess;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

final class CreateOrganizationEventAction
{
    use AsAction;

    public function __construct(
        private readonly CreateManagedEventAction $createManagedEvent,
        private readonly OrganizationEventAccess $organizationEventAccess,
    ) {}

    /**
     * @param  array<string, mixed>  $form
     */
    public function handle(User $creator, Organization $organization, array $form): Event
    {
        $this->organizationEventAccess->authorizeCreate($creator, $organization);

        $timezone = (string) ($form['timezone'] ?? config('app.timezone', 'UTC'));
        $startsAt = Carbon::parse((string) $form['starts_at'], $timezone)->utc();
        $endsAt = Carbon::parse((string) $form['ends_at'], $timezone)->utc();

        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            throw new InvalidArgumentException('Event end time must be after the start time.');
        }

        $registrationMode = RegistrationMode::from((string) $form['registration_mode']);
        $pricingMode = PricingMode::from((string) $form['pricing_mode']);

        return OwnerContext::withOwner($organization, fn (): Event => $this->createManagedEvent->handle(
            user: $creator,
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
            startsAt: $startsAt,
            endsAt: $endsAt,
            timezone: $timezone,
            primaryOrganizer: null,
            locationInstitutionId: null,
            organization: $organization,
            registrationMode: $registrationMode,
            pricingMode: $pricingMode,
            tickets: (array) ($form['tickets'] ?? []),
            seating: (array) ($form['seating'] ?? []),
        ));
    }
}
