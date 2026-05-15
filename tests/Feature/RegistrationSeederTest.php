<?php

use App\Models\Event;
use App\Models\EventSettings;
use App\Models\Registration;
use App\Models\User;
use Database\Seeders\RegistrationSeeder;
use Illuminate\Support\Facades\Schema;

it('seeds registrations when the users table has no phone column', function (): void {
    expect(Schema::hasColumn('users', 'phone'))->toBeFalse();

    User::factory()->count(3)->create();

    $event = Event::factory()
        ->has(EventSettings::factory()->state([
            'registration_required' => true,
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDay(),
        ]), 'settings')
        ->create();

    (new RegistrationSeeder)->run();

    expect(Registration::query()->where('event_id', $event->id)->count())->toBeGreaterThan(0)
        ->and($event->fresh()?->registrations_count)->toBeGreaterThan(0);
});