<?php

use AIArmada\Authz\Facades\Authz;
use AIArmada\Authz\Features\Authorization\Models\Role;
use App\Features\Events\Models\Event;
use App\Features\Institutions\Models\Institution;
use App\Features\Persons\Models\Person;
use App\Features\References\Models\Reference;
use App\Models\User;
use Filament\Panel;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('denies ahli panel access to users without any institution, person, reference, or event membership', function () {
    $user = User::factory()->create();

    expect($user->canAccessPanel(Panel::make()->id('ahli')))->toBeFalse();
});

it('allows ahli panel access to institution members', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create();

    $institution->members()->syncWithoutDetaching([$user->id]);

    expect($user->canAccessPanel(Panel::make()->id('ahli')))->toBeTrue();
});

it('allows ahli panel access to person members', function () {
    $user = User::factory()->create();
    $person = Person::factory()->create();

    $person->members()->syncWithoutDetaching([$user->id]);

    expect($user->canAccessPanel(Panel::make()->id('ahli')))->toBeTrue();
});

it('allows ahli panel access to reference members', function () {
    $user = User::factory()->create();
    $reference = Reference::factory()->create();

    $reference->members()->syncWithoutDetaching([$user->id]);

    expect($user->canAccessPanel(Panel::make()->id('ahli')))->toBeTrue();
});

it('allows ahli panel access to event members', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create();

    $event->members()->syncWithoutDetaching([$user->id => ['joined_at' => now()]]);

    expect($user->canAccessPanel(Panel::make()->id('ahli')))->toBeTrue();
});

it('denies admin panel access when user has no global role assignment', function () {
    $user = User::factory()->create();

    expect($user->canAccessPanel(Panel::make()->id('admin')))->toBeFalse();
});

it('denies admin panel access for users with scoped roles only', function () {
    $user = User::factory()->create();
    $scopeId = (string) Str::uuid();

    Authz::withScope($scopeId, function () use ($user): void {
        Role::findOrCreate('institution-admin', 'web');
        $user->syncRoles(['institution-admin']);
    }, $user);

    expect($user->canAccessPanel(Panel::make()->id('admin')))->toBeFalse();
});

it('denies access to unknown panels by default', function () {
    $user = User::factory()->create();

    Authz::withScope(null, function () use ($user): void {
        Role::findOrCreate('institution-admin', 'web');
        $user->syncRoles(['institution-admin']);
    }, $user);

    expect($user->canAccessPanel(Panel::make()->id('unknown')))->toBeFalse();
});

it('allows admin panel access when user has a global role assignment', function () {
    $user = User::factory()->create();

    Authz::withScope(null, function () use ($user): void {
        Role::findOrCreate('super_admin', 'web');
        $user->syncRoles(['super_admin']);
    }, $user);

    expect($user->canAccessPanel(Panel::make()->id('admin')))->toBeTrue();
});
