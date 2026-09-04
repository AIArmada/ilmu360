<?php

use AIArmada\Events\Models\EventAttendance;
use AIArmada\Events\Models\EventRegistrationItem;
use AIArmada\Ticketing\Models\TicketType;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use OwenIt\Auditing\Models\Audit;

test('registration export streams csv and writes audit metadata', function () {
    $user = User::factory()->create();
    $attendee = User::factory()->create([
        'name' => 'Attendee User',
        'email' => 'attendee@example.com',
    ]);

    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(7),
    ]);

    Registration::factory()
        ->forRegistrant($attendee)
        ->withPrimaryParticipant($attendee->name, $attendee->email, '0123456789')
        ->create([
            'event_id' => $event->id,
            'status' => 'confirmed',
        ]);

    Registration::factory()
        ->withPrimaryParticipant('Guest Registrant', 'guest@example.com', '0199988877')
        ->create([
            'event_id' => $event->id,
            'status' => 'confirmed',
        ]);

    Sanctum::actingAs($user);

    Gate::shouldReceive('denies')
        ->once()
        ->with('exportRegistrations', Mockery::type(Event::class))
        ->andReturnFalse();

    $response = $this->get(route('api.registrations.export', $event));

    $response->assertOk();

    expect((string) $response->headers->get('content-type'))->toStartWith('text/csv');

    $csv = $response->streamedContent();
    $lines = array_values(array_filter(explode("\n", trim((string) $csv))));
    $header = str_getcsv($lines[0] ?? '', escape: '\\');

    expect($header)->toBe(['Registration ID', 'Name', 'Email', 'Phone', 'Status', 'Registered At'])
        ->and($csv)->toContain('Attendee User')
        ->and($csv)->toContain('attendee@example.com')
        ->and($csv)->toContain('Guest Registrant')
        ->and($csv)->toContain('guest@example.com');

    $audit = Audit::query()
        ->where('event', 'export_registrations')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->auditable_id)->toBe($event->id)
        ->and((int) data_get($audit->new_values, 'count'))->toBe(2);
});

test('registration export neutralizes spreadsheet formulas in attendee values', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(7),
    ]);

    Registration::factory()
        ->withPrimaryParticipant('=HYPERLINK("https://example.test")', 'attendee@example.com', '+60123456789')
        ->create([
            'event_id' => $event->id,
            'status' => 'confirmed',
        ]);

    Sanctum::actingAs($user);

    Gate::shouldReceive('denies')
        ->once()
        ->with('exportRegistrations', Mockery::type(Event::class))
        ->andReturnFalse();

    $csv = $this->get(route('api.registrations.export', $event))->streamedContent();

    expect($csv)->toContain("\"'=HYPERLINK(\"\"https://example.test\"\")\"");
});

test('registration export applies operational filters without exporting protected identity data', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'pricing_mode' => 'paid',
        'starts_at' => now()->addDays(7),
    ]);
    $occurrenceId = (string) Str::uuid();
    $sessionId = (string) Str::uuid();
    $ticket = TicketType::create([
        'ticketable_type' => Event::class,
        'ticketable_id' => $event->getKey(),
        'name' => 'Target Ticket',
        'code' => 'TARGET-'.Str::upper(Str::random(6)),
        'access_type' => 'general',
        'price' => 5000,
        'currency' => 'MYR',
        'status' => 'active',
        'visibility' => 'public',
    ]);

    $target = Registration::factory()
        ->withPrimaryParticipant('Filtered Target', 'target@example.test', '0123456789')
        ->create([
            'event_id' => $event->getKey(),
            'event_occurrence_id' => $occurrenceId,
            'event_session_id' => $sessionId,
            'status' => 'confirmed',
            'payment_status' => 'paid',
        ]);
    $target->participants()->firstOrFail()->forceFill([
        'metadata' => [
            'event_checkout' => [
                'agreement' => ['accepted_at' => now()->toIso8601String()],
            ],
        ],
    ])->save();
    EventRegistrationItem::create([
        'event_registration_id' => $target->getKey(),
        'event_id' => $event->getKey(),
        'event_occurrence_id' => $occurrenceId,
        'event_session_id' => $sessionId,
        'ticket_type_id' => $ticket->getKey(),
        'quantity' => 1,
        'unit_price' => 5000,
        'total_price' => 5000,
        'currency' => 'MYR',
        'status' => 'confirmed',
    ]);
    EventAttendance::create([
        'event_id' => $event->getKey(),
        'event_registration_id' => $target->getKey(),
        'event_registration_participant_id' => $target->participants()->firstOrFail()->getKey(),
        'attendance_type' => 'attended',
        'checked_in_at' => now(),
    ]);

    Registration::factory()
        ->withPrimaryParticipant('Excluded Target', 'excluded@example.test', '0199999999')
        ->create([
            'event_id' => $event->getKey(),
            'event_occurrence_id' => (string) Str::uuid(),
            'event_session_id' => (string) Str::uuid(),
            'status' => 'confirmed',
            'payment_status' => 'pending',
        ]);

    Sanctum::actingAs($user);

    Gate::shouldReceive('denies')
        ->once()
        ->with('exportRegistrations', Mockery::type(Event::class))
        ->andReturnFalse();

    $url = route('api.registrations.export', $event).'?'.http_build_query([
        'q' => 'Filtered Target',
        'status' => 'confirmed',
        'payment' => 'paid',
        'occurrence' => $occurrenceId,
        'session' => $sessionId,
        'ticket' => $ticket->getKey(),
        'attendance' => 'attended',
        'agreement' => 'accepted',
    ]);
    $csv = $this->get($url)->streamedContent();

    expect($csv)->toContain('Filtered Target')
        ->and($csv)->not->toContain('Excluded Target')
        ->and($csv)->not->toContain('900101');
});

test('registration export treats percent signs in search terms literally', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(7),
    ]);

    Registration::factory()
        ->withPrimaryParticipant('Literal % Participant', 'literal-percent@example.test')
        ->create(['event_id' => $event->getKey(), 'status' => 'confirmed']);
    Registration::factory()
        ->withPrimaryParticipant('Ordinary Participant', 'ordinary@example.test')
        ->create(['event_id' => $event->getKey(), 'status' => 'confirmed']);

    Sanctum::actingAs($user);

    Gate::shouldReceive('denies')
        ->once()
        ->with('exportRegistrations', Mockery::type(Event::class))
        ->andReturnFalse();

    $csv = $this->get(route('api.registrations.export', $event).'?q=%25')->streamedContent();

    expect($csv)->toContain('Literal % Participant')
        ->and($csv)->not->toContain('Ordinary Participant');
});

test('registration export does not treat a corrected absence as attended', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(7),
    ]);
    $registration = Registration::factory()
        ->withPrimaryParticipant('Explicit Absence', 'absence@example.test')
        ->create(['event_id' => $event->getKey(), 'status' => 'confirmed']);

    EventAttendance::create([
        'event_id' => $event->getKey(),
        'event_registration_id' => $registration->getKey(),
        'event_registration_participant_id' => $registration->participants()->firstOrFail()->getKey(),
        'attendance_type' => 'did_not_attend',
        'checked_in_at' => now(),
    ]);

    Sanctum::actingAs($user);

    Gate::shouldReceive('denies')
        ->once()
        ->with('exportRegistrations', Mockery::type(Event::class))
        ->andReturnFalse();

    $csv = $this->get(route('api.registrations.export', $event).'?attendance=attended')->streamedContent();

    expect($csv)->not->toContain('Explicit Absence');
});
