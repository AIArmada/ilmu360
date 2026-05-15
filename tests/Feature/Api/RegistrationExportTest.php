<?php

use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
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

    Registration::factory()->create([
        'event_id' => $event->id,
        'user_id' => $attendee->id,
        'name' => null,
        'email' => null,
        'phone' => '0123456789',
        'status' => 'registered',
    ]);

    Registration::factory()->create([
        'event_id' => $event->id,
        'user_id' => null,
        'name' => 'Guest Registrant',
        'email' => 'guest@example.com',
        'phone' => '0199988877',
        'status' => 'registered',
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
