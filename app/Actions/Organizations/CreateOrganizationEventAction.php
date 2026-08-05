<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use AIArmada\CommerceSupport\Support\MoneyNormalizer;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Actions\EnsureTicketTypeForOccurrenceAction;
use AIArmada\Events\Enums\RegistrationMode;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Organizations\Contracts\OrganizationAuthorization;
use AIArmada\Organizations\Models\Organization;
use AIArmada\Seating\Enums\SeatingMode;
use AIArmada\Seating\Models\Seat;
use AIArmada\Seating\Models\SeatMap;
use AIArmada\Seating\Models\SeatSection;
use AIArmada\Ticketing\Enums\PricingMode;
use AIArmada\Ticketing\Models\TicketType;
use AIArmada\Ticketing\Models\TicketTypeSeatingOption;
use App\Actions\Events\CreateAdvancedEventAction;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

final class CreateOrganizationEventAction
{
    use AsAction;

    public function __construct(
        private readonly CreateAdvancedEventAction $createAdvancedEvent,
        private readonly EnsureTicketTypeForOccurrenceAction $ensureTicketType,
        private readonly OrganizationAuthorization $organizationAuthorization,
    ) {}

    /**
     * @param  array<string, mixed>  $form
     */
    public function handle(User $creator, Organization $organization, array $form): Event
    {
        $this->organizationAuthorization->authorize($creator, $organization, 'organization.update');

        $timezone = (string) ($form['timezone'] ?? config('app.timezone', 'UTC'));
        $startsAt = Carbon::parse((string) $form['starts_at'], $timezone)->utc();
        $endsAt = Carbon::parse((string) $form['ends_at'], $timezone)->utc();
        $registrationMode = RegistrationMode::from((string) $form['registration_mode']);
        $pricingMode = PricingMode::from((string) $form['pricing_mode']);
        $tickets = $this->normalizeTickets((array) ($form['tickets'] ?? []), $pricingMode);
        $seating = (array) ($form['seating'] ?? []);
        $this->validateSeatingConfiguration($tickets, $seating);

        if ($pricingMode === PricingMode::Paid && $registrationMode === RegistrationMode::None) {
            throw new InvalidArgumentException('Paid events must use registration or ticketing.');
        }

        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            throw new InvalidArgumentException('Event end time must be after the start time.');
        }

        return OwnerContext::withOwner($organization, function () use (
            $creator,
            $endsAt,
            $form,
            $organization,
            $pricingMode,
            $registrationMode,
            $seating,
            $startsAt,
            $tickets,
            $timezone,
        ): Event {
            return DB::transaction(function () use (
                $creator,
                $endsAt,
                $form,
                $organization,
                $pricingMode,
                $registrationMode,
                $seating,
                $startsAt,
                $tickets,
                $timezone,
            ): Event {
                $event = $this->createAdvancedEvent->handle(
                    user: $creator,
                    form: [
                        'title' => (string) $form['title'],
                        'description' => $form['description'] ?? null,
                        'default_event_format' => (string) $form['delivery_mode'],
                        'visibility' => (string) $form['visibility'],
                        'registration_required' => $registrationMode !== RegistrationMode::None,
                        'default_event_category_ids' => (array) ($form['event_category_ids'] ?? []),
                    ],
                    startsAt: $startsAt,
                    endsAt: $endsAt,
                    timezone: $timezone,
                    primaryOrganizer: null,
                    locationInstitutionId: null,
                    organization: $organization,
                );

                $event->forceFill([
                    'pricing_mode' => $pricingMode,
                    'registration_mode' => $registrationMode,
                    'issue_passes_for_free' => true,
                ])->save();

                $occurrence = $event->primaryOccurrence;

                if (! $occurrence instanceof EventOccurrence) {
                    throw new InvalidArgumentException('The event occurrence could not be created.');
                }

                $totalQuota = 0;
                $requiresSeating = false;
                $ticketTypes = [];

                foreach ($tickets as $ticket) {
                    $seatingMode = SeatingMode::from($ticket['seating_mode']);
                    $requiresSeating = $requiresSeating || $seatingMode->requiresAllocation();
                    $totalQuota += (int) ($ticket['quota'] ?? 0);

                    $ticketTypes[] = $this->ensureTicketType->handle($occurrence, [
                        'code' => $ticket['code'],
                        'name' => $ticket['name'],
                        'description' => $ticket['description'],
                        'price' => $ticket['price'],
                        'currency' => (string) config('ticketing.defaults.currency', 'MYR'),
                        'max_quantity' => $ticket['max_quantity'],
                        'seating_mode' => $seatingMode,
                        'quota' => $ticket['quota'],
                        'visibility' => 'public',
                    ]);
                }

                $occurrence->forceFill([
                    'capacity' => $totalQuota > 0 ? $totalQuota : null,
                    'pricing_mode' => $pricingMode,
                    'registration_mode' => $registrationMode,
                    'issue_passes_for_free' => true,
                ])->save();

                $event->accessPolicy()->updateOrCreate(
                    ['event_id' => $event->getKey()],
                    [
                        'registration_required' => $registrationMode === RegistrationMode::Required,
                        'payment_required' => $pricingMode === PricingMode::Paid,
                        'ticket_required' => $registrationMode !== RegistrationMode::None,
                        'seating_required' => $requiresSeating,
                        'capacity' => $totalQuota > 0 ? $totalQuota : null,
                        'walk_in_allowed' => $registrationMode === RegistrationMode::None,
                    ],
                );

                if ($requiresSeating) {
                    $seatMap = $this->createSeatMap($event, $seating);
                    $this->validateSeatingCapacity($tickets, $seatMap);
                    $this->attachGeneralAdmissionSections($ticketTypes, $tickets, $seatMap);
                }

                return $event->fresh(['primaryOccurrence', 'ticketTypes', 'accessPolicies']);
            });
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $tickets
     * @return list<array{code: string, name: string, description: ?string, price: int, max_quantity: ?int, quota: ?int, seating_mode: string}>
     */
    private function normalizeTickets(array $tickets, PricingMode $pricingMode): array
    {
        if ($tickets === []) {
            throw new InvalidArgumentException('At least one ticket type is required.');
        }

        $normalized = [];

        foreach (array_values($tickets) as $index => $ticket) {
            $name = mb_trim((string) ($ticket['name'] ?? ''));

            if ($name === '') {
                throw new InvalidArgumentException('Every ticket type needs a name.');
            }

            $price = $pricingMode === PricingMode::Free
                ? 0
                : MoneyNormalizer::toCents((string) ($ticket['price'] ?? '0.00'));

            if ($pricingMode === PricingMode::Paid && $price <= 0) {
                throw new InvalidArgumentException('Paid ticket types must have a price greater than zero.');
            }

            $baseCode = Str::upper(Str::slug((string) ($ticket['code'] ?? $name), '_'));
            $code = ($baseCode !== '' ? $baseCode : 'TICKET').'_'.($index + 1);

            $normalizedSeatingMode = SeatingMode::tryFrom((string) ($ticket['seating_mode'] ?? SeatingMode::None->value));

            $normalized[] = [
                'code' => $code,
                'name' => $name,
                'description' => filled($ticket['description'] ?? null) ? (string) $ticket['description'] : null,
                'price' => $price,
                'max_quantity' => filled($ticket['max_quantity'] ?? null) ? (int) $ticket['max_quantity'] : null,
                'quota' => filled($ticket['quota'] ?? null) ? (int) $ticket['quota'] : null,
                'seating_mode' => $normalizedSeatingMode instanceof SeatingMode
                    ? $normalizedSeatingMode->value
                    : SeatingMode::None->value,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $seating
     */
    private function createSeatMap(Event $event, array $seating): SeatMap
    {
        $mapName = mb_trim((string) ($seating['map_name'] ?? 'Main seating map'));
        $mode = (string) ($seating['mode'] ?? SeatingMode::GeneralAdmission->value);
        $sections = (array) ($seating['sections'] ?? []);

        if ($sections === []) {
            throw new InvalidArgumentException('At least one seating section is required.');
        }

        $map = SeatMap::query()->create([
            'seatable_type' => $event->getMorphClass(),
            'seatable_id' => $event->getKey(),
            'name' => $mapName !== '' ? $mapName : 'Main seating map',
            'slug' => Str::slug($mapName !== '' ? $mapName : 'main seating map'),
            'layout_metadata' => ['mode' => $mode],
        ]);

        foreach (array_values($sections) as $sectionIndex => $section) {
            $name = mb_trim((string) ($section['name'] ?? ''));
            $capacity = max(1, (int) ($section['capacity'] ?? 0));
            $rows = max(1, (int) ($section['rows'] ?? 1));
            $seatsPerRow = max(1, (int) ($section['seats_per_row'] ?? $capacity));

            if ($name === '') {
                throw new InvalidArgumentException('Every seating section needs a name.');
            }

            if ($mode === SeatingMode::Assigned->value) {
                $capacity = $rows * $seatsPerRow;
            }

            $sectionModel = SeatSection::query()->create([
                'seat_map_id' => $map->getKey(),
                'name' => $name,
                'code' => Str::upper(mb_trim((string) ($section['code'] ?? 'SEC'.($sectionIndex + 1)))),
                'sort_order' => $sectionIndex,
                'capacity' => $capacity,
                'color' => $section['color'] ?? null,
            ]);

            if (! in_array($mode, [SeatingMode::Assigned->value, SeatingMode::Hybrid->value], true)) {
                continue;
            }

            for ($row = 1; $row <= $rows; $row++) {
                for ($column = 1; $column <= $seatsPerRow; $column++) {
                    Seat::query()->create([
                        'seat_section_id' => $sectionModel->getKey(),
                        'row_label' => $this->rowLabel($row),
                        'seat_label' => (string) $column,
                        'row_number' => $row,
                        'column_number' => $column,
                        'category' => 'standard',
                        'status' => 'available',
                    ]);
                }
            }
        }

        return $map->fresh(['sections']);
    }

    /**
     * @param  list<TicketType>  $ticketTypes
     * @param  list<array{code: string, name: string, description: ?string, price: int, max_quantity: ?int, quota: ?int, seating_mode: string}>  $tickets
     */
    private function attachGeneralAdmissionSections(array $ticketTypes, array $tickets, SeatMap $seatMap): void
    {
        $section = $seatMap->sections->first();

        if ($section === null) {
            throw new InvalidArgumentException('At least one seating section is required.');
        }

        foreach ($ticketTypes as $index => $ticketType) {
            if (($tickets[$index]['seating_mode'] ?? SeatingMode::None->value) !== SeatingMode::GeneralAdmission->value) {
                continue;
            }

            TicketTypeSeatingOption::query()->create([
                'ticket_type_id' => $ticketType->getKey(),
                'seat_section_id' => $section->getKey(),
                'included_quantity' => 1,
                'allowed_quantity' => $ticketType->max_quantity,
            ]);
        }
    }

    /**
     * @param  list<array{code: string, name: string, description: ?string, price: int, max_quantity: ?int, quota: ?int, seating_mode: string}>  $tickets
     * @param  array<string, mixed>  $seating
     */
    private function validateSeatingConfiguration(array $tickets, array $seating): void
    {
        $seatingModes = array_values(array_filter(
            array_map(
                static fn (array $ticket): string => $ticket['seating_mode'],
                $tickets,
            ),
            static fn (string $mode): bool => $mode !== SeatingMode::None->value,
        ));

        if ($seatingModes === []) {
            return;
        }

        $mapMode = (string) ($seating['mode'] ?? '');
        $hasGeneralAdmission = in_array(SeatingMode::GeneralAdmission->value, $seatingModes, true);
        $hasAssignedSeats = (bool) array_intersect(
            $seatingModes,
            [SeatingMode::Assigned->value, SeatingMode::Hybrid->value],
        );

        $expectedModes = match (true) {
            $hasGeneralAdmission && $hasAssignedSeats => [SeatingMode::Hybrid->value],
            $hasGeneralAdmission => [SeatingMode::GeneralAdmission->value],
            default => [SeatingMode::Assigned->value, SeatingMode::Hybrid->value],
        };

        if (! in_array($mapMode, $expectedModes, true)) {
            throw new InvalidArgumentException('The seating map mode does not support the selected ticket seating modes.');
        }
    }

    /**
     * @param  list<array{code: string, name: string, description: ?string, price: int, max_quantity: ?int, quota: ?int, seating_mode: string}>  $tickets
     */
    private function validateSeatingCapacity(array $tickets, SeatMap $seatMap): void
    {
        $requestedCapacity = array_sum(array_map(
            static fn (array $ticket): int => (int) ($ticket['quota'] ?? 0),
            array_values(array_filter(
                $tickets,
                static fn (array $ticket): bool => $ticket['seating_mode'] !== SeatingMode::None->value,
            )),
        ));

        $availableCapacity = (int) $seatMap->sections->sum(static fn (SeatSection $section): int => $section->capacity);

        if ($requestedCapacity > 0 && $requestedCapacity > $availableCapacity) {
            throw new InvalidArgumentException('Ticket capacity cannot exceed the seating map capacity.');
        }
    }

    private function rowLabel(int $row): string
    {
        $label = '';

        while ($row > 0) {
            $row--;
            $label = chr(65 + ($row % 26)).$label;
            $row = intdiv($row, 26);
        }

        return $label;
    }
}
