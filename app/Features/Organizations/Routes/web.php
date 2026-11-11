<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Organization workspace pages.
//
// The Livewire page classes intentionally remain root adapters in
// App\Livewire\Pages\Dashboard\Organizations because they compose Directory,
// Events and Identity private models and Events-owned resources. The feature
// owns the URLs, names and auth boundary; Livewire's App\Livewire discovery
// resolves the aliases below.
Route::middleware(['web', 'auth'])->group(function (): void {
    Route::livewire('/organisasi', 'pages.dashboard.organizations.index')
        ->name('dashboard.organizations.index');
    Route::livewire('/organisasi/cipta', 'pages.dashboard.organizations.create-organization')
        ->name('dashboard.organizations.create');
    Route::livewire('/organisasi/{organization}', 'pages.dashboard.organizations.workspace')
        ->name('dashboard.organizations.show');
    Route::livewire('/organisasi/{organization}/majlis/cipta', 'pages.dashboard.organizations.create-event')
        ->name('dashboard.organizations.events.create');
});
