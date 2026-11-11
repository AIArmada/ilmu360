<?php

declare(strict_types=1);

use App\Features\Registration\EntryPoints\Livewire\Pages\Dashboard\Events\RegistrationQuestions;
use Illuminate\Support\Facades\Route;

// Registration questions workspace. The component keeps its root Blade view
// (`livewire.pages.dashboard.events.registration-questions`) while the class
// lives in the feature; route registration here owns the URL, name and auth
// boundary.
Route::middleware(['web', 'auth'])->group(function (): void {
    Route::livewire('/dashboard/majlis/{event}/pendaftaran', RegistrationQuestions::class)
        ->name('dashboard.events.registration-questions');
});
