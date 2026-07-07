<?php

use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use Database\Seeders\RegistrationSeeder;
use Illuminate\Support\Facades\Schema;

it('seeds registrations whether or not the users table has a phone column', function (): void {
    $hasPhoneColumn = Schema::hasColumn('users', 'phone');

    User::factory()->count(3)->create();

    $event = Event::factory()
        ->has(\AIArmada\Events\Models\EventAccessPolicy::factory()->state([
            'registration_required' => true,
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDay(),
        ]), 'settings')
        ->create();

    (new RegistrationSeeder)->run();

    expect(Registration::query()->where('event_id', $event->id)->count())->toBeGreaterThan(0)
        ->and($event->fresh()?->registrations_count)->toBeGreaterThan(0)
        ->and($hasPhoneColumn)->toBeBool();
});
