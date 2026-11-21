<?php

declare(strict_types=1);

use App\Features\Discovery\EntryPoints\Http\CatalogController;
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
