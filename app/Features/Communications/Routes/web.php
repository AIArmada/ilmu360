<?php

declare(strict_types=1);

use App\Features\Communications\EntryPoints\Http\LocaleController;
use App\Features\Communications\EntryPoints\Livewire\NotificationsIndex;
use Illuminate\Support\Facades\Route;

Route::middleware('web')
    ->get('/bahasa/{locale}', LocaleController::class)
    ->name('locale.switch');

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::livewire('/notifications', NotificationsIndex::class)
        ->name('notifications');
});
