<?php

use App\Filament\Pages\AhliDashboard;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('uses Dashboard as the ahli dashboard label in Malay locale for ahli members', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create();

    $institution->members()->syncWithoutDetaching([$user->id]);

    $this->actingAs($user)
        ->get(AhliDashboard::getUrl(panel: 'ahli'))
        ->assertSuccessful()
        ->assertSee('Dashboard')
        ->assertDontSee('Papan pemuka');
});

it('forbids the ahli dashboard for users without any member relationship', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(AhliDashboard::getUrl(panel: 'ahli'))
        ->assertForbidden();
});
