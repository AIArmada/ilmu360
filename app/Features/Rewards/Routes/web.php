<?php

declare(strict_types=1);

use App\Features\Rewards\EntryPoints\Livewire\Pages\Dashboard\RewardMemberIndex;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::livewire('/dashboard/rewards', RewardMemberIndex::class)->name('dashboard.rewards');
});
