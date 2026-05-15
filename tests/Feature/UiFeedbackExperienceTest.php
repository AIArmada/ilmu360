<?php

use App\Livewire\Pages\SavedSearches\Index as SavedSearchesIndex;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders the shared toast stack on public and auth shells', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('data-toast-root', false);

    $this->get(route('login'))
        ->assertOk()
        ->assertSee('data-toast-root', false);
});

it('dispatches a shared toast when a saved search is created', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(SavedSearchesIndex::class)
        ->set('name', 'Kuliah Maghrib KL')
        ->set('query', 'maghrib')
        ->set('notify', 'daily')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('app-toast');
});

it('renders the custom 404 page', function () {
    $this->get('/definitely-missing-page')
        ->assertNotFound()
        ->assertSee('This page wandered off the map.')
        ->assertSee('Lihat Majlis')
        ->assertSee('Back to Home');
});

it('renders the custom 500 page when debug mode is disabled', function () {
    config()->set('app.debug', false);

    Route::get('/__test/server-error', function (): never {
        throw new RuntimeException('Boom');
    });

    $this->get('/__test/server-error')
        ->assertStatus(500)
        ->assertSee('The server hit an unexpected interruption.')
        ->assertSee('Continue Browsing');
});
