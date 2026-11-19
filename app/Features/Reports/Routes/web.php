<?php

declare(strict_types=1);

use App\Features\Reports\Api\ReportSubjectType;
use App\Features\Reports\EntryPoints\Livewire\Pages\Reports\Create;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::livewire('/lapor/{subjectType}/{subjectId}', Create::class)
        ->whereIn('subjectType', ReportSubjectType::publicRouteSegments())
        ->name('reports.create');
});
