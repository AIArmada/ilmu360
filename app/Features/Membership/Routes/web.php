<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Membership web transport.
//
// The Livewire page classes intentionally remain root adapters in
// App\Livewire\Pages\Membership and App\Livewire\Pages\MembershipApplications
// because they compose root Directory/Events models, the root User account,
// the root MemberSubjectType UI mapping and the root membership presenter.
// The feature owns the URLs, names and auth boundary; Livewire's App\Livewire
// discovery resolves the aliases below, so no explicit Livewire registration
// is required.
//
// The claimable subject constraint mirrors
// App\Enums\MemberSubjectType::claimableRouteSegments(). The route file cannot
// import that root UI enum without crossing the feature boundary; keep these
// public route segments in sync when the claimable subject set changes.
Route::middleware(['web', 'auth'])->group(function (): void {
    Route::livewire('/jemputan-ahli/{token}', 'pages.membership.show-invitation')
        ->name('member-invitations.show');
    Route::livewire('/permohonan-keahlian', 'pages.membership-applications.index')
        ->name('membership-applications.index');
    Route::livewire('/pohon-keahlian/{subjectType}/{subjectId}', 'pages.membership-applications.create')
        ->whereIn('subjectType', ['institusi', 'penceramah'])
        ->name('membership-applications.create');
});
