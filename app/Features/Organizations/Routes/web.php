<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Organization workspace pages.
//
// The page classes live in this feature and compose Directory, Events and
// Identity through their public APIs. The feature owns the URLs, names and
// auth boundary; Livewire's component aliases resolve the classes below.
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
