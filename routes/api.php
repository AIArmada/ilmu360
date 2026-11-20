<?php

declare(strict_types=1);

use App\Features\Contributions\Api\Enums\ContributionSubjectType;
use App\Features\Contributions\EntryPoints\Http\ContributionController;
use App\Features\Contributions\EntryPoints\Http\ContributionRequestReviewController as AdminContributionRequestReviewController;
use App\Features\Discovery\EntryPoints\Http\EventSearchController;
use App\Features\Discovery\EntryPoints\Http\SearchController;
use App\Features\Engagement\EntryPoints\Http\EventGoingController;
use App\Features\Engagement\EntryPoints\Http\EventSaveController;
use App\Features\Engagement\EntryPoints\Http\FollowController;
use App\Features\Events\EntryPoints\Http\AdvancedEventController;
use App\Features\Events\EntryPoints\Http\EventController;
use App\Features\Events\EntryPoints\Http\EventModerationController as AdminEventModerationController;
use App\Features\Events\EntryPoints\Http\EventSubmissionController;
use App\Features\Events\EntryPoints\Http\SeriesDirectoryController;
use App\Features\Identity\EntryPoints\Http\AccountSettingsMcpTokenController;
use App\Features\Institutions\EntryPoints\Http\InstitutionDirectoryController;
use App\Features\Institutions\EntryPoints\Http\InstitutionWorkspaceController;
use App\Features\Membership\Api\MembershipApplicationSubjectType;
use App\Features\Persons\EntryPoints\Http\PersonDirectoryController;
use App\Features\Places\EntryPoints\Http\VenueDirectoryController;
use App\Features\References\EntryPoints\Http\ReferenceDirectoryController;
use App\Features\Registration\EntryPoints\Http\EventCheckInController;
use App\Features\Registration\EntryPoints\Http\EventRegistrationController;
use App\Features\Registration\EntryPoints\Http\UserRegistrationController;
use App\Features\Reports\EntryPoints\Http\ReportTriageController as AdminReportTriageController;
use App\Features\ShareTracking\EntryPoints\Http\ShareAnalyticsController;
use App\Features\ShareTracking\EntryPoints\Http\ShareClaimController;
use App\Features\ShareTracking\EntryPoints\Http\ShareController;
use App\Platform\Api\Admin\EntryPoints\Http\CatalogController as AdminCatalogController;
use App\Platform\Api\Admin\EntryPoints\Http\ManifestController as AdminManifestController;
use App\Platform\Api\Admin\EntryPoints\Http\ResourceController as AdminResourceController;
use App\Platform\Api\Frontend\EntryPoints\Http\CatalogController;
use App\Platform\Api\Frontend\EntryPoints\Http\ManifestController;
use App\Platform\ApiDocumentation\EntryPoints\Http\DocumentationController;
use App\Platform\Http\Middleware\EnsureAdminApiAccess;
use App\Platform\Telemetry\EntryPoints\Http\MobileTelemetryController;
use Illuminate\Support\Facades\Route;

// Public API endpoints
Route::prefix('v1')->group(function () {
    Route::name('api.client.')->group(function () {
        Route::get('/manifest', [ManifestController::class, 'manifest'])->name('manifest');

        Route::prefix('documentation')->name('documentation.')->group(function (): void {
            Route::get('/', [DocumentationController::class, 'index'])->name('index');
            Route::get('/{documentId}', [DocumentationController::class, 'show'])->name('show');
        });

        Route::prefix('forms')->name('forms.')->group(function () {
            Route::get('/mobile-telemetry', [ManifestController::class, 'mobileTelemetry'])->name('mobile-telemetry');
            Route::get('/submit-event', [ManifestController::class, 'submitEvent'])->name('submit-event');
            Route::get('/contributions/institutions', [ManifestController::class, 'submitInstitution'])->name('contributions.institutions');
            Route::get('/contributions/persons', [ManifestController::class, 'submitPerson'])->name('contributions.persons');
        });

        Route::prefix('catalogs')->name('catalogs.')->group(function () {
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

        Route::get('/search', [SearchController::class, 'search'])->name('search.index');
        Route::get('/share/payload', [ShareController::class, 'payload'])
            ->middleware('throttle:share-tracking')
            ->name('share.payload');
        Route::post('/share/track', [ShareController::class, 'track'])
            ->middleware('throttle:30,1')
            ->name('share.track');
        Route::get('/share/claim', [ShareClaimController::class, 'availability'])
            ->middleware('auth:sanctum')
            ->name('share.claim.availability');
        Route::post('/share/claim', [ShareClaimController::class, 'claim'])
            ->middleware(['auth:sanctum', 'throttle:share-claim'])
            ->name('share.claim');
        Route::post('/mobile/telemetry/events', [MobileTelemetryController::class, 'store'])
            ->middleware('throttle:mobile-telemetry')
            ->name('mobile-telemetry.store');
        Route::get('/institutions', [InstitutionDirectoryController::class, 'institutions'])->name('institutions.index');
        Route::get('/institutions/near', [InstitutionDirectoryController::class, 'institutionsNear'])->name('institutions.near');
        Route::get('/institutions/{institutionKey}', [InstitutionDirectoryController::class, 'showInstitution'])->name('institutions.show');
        Route::get('/persons', [PersonDirectoryController::class, 'persons'])->name('persons.index');
        Route::get('/persons/{personKey}', [PersonDirectoryController::class, 'showPerson'])->name('persons.show');
        Route::get('/venues/{venueKey}', [VenueDirectoryController::class, 'showVenue'])->name('venues.show');
        Route::get('/references', [ReferenceDirectoryController::class, 'references'])->name('references.index');
        Route::get('/references/{referenceKey}', [ReferenceDirectoryController::class, 'showReference'])->name('references.show');
        Route::get('/series/{series}', [SeriesDirectoryController::class, 'showSeries'])->name('series.show');
        Route::post('/submit-event', [EventSubmissionController::class, 'store'])
            ->middleware('throttle:event-submission')
            ->name('submit-event.store');
    });

    // Events API with query builder
    Route::get('/events', [EventController::class, 'index'])->name('api.events.index');
    Route::get('/events/{event}', [EventController::class, 'show'])->name('api.events.show');
    Route::post('/events/{event}/registrations', [EventRegistrationController::class, 'store'])
        ->middleware('throttle:registration')
        ->name('api.events.registrations.store');

});

// Authenticated API endpoints
Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::prefix('admin')
        ->middleware(EnsureAdminApiAccess::class)
        ->name('api.admin.')
        ->group(function (): void {
            Route::get('/manifest', AdminManifestController::class)->name('manifest');
            Route::prefix('catalogs')->name('catalogs.')->group(function (): void {
                Route::get('/countries', [AdminCatalogController::class, 'countries'])->name('countries');
                Route::get('/states', [AdminCatalogController::class, 'states'])->name('states');
                Route::get('/cities', [AdminCatalogController::class, 'cities'])->name('cities');
                Route::get('/administrative-districts', [AdminCatalogController::class, 'administrativeDistricts'])->name('administrative-districts');
                Route::get('/administrative-subdivisions', [AdminCatalogController::class, 'administrativeSubdivisions'])->name('administrative-subdivisions');
            });
            Route::get('/events/search', [EventSearchController::class, 'search'])->name('events.search');
            Route::get('/{resourceKey}', [AdminResourceController::class, 'indexRecords'])->name('resources.index');
            Route::post('/{resourceKey}', [AdminResourceController::class, 'storeRecord'])->name('resources.store');
            Route::post('/{resourceKey}/batch', [AdminResourceController::class, 'batchStoreRecords'])->name('resources.batch-store');
            Route::put('/{resourceKey}/batch', [AdminResourceController::class, 'batchUpdateRecords'])->name('resources.batch-update');
            Route::get('/{resourceKey}/meta', [AdminResourceController::class, 'show'])->name('resources.meta');
            Route::get('/{resourceKey}/schema', [AdminResourceController::class, 'schema'])->name('resources.schema');
            Route::get('/events/{recordKey}/moderation-schema', [AdminEventModerationController::class, 'schema'])->name('events.moderation-schema');
            Route::post('/events/{recordKey}/moderate', [AdminEventModerationController::class, 'moderate'])->name('events.moderate');
            Route::get('/reports/{recordKey}/triage-schema', [AdminReportTriageController::class, 'schema'])->name('reports.triage-schema');
            Route::post('/reports/{recordKey}/triage', [AdminReportTriageController::class, 'triage'])->name('reports.triage');
            Route::get('/contribution-requests/{recordKey}/review-schema', [AdminContributionRequestReviewController::class, 'schema'])->name('contribution-requests.review-schema');
            Route::post('/contribution-requests/{recordKey}/review', [AdminContributionRequestReviewController::class, 'review'])->name('contribution-requests.review');
            Route::get('/{resourceKey}/{recordKey}/relations/{relation}', [AdminResourceController::class, 'relatedRecords'])->name('resources.related');
            Route::get('/{resourceKey}/{recordKey}', [AdminResourceController::class, 'showRecord'])->name('resources.show');
            Route::put('/{resourceKey}/{recordKey}', [AdminResourceController::class, 'updateRecord'])->name('resources.update');
        });

    Route::name('api.client.')->group(function () {
        Route::prefix('share')->name('share.')->group(function (): void {
            Route::get('/analytics', [ShareAnalyticsController::class, 'index'])->name('analytics');
            Route::get('/analytics/links/{link}', [ShareAnalyticsController::class, 'show'])->name('analytics.links.show');
        });

        Route::prefix('forms')->name('forms.')->group(function () {
            Route::get('/report', [ManifestController::class, 'report'])->name('report');
            Route::get('/github-issue-report', [ManifestController::class, 'githubIssueReport'])->name('github-issue-report');
            Route::get('/account-settings', [ManifestController::class, 'accountSettings'])->name('account-settings');
            Route::get('/advanced-events', [ManifestController::class, 'advancedEvent'])->name('advanced-events');
            Route::get('/institution-workspace', [ManifestController::class, 'institutionWorkspace'])->name('institution-workspace');
            Route::get('/membership-applications/{subjectType}', [ManifestController::class, 'membershipApplication'])
                ->whereIn('subjectType', MembershipApplicationSubjectType::routeSegments())
                ->name('membership-applications');
            Route::get('/contributions/{subjectType}/{subject}/suggest', [ContributionController::class, 'suggestContext'])
                ->whereIn('subjectType', ContributionSubjectType::publicRouteSegments())
                ->name('contributions.suggest');
        });

        Route::prefix('catalogs')->name('catalogs.')->group(function () {
            Route::get('/institution-roles', [CatalogController::class, 'institutionRoles'])->name('institution-roles');
        });

        Route::get('/account-settings/mcp-tokens', [AccountSettingsMcpTokenController::class, 'index'])->name('account-settings.mcp-tokens.index');
        Route::post('/account-settings/mcp-tokens', [AccountSettingsMcpTokenController::class, 'store'])->name('account-settings.mcp-tokens.store');
        Route::delete('/account-settings/mcp-tokens/{tokenId}', [AccountSettingsMcpTokenController::class, 'destroy'])->name('account-settings.mcp-tokens.destroy');

        Route::get('/contributions', [ContributionController::class, 'index'])->name('contributions.index');
        Route::post('/contributions/institutions', [ContributionController::class, 'storeInstitution'])->name('contributions.institutions.store');
        Route::post('/contributions/persons', [ContributionController::class, 'storePerson'])->name('contributions.persons.store');
        Route::post('/contributions/{subjectType}/{subject}/suggest', [ContributionController::class, 'suggestUpdate'])
            ->whereIn('subjectType', ContributionSubjectType::publicRouteSegments())
            ->name('contributions.suggest.store');
        Route::post('/contributions/{requestId}/approve', [ContributionController::class, 'approve'])->name('contributions.approve');
        Route::post('/contributions/{requestId}/reject', [ContributionController::class, 'reject'])->name('contributions.reject');
        Route::post('/contributions/{requestId}/cancel', [ContributionController::class, 'cancel'])->name('contributions.cancel');

        Route::post('/advanced-events', [AdvancedEventController::class, 'store'])->name('advanced-events.store');

        Route::get('/follows/{type}/{subject}', [FollowController::class, 'show'])
            ->whereIn('type', ['institution', 'person', 'reference', 'series'])
            ->name('follows.show');
        Route::post('/follows/{type}/{subject}', [FollowController::class, 'store'])
            ->whereIn('type', ['institution', 'person', 'reference', 'series'])
            ->name('follows.store');
        Route::delete('/follows/{type}/{subject}', [FollowController::class, 'destroy'])
            ->whereIn('type', ['institution', 'person', 'reference', 'series'])
            ->name('follows.destroy');

        Route::get('/institution-workspace', [InstitutionWorkspaceController::class, 'show'])->name('institution-workspace.show');
        Route::post('/institution-workspace/{institutionId}/members', [InstitutionWorkspaceController::class, 'addMember'])->name('institution-workspace.members.store');
        Route::put('/institution-workspace/{institutionId}/members/{memberId}', [InstitutionWorkspaceController::class, 'updateMemberRole'])->name('institution-workspace.members.update');
        Route::delete('/institution-workspace/{institutionId}/members/{memberId}', [InstitutionWorkspaceController::class, 'removeMember'])->name('institution-workspace.members.destroy');
    });

    Route::get('/user/registrations', [UserRegistrationController::class, 'index'])
        ->name('api.user.registrations.index');
    Route::get('/me/events/going', [EventGoingController::class, 'index'])
        ->name('api.events.going.index');
    Route::get('/me/events/saved', [EventSaveController::class, 'index'])
        ->name('api.events.saved.index');

    Route::get('/events/{event}/me', [EventController::class, 'me'])
        ->name('api.events.me.show');
    Route::post('/events/{event}/check-ins', [EventCheckInController::class, 'store'])
        ->name('api.events.check-ins.store');
    Route::put('/events/{event}/going', [EventGoingController::class, 'store'])
        ->name('api.events.going.update');
    Route::delete('/events/{event}/going', [EventGoingController::class, 'destroy'])
        ->name('api.events.going.destroy');

    // Event Saves / Bookmarks (per documentation B5)
    Route::put('/events/{event}/saved', [EventSaveController::class, 'store'])->name('api.events.saved.update');
    Route::delete('/events/{event}/saved', [EventSaveController::class, 'destroy'])->name('api.events.saved.destroy');

});
