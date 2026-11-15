<?php

declare(strict_types=1);

use App\Features\Membership\Api\MembershipApplicationSubjectType;
use App\Features\Membership\EntryPoints\Http\MembershipApplicationController;
use App\Features\Membership\EntryPoints\Http\MembershipApplicationReviewController;
use App\Platform\Http\Middleware\EnsureAdminApiAccess;
use Illuminate\Support\Facades\Route;

Route::middleware(['api', 'auth:sanctum'])
    ->prefix('api/v1')
    ->name('api.client.')
    ->group(function (): void {
        Route::get('/membership-applications', [MembershipApplicationController::class, 'index'])
            ->name('membership-applications.index');
        Route::post('/membership-applications/{subjectType}/{subject}', [MembershipApplicationController::class, 'store'])
            ->whereIn('subjectType', MembershipApplicationSubjectType::routeSegments())
            ->name('membership-applications.store');
        Route::delete('/membership-applications/{applicationId}', [MembershipApplicationController::class, 'cancel'])
            ->name('membership-applications.cancel');
    });

Route::middleware(['api', 'auth:sanctum', EnsureAdminApiAccess::class])
    ->prefix('api/v1/admin')
    ->name('api.admin.')
    ->group(function (): void {
        Route::get('/membership-applications/{recordKey}/review-schema', [MembershipApplicationReviewController::class, 'schema'])
            ->name('membership-applications.review-schema');
        Route::post('/membership-applications/{recordKey}/review', [MembershipApplicationReviewController::class, 'review'])
            ->name('membership-applications.review');
    });
