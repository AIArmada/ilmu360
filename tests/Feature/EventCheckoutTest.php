<?php

use AIArmada\Checkout\Models\CheckoutSession;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Enums\EventRegistrationQuestionStatus;
use AIArmada\Events\Enums\EventRegistrationQuestionType;
use AIArmada\Events\Models\EventRegistrationQuestion;
use AIArmada\Events\States\RegistrationStatus\Confirmed;
use AIArmada\Inventory\Models\InventoryLocation;
use AIArmada\Orders\Models\Order;
use AIArmada\Seating\Enums\SeatingMode;
use App\Livewire\Pages\Events\Checkout;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use App\Support\Events\ParticipantIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function checkoutEvent(array $attributes = []): Event
{
    $event = Event::factory()->create(array_merge([
        'title' => 'Checkout Test Event',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDay(),
        'pricing_mode' => 'free',
        'registration_mode' => 'required',
    ], $attributes));

    $event->accessPolicy()->delete();
    $event->accessPolicy()->create([
        'registration_required' => true,
        'capacity' => 100,
        'walk_in_allowed' => false,
        'opens_at' => now()->subDay(),
        'closes_at' => now()->addDay(),
    ]);

    return $event->fresh();
}

it('requires an authenticated account for event checkout', function (): void {
    $event = checkoutEvent();

    $this->get(route('events.checkout', $event))
        ->assertRedirect(route('login'));
});

it('requires a verified account for event checkout', function (): void {
    $event = checkoutEvent();
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get(route('events.checkout', $event))
        ->assertRedirect(route('verification.notice'));
});

it('submits a free registration with participant agreement under the purchaser account', function (): void {
    $event = checkoutEvent();
    $user = User::factory()->create(['name' => 'Verified Buyer']);

    Livewire::actingAs($user)
        ->test(Checkout::class, ['event' => $event])
        ->set('participants', [[
            'name' => 'Participant One',
            'email' => 'participant@example.com',
            'phone' => '',
            'is_purchaser' => true,
        ]])
        ->set('agreementAccepted', true)
        ->call('submit')
        ->assertRedirect();

    $registration = Registration::query()->where('event_id', $event->getKey())->first();

    expect($registration)->not->toBeNull()
        ->and($registration?->registrant_id)->toBe((string) $user->getKey())
        ->and(data_get($registration?->participants()->first()?->metadata, 'event_checkout.agreement.version'))
        ->toBe('event-participation-v1')
        ->and($registration?->participants()->first()?->is_purchaser)->toBeTrue();
});

it('revalidates ticket selection when an event changes after checkout opens', function (): void {
    $event = checkoutEvent();
    $user = User::factory()->create();

    $checkout = Livewire::actingAs($user)
        ->test(Checkout::class, ['event' => $event]);

    $event->ticketTypes()->create([
        'name' => 'Newly Published Admission',
        'code' => 'NEWLY-PUBLISHED-ADMISSION',
        'access_type' => 'general',
        'seating_mode' => SeatingMode::None,
        'price' => 0,
        'currency' => 'MYR',
        'status' => 'active',
        'visibility' => 'public',
    ]);

    $checkout
        ->set('participants', [[
            'name' => 'Late Ticket Participant',
            'email' => 'late-ticket@example.com',
            'phone' => '',
            'is_purchaser' => true,
        ]])
        ->set('agreementAccepted', true)
        ->call('submit')
        ->assertHasErrors('checkout');

    expect(Registration::query()->where('event_id', $event->getKey())->exists())->toBeFalse();
});

it('protects an optional participant identity document inside the event record', function (): void {
    $event = checkoutEvent([
        'metadata' => [
            'registration' => [
                'participant_identity' => 'ic',
            ],
        ],
    ]);
    $user = User::factory()->create(['name' => 'Identity Buyer']);

    Livewire::actingAs($user)
        ->test(Checkout::class, ['event' => $event])
        ->set('participants', [[
            'name' => 'Identity Participant',
            'email' => 'identity-participant@example.com',
            'phone' => '',
            'identity_document' => '900101-14-5678',
            'is_purchaser' => true,
        ]])
        ->set('agreementAccepted', true)
        ->call('submit')
        ->assertRedirect();

    $registration = Registration::query()->where('event_id', $event->getKey())->firstOrFail();
    $identity = data_get($registration->participants()->firstOrFail()->metadata, 'event_checkout.identity_document');

    expect($identity)->toBeArray()
        ->and($identity['type'] ?? null)->toBe('ic')
        ->and($identity['value_encrypted'] ?? null)->not->toBe('900101-14-5678')
        ->and(Crypt::decryptString((string) ($identity['value_encrypted'] ?? '')))->toBe('900101-14-5678')
        ->and($identity['lookup_hash'] ?? null)->toBe(ParticipantIdentity::lookupHash('900101145678'));
});

it('fulfills a free ticket through the Commerce checkout pipeline', function (): void {
    $event = checkoutEvent();
    $ticket = $event->ticketTypes()->create([
        'name' => 'Free Admission',
        'code' => 'FREE-ADMISSION',
        'access_type' => 'general',
        'seating_mode' => SeatingMode::None,
        'price' => 0,
        'currency' => 'MYR',
        'status' => 'active',
        'visibility' => 'public',
    ]);
    $ticket->inventoryLevels()->create([
        'location_id' => InventoryLocation::getOrCreateDefault()->getKey(),
        'quantity_on_hand' => 100,
    ]);
    $user = User::factory()->create(['name' => 'Ticket Buyer']);

    Livewire::actingAs($user)
        ->test(Checkout::class, ['event' => $event, 'ticket' => $ticket->getKey()])
        ->set('participants', [[
            'name' => 'Ticket Participant',
            'email' => 'ticket-participant@example.com',
            'phone' => '',
            'is_purchaser' => true,
        ]])
        ->set('agreementAccepted', true)
        ->call('submit')
        ->assertRedirect();

    $registration = Registration::query()
        ->where('event_id', $event->getKey())
        ->first();

    expect($registration)->not->toBeNull()
        ->and($registration?->source)->toBe('order')
        ->and($registration?->status)->toBeInstanceOf(Confirmed::class)
        ->and($registration?->items()->count())->toBe(1)
        ->and(OwnerContext::withOwner(null, fn (): int => $registration?->passes()->count() ?? 0))->toBe(1);
});

it('treats a ticket without inventory levels as unlimited inventory', function (): void {
    $event = checkoutEvent();
    $ticket = $event->ticketTypes()->create([
        'name' => 'Unlimited Admission',
        'code' => 'UNLIMITED-ADMISSION',
        'access_type' => 'general',
        'seating_mode' => SeatingMode::None,
        'price' => 0,
        'currency' => 'MYR',
        'status' => 'active',
        'visibility' => 'public',
    ]);
    $user = User::factory()->create(['name' => 'Unlimited Buyer']);

    Livewire::actingAs($user)
        ->test(Checkout::class, ['event' => $event, 'ticket' => $ticket->getKey()])
        ->set('participants', [[
            'name' => 'Unlimited Participant',
            'email' => 'unlimited-participant@example.com',
            'phone' => '',
            'is_purchaser' => true,
        ]])
        ->set('agreementAccepted', true)
        ->call('submit')
        ->assertRedirect();

    expect(Registration::query()->where('event_id', $event->getKey())->exists())->toBeTrue();
});

it('validates and records participant answers from event registration questions', function (): void {
    $user = User::factory()->create(['name' => 'Question Buyer']);
    $this->actingAs($user);

    $event = checkoutEvent([
        'created_by_type' => $user->getMorphClass(),
        'created_by_id' => $user->getKey(),
    ]);

    EventRegistrationQuestion::query()->create([
        'event_id' => $event->getKey(),
        'field_key' => 'dietary_requirements',
        'question' => 'Dietary requirements',
        'type' => EventRegistrationQuestionType::Select,
        'options' => ['Halal', 'Vegetarian'],
        'is_required' => true,
        'status' => EventRegistrationQuestionStatus::Active,
    ]);

    Livewire::actingAs($user)
        ->test(Checkout::class, ['event' => $event])
        ->assertSee('Dietary requirements')
        ->set('participants', [[
            'name' => 'Question Participant',
            'email' => 'question-participant@example.com',
            'phone' => '',
            'identity_document' => '',
            'is_purchaser' => true,
            'answers' => ['dietary_requirements' => 'Vegetarian'],
        ]])
        ->set('agreementAccepted', true)
        ->call('submit')
        ->assertRedirect();

    $registration = Registration::query()->where('event_id', $event->getKey())->firstOrFail();
    $answer = $registration->participants()->firstOrFail()->answers()->firstOrFail();

    expect($answer->field_key)->toBe('dietary_requirements')
        ->and($answer->question)->toBe('Dietary requirements')
        ->and($answer->answer)->toBe('Vegetarian')
        ->and($answer->metadata['question_type'] ?? null)->toBe(EventRegistrationQuestionType::Select->value);
});

it('keeps paid checkout unavailable until the platform payment setting is enabled', function (): void {
    config()->set('events.features.commerce.public_paid_checkout_enabled', false);

    $event = checkoutEvent([
        'pricing_mode' => 'paid',
    ]);
    $ticket = $event->ticketTypes()->create([
        'name' => 'General Admission',
        'code' => 'GENERAL-ADMISSION',
        'access_type' => 'general',
        'seating_mode' => SeatingMode::GeneralAdmission,
        'price' => 2500,
        'currency' => 'MYR',
        'status' => 'active',
        'visibility' => 'public',
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('events.checkout', ['event' => $event, 'ticket' => $ticket]))
        ->assertNotFound();
});

it('renders an authenticated paid checkout result with participant data', function (): void {
    $event = checkoutEvent();
    $user = User::factory()->create(['name' => 'Result Buyer']);
    $order = Order::create([
        'status' => 'processing',
        'customer_type' => $user->getMorphClass(),
        'customer_id' => (string) $user->getKey(),
        'subtotal' => 100,
        'discount_total' => 0,
        'shipping_total' => 0,
        'tax_total' => 0,
        'grand_total' => 100,
        'currency' => 'MYR',
        'paid_at' => now(),
    ]);
    $registration = Registration::factory()
        ->for($event)
        ->withPrimaryParticipant('Result Participant', 'result-participant@example.test')
        ->create([
            'external_order_id' => (string) $order->getKey(),
            'external_order_type' => $order::class,
            'status' => 'confirmed',
        ]);
    $session = CheckoutSession::create([
        'cart_id' => (string) str()->uuid(),
        'order_id' => (string) $order->getKey(),
        'status' => 'completed',
        'cart_snapshot' => [],
        'step_states' => [],
        'payment_data' => [
            'checkout_actor' => [
                'type' => $user->getMorphClass(),
                'id' => (string) $user->getKey(),
            ],
        ],
        'grand_total' => 100,
        'currency' => 'MYR',
    ]);

    $this->actingAs($user)
        ->get(route('checkout.result', ['session' => $session]))
        ->assertOk()
        ->assertSee('Result Participant')
        ->assertSee($registration->registration_no)
        ->assertSee(route('checkout.receipt', ['session' => $session]), false);
});
