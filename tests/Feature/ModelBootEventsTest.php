<?php

use App\Models\Institution;
use App\Models\Person;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('sets verified_by on institution when status changes to verified', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $institution = Institution::factory()->create(['status' => 'pending']);

    expect($institution->verified_by)->toBeNull();

    $institution->update(['status' => 'verified']);

    expect($institution->fresh()->verified_by)->toBe((string) $user->getKey());
});

it('sets verified_at on institution when status changes to verified', function () {
    $institution = Institution::factory()->create(['status' => 'pending', 'verified_at' => null]);

    $institution->update(['status' => 'verified']);

    expect($institution->fresh()->verified_at)->not->toBeNull();
});

it('sets last_state_change_at on institution when status changes', function () {
    $institution = Institution::factory()->create(['status' => 'pending']);

    $institution->update(['status' => 'verified']);

    expect($institution->fresh()->last_state_change_at)->not->toBeNull();
});

it('sets verified_by on person when status changes to verified', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $person = Person::factory()->create(['status' => 'pending']);

    expect($person->verified_by)->toBeNull();

    $person->update(['status' => 'verified']);

    expect($person->fresh()->verified_by)->toBe((string) $user->getKey());
});

it('sets last_state_change_at on person when status changes', function () {
    $person = Person::factory()->create(['status' => 'pending']);

    $person->update(['status' => 'verified']);

    expect($person->fresh()->last_state_change_at)->not->toBeNull();
});

it('sets verified_by on venue when status changes to verified', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $venue = Venue::factory()->create(['status' => 'pending']);

    expect($venue->verified_by)->toBeNull();

    $venue->update(['status' => 'verified']);

    expect($venue->fresh()->verified_by)->toBe((string) $user->getKey());
});

it('does not set verified_by when status changes to a non-verified value', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $institution = Institution::factory()->create(['status' => 'pending', 'verified_by' => null]);

    $institution->update(['status' => 'rejected']);

    expect($institution->fresh()->verified_by)->toBeNull();
});
