<?php

declare(strict_types=1);

use App\Features\Events\EntryPoints\Livewire\Pages\Dashboard\Events\Schedule;
use Illuminate\Support\Facades\Route;

// Events dashboard schedule workspace. The component keeps its root Blade view
// (`livewire.pages.dashboard.events.schedule`) while the class lives in the
// feature; route registration here owns the URL, name and auth boundary.
Route::middleware(['web', 'auth'])->group(function (): void {
    Route::livewire('/dashboard/majlis/{event}/jadual', Schedule::class)
        ->name('dashboard.events.schedule');
});
