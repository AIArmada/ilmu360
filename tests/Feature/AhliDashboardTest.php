<?php

use App\Features\Institutions\Models\Institution;
use App\Models\User;
use App\Platform\Admin\EntryPoints\Filament\Pages\AhliDashboard;

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
