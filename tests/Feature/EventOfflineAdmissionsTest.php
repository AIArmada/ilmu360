<?php

declare(strict_types=1);

use AIArmada\Events\States\RegistrationStatus\Confirmed;
use AIArmada\Events\States\RegistrationStatus\Pending;
use AIArmada\Inventory\Models\InventoryLocation;
use AIArmada\Membership\Enums\MemberRole;
use AIArmada\Orders\Models\Order;
use AIArmada\Seating\Enums\SeatingMode;
use AIArmada\Ticketing\Models\TicketType;
use App\Livewire\Pages\Dashboard\Events\OfflineAdmissions;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** @return array{event: Event, ticket: TicketType} */
function offlineAdmissionFixture(User $organizer): array
{
    $event = Event::factory()->create([
        'title' => 'Offline Admission Event',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHours(2),
        'pricing_mode' => 'paid',
        'registration_mode' => 'required',
        'created_by_type' => $organizer->getMorphClass(),
        'created_by_id' => $organizer->getKey(),
    ]);
    addTestMember($event, $organizer, MemberRole::Owner);

    $event->accessPolicy()->delete();
    $event->accessPolicy()->create([
        'registration_required' => true,
        'capacity' => 100,
        'walk_in_allowed' => false,
        'opens_at' => now()->subDay(),
        'closes_at' => now()->addDay(),
    ]);

    $ticket = $event->ticketTypes()->create([
        'name' => 'General admission',
        'code' => 'GENERAL-OFFLINE',
        'access_type' => 'general',
        'seating_mode' => SeatingMode::None,
        'price' => 3500,
        'currency' => 'MYR',
        'status' => 'active',
        'visibility' => 'public',
    ]);
    $ticket->inventoryLevels()->create([
        'location_id' => InventoryLocation::getOrCreateDefault()->getKey(),
        'quantity_on_hand' => 3,
    ]);

    return ['event' => $event->fresh(), 'ticket' => $ticket->fresh()];
}

it('records a confirmed offline admission through the shared order and pass pipeline', function (): void {
    $organizer = User::factory()->create();
    ['event' => $event, 'ticket' => $ticket] = offlineAdmissionFixture($organizer);

    Livewire::actingAs($organizer)
        ->test(OfflineAdmissions::class, ['event' => $event])
        ->set('ticketId', (string) $ticket->getKey())
        ->set('participant.name', 'Door Participant')
        ->set('participant.email', 'door@example.com')
        ->set('agreementAccepted', true)
        ->set('paymentState', 'confirmed')
        ->set('paymentMethod', 'cash')
        ->set('paymentReference', 'CASH-001')
        ->call('submit')
        ->assertHasNoErrors();

    $registration = Registration::query()->where('source', 'offline_admission')->firstOrFail();
    $order = Order::query()->findOrFail($registration->external_order_id);

    expect($order->isPaid())->toBeTrue()
        ->and($registration->status)->toBeInstanceOf(Confirmed::class)
        ->and($registration->payment_status)->toBe('paid')
        ->and($registration->participants()->first()?->is_purchaser)->toBeFalse()
        ->and($registration->passes()->count())->toBe(1)
        ->and($ticket->fresh()->getTotalAvailable())->toBe(2)
        ->and(data_get($registration->participants()->first()?->metadata, 'event_checkout.agreement.acceptance_mode'))
        ->toBe('organizer_recorded');
});

it('keeps an offline admission pending until the organizer confirms payment', function (): void {
    $organizer = User::factory()->create();
    ['event' => $event, 'ticket' => $ticket] = offlineAdmissionFixture($organizer);

    $component = Livewire::actingAs($organizer)
        ->test(OfflineAdmissions::class, ['event' => $event])
        ->set('ticketId', (string) $ticket->getKey())
        ->set('participant.name', 'Pending Participant')
        ->set('agreementAccepted', true)
        ->set('paymentState', 'pending')
        ->set('paymentMethod', 'bank_transfer')
        ->call('submit')
        ->assertHasNoErrors();

    $registration = Registration::query()->where('source', 'offline_admission')->firstOrFail();
    $order = Order::query()->findOrFail($registration->external_order_id);

    expect($order->isPaid())->toBeFalse()
        ->and($registration->status)->toBeInstanceOf(Pending::class)
        ->and($registration->payment_status)->toBe('pending')
        ->and($registration->passes()->count())->toBe(0)
        ->and($ticket->fresh()->getTotalAvailable())->toBe(3);

    $component
        ->call('startConfirmation', (string) $order->getKey())
        ->set('confirmationPaymentMethod', 'bank_transfer')
        ->set('confirmationPaymentReference', 'TRX-002')
        ->call('confirmPendingAdmission')
        ->assertHasNoErrors();

    expect($order->fresh()->isPaid())->toBeTrue()
        ->and($registration->fresh()->status)->toBeInstanceOf(Confirmed::class)
        ->and($registration->fresh()->payment_status)->toBe('paid')
        ->and($registration->fresh()->passes()->count())->toBe(1)
        ->and($ticket->fresh()->getTotalAvailable())->toBe(2);
});

it('does not expose offline admissions to an unrelated user', function (): void {
    $organizer = User::factory()->create();
    $outsider = User::factory()->create();
    ['event' => $event] = offlineAdmissionFixture($organizer);

    $this->actingAs($outsider)
        ->get(route('dashboard.events.offline-admissions', ['event' => $event]))
        ->assertForbidden();
});
