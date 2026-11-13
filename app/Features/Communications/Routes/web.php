<?php

declare(strict_types=1);

use App\Features\Communications\EntryPoints\Livewire\NotificationsIndex;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::livewire('/notifications', NotificationsIndex::class)
        ->name('notifications');
});
