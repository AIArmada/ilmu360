<?php

declare(strict_types=1);

use App\Features\Membership\Api\MembershipApplicationSubjectType;
use Illuminate\Support\Facades\Route;

// Membership web transport.
//
// Membership owns the application pages and registers their Livewire aliases from
// its feature provider. Invitation acceptance remains an app transport adapter.
//
Route::middleware(['web', 'auth'])->group(function (): void {
    Route::livewire('/jemputan-ahli/{token}', 'pages.membership.show-invitation')
        ->name('member-invitations.show');
    Route::livewire('/permohonan-keahlian', 'pages.membership-applications.index')
        ->name('membership-applications.index');
    Route::livewire('/pohon-keahlian/{subjectType}/{subjectId}', 'pages.membership-applications.create')
        ->whereIn('subjectType', MembershipApplicationSubjectType::routeSegments())
        ->name('membership-applications.create');
});
