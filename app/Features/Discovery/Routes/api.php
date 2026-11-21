<?php

declare(strict_types=1);

use App\Features\Discovery\EntryPoints\Http\CatalogController;
use App\Features\Discovery\EntryPoints\Http\ManifestController;
use App\Features\Membership\Api\MembershipApplicationSubjectType;
use Illuminate\Support\Facades\Route;

Route::middleware('api')
    ->prefix('api/v1/catalogs')
    ->name('api.client.catalogs.')
    ->group(function (): void {
        Route::get('/countries', [CatalogController::class, 'countries'])->name('countries');
        Route::get('/states', [CatalogController::class, 'states'])->name('states');
        Route::get('/cities', [CatalogController::class, 'cities'])->name('cities');
        Route::get('/administrative-districts', [CatalogController::class, 'administrativeDistricts'])->name('administrative-districts');
        Route::get('/administrative-subdivisions', [CatalogController::class, 'administrativeSubdivisions'])->name('administrative-subdivisions');
        Route::get('/languages', [CatalogController::class, 'languages'])->name('languages');
        Route::get('/taxonomy-terms/{type}', [CatalogController::class, 'taxonomyTerms'])->name('taxonomy-terms');
        Route::get('/references', [CatalogController::class, 'references'])->name('references');
        Route::get('/submit-institutions', [CatalogController::class, 'submitInstitutions'])->name('submit-institutions');
        Route::get('/submit-persons', [CatalogController::class, 'submitPersons'])->name('submit-persons');
        Route::get('/reference-authors', [CatalogController::class, 'referenceAuthors'])->name('reference-authors');
        Route::get('/venues', [CatalogController::class, 'venues'])->name('venues');
        Route::get('/spaces', [CatalogController::class, 'spaces'])->name('spaces');
        Route::get('/membership-application-subjects/{subjectType}', [CatalogController::class, 'membershipApplicationSubjects'])
            ->whereIn('subjectType', MembershipApplicationSubjectType::routeSegments())
            ->name('membership-application-subjects');
        Route::get('/prayer-institutions', [CatalogController::class, 'prayerInstitutions'])->name('prayer-institutions');
    });

Route::middleware(['api', 'auth:sanctum'])
    ->prefix('api/v1/catalogs')
    ->name('api.client.catalogs.')
    ->group(function (): void {
        Route::get('/institution-roles', [CatalogController::class, 'institutionRoles'])->name('institution-roles');
    });

Route::middleware('api')
    ->prefix('api/v1')
    ->name('api.client.')
    ->group(function (): void {
        Route::get('/manifest', [ManifestController::class, 'manifest'])->name('manifest');
        Route::prefix('forms')->name('forms.')->group(function (): void {
            Route::get('/mobile-telemetry', [ManifestController::class, 'mobileTelemetry'])->name('mobile-telemetry');
            Route::get('/submit-event', [ManifestController::class, 'submitEvent'])->name('submit-event');
            Route::get('/contributions/institutions', [ManifestController::class, 'submitInstitution'])->name('contributions.institutions');
            Route::get('/contributions/persons', [ManifestController::class, 'submitPerson'])->name('contributions.persons');
        });
    });

Route::middleware(['api', 'auth:sanctum'])
    ->prefix('api/v1/forms')
    ->name('api.client.forms.')
    ->group(function (): void {
        Route::get('/report', [ManifestController::class, 'report'])->name('report');
        Route::get('/github-issue-report', [ManifestController::class, 'githubIssueReport'])->name('github-issue-report');
        Route::get('/account-settings', [ManifestController::class, 'accountSettings'])->name('account-settings');
        Route::get('/advanced-events', [ManifestController::class, 'advancedEvent'])->name('advanced-events');
        Route::get('/institution-workspace', [ManifestController::class, 'institutionWorkspace'])->name('institution-workspace');
        Route::get('/membership-applications/{subjectType}', [ManifestController::class, 'membershipApplication'])
            ->whereIn('subjectType', MembershipApplicationSubjectType::routeSegments())
            ->name('membership-applications');
    });
