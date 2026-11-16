<?php

declare(strict_types=1);

use App\Features\Reports\EntryPoints\Livewire\Pages\Reports\Create;
use App\Shared\Reports\ReportSubjectType;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::livewire('/lapor/{subjectType}/{subjectId}', Create::class)
        ->whereIn('subjectType', ReportSubjectType::publicRouteSegments())
        ->name('reports.create');
});
