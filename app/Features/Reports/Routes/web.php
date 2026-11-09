<?php

declare(strict_types=1);

use App\Shared\Reports\ReportSubjectType;
use Illuminate\Support\Facades\Route;

// Feature-owned web routes (FeatureModule auto-loads this file). The Livewire
// alias 'pages.reports.create' is registered explicitly in
// FeatureServiceProvider so the established component alias keeps resolving.
Route::middleware(['web', 'auth'])->group(function (): void {
    Route::livewire('/lapor/{subjectType}/{subjectId}', 'pages.reports.create')
        ->whereIn('subjectType', ReportSubjectType::publicRouteSegments())
        ->name('reports.create');
});
