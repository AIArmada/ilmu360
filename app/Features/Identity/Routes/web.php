<?php

declare(strict_types=1);

use App\Features\Identity\EntryPoints\Http\SocialiteController;
use App\Features\Identity\EntryPoints\Livewire\Pages\Dashboard\AccountSettings;
use Illuminate\Support\Facades\Route;

// Socialite OAuth routes (feature-owned; the canonical Fortify routes remain conventional).
Route::middleware('web')->group(function (): void {
    Route::get('/oauth/{provider}/redirect', [SocialiteController::class, 'redirect'])
        ->name('socialite.redirect')
        ->whereIn('provider', ['google']);
    Route::get('/oauth/{provider}/callback', [SocialiteController::class, 'callback'])
        ->name('socialite.callback')
        ->whereIn('provider', ['google']);
    Route::get('/oauth/{provider}/complete', [SocialiteController::class, 'complete'])
        ->name('socialite.complete')
        ->whereIn('provider', ['google']);
    Route::get('/oauth/error/{code}', [SocialiteController::class, 'error'])
        ->name('socialite.error')
        ->where('code', '[a-z]+');
});

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::livewire('/tetapan', AccountSettings::class)->name('account-settings');
});
