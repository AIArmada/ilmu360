<?php

declare(strict_types=1);

use App\Features\Registration\EntryPoints\Http\RegistrationExportController;
use App\Features\Registration\EntryPoints\Livewire\Pages\Dashboard\Events\RegistrationQuestions;
use Illuminate\Support\Facades\Route;

// Registration dashboard entry points are registered by their owning feature.
// The questions component keeps its root Blade view
// (`livewire.pages.dashboard.events.registration-questions`) while its class
// and route live here.
Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('/dashboard/majlis/{event}/peserta/export', [RegistrationExportController::class, 'export'])
        ->name('dashboard.events.participants.export');

    Route::livewire('/dashboard/majlis/{event}/pendaftaran', RegistrationQuestions::class)
        ->name('dashboard.events.registration-questions');
});
