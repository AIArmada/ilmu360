<?php

declare(strict_types=1);

use App\Features\Feedback\EntryPoints\Http\AppFeedbackEvidenceController;
use Illuminate\Support\Facades\Route;

// The page component keeps its established Livewire alias, registered in
// FeatureServiceProvider, so existing component references stay stable.
Route::middleware(['web', 'auth'])->group(function (): void {
    Route::livewire('/maklum-balas', 'pages.app-feedback.index')->name('app-feedback.index');
    Route::get('/maklum-balas/{feedback}/bukti/{mediaId}', AppFeedbackEvidenceController::class)
        ->name('app-feedback.evidence.download');
});
