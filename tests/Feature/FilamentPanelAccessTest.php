<?php

use AIArmada\FilamentAuthz\Facades\Authz;
use AIArmada\FilamentAuthz\Models\Role;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;
use App\Models\User;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('denies ahli panel access to users without any institution, speaker, reference, or event membership', function () {
    $user = User::factory()->create();

    expect($user->canAccessPanel(Panel::make()->id('ahli')))->toBeFalse();
});

it('allows ahli panel access to institution members', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create();

    $institution->members()->syncWithoutDetaching([$user->id]);

    expect($user->canAccessPanel(Panel::make()->id('ahli')))->toBeTrue();
});

it('allows ahli panel access to speaker members', function () {
    $user = User::factory()->create();
    $speaker = Speaker::factory()->create();

    $speaker->members()->syncWithoutDetaching([$user->id]);

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

    $user->memberEvents()->syncWithoutDetaching([$event->id => ['joined_at' => now()]]);

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

it('allows admin panel access when user has a global role assignment', function () {
    $user = User::factory()->create();

    Authz::withScope(null, function () use ($user): void {
        Role::findOrCreate('super_admin', 'web');
        $user->syncRoles(['super_admin']);
    }, $user);

    expect($user->canAccessPanel(Panel::make()->id('admin')))->toBeTrue();
});
