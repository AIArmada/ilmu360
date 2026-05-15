<?php

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('allows only global admins through the horizon gate', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $member = User::factory()->create();

    expect(Gate::forUser($superAdmin)->check('viewHorizon'))->toBeTrue()
        ->and(Gate::forUser($admin)->check('viewHorizon'))->toBeTrue()
        ->and(Gate::forUser($moderator)->check('viewHorizon'))->toBeFalse()
        ->and(Gate::forUser($member)->check('viewHorizon'))->toBeFalse();
});

it('registers redis-backed horizon supervisors and metrics snapshots', function () {
    expect(config('queue.connections.redis.retry_after'))->toBe(360)
        ->and(config('media-library.queue_name'))->toBe('media')
        ->and(config('horizon.defaults.supervisor-default.connection'))->toBe('redis')
        ->and(config('horizon.defaults.supervisor-default.queue'))->toBe(['default'])
        ->and(config('horizon.defaults.supervisor-notifications.queue'))->toBe([
            'notifications-inbox',
            'notifications-mail',
            'notifications-push',
            'notifications-whatsapp',
        ])
        ->and(config('horizon.defaults.supervisor-media.queue'))->toBe(['media']);

    $snapshot = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === 'horizon-snapshot');

    expect($snapshot)->not->toBeNull()
        ->and($snapshot->expression)->toBe('*/5 * * * *')
        ->and($snapshot->command)->toContain('horizon:snapshot');
});
