<?php

use AIArmada\Events\Models\EventAccessPolicy;
use App\Features\Events\Models\Event;
use App\Features\Registration\Models\Registration;
use App\Models\User;
use Database\Seeders\RegistrationSeeder;
use Illuminate\Support\Facades\Schema;

it('seeds registrations whether or not the users table has a phone column', function (): void {
    $hasPhoneColumn = Schema::hasColumn('users', 'phone');

    User::factory()->count(3)->create();

    $event = Event::factory()
        ->has(EventAccessPolicy::factory()->state([
            'registration_required' => true,
            'opens_at' => now()->subDay(),
            'closes_at' => now()->addDay(),
        ]), 'accessPolicy')
        ->create();

    (new RegistrationSeeder)->run();

    expect(Registration::query()->where('event_id', $event->id)->count())->toBeGreaterThan(0)
        ->and($hasPhoneColumn)->toBeBool();
});
