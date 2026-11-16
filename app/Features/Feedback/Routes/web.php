<?php

declare(strict_types=1);

use App\Features\Feedback\EntryPoints\Http\AppFeedbackEvidenceController;
use App\Features\Feedback\EntryPoints\Livewire\Pages\AppFeedbackIndex;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::livewire('/maklum-balas', AppFeedbackIndex::class)->name('app-feedback.index');
    Route::get('/maklum-balas/{feedback}/bukti/{mediaId}', AppFeedbackEvidenceController::class)
        ->name('app-feedback.evidence.download');
});
