<?php

declare(strict_types=1);

use App\Enums\ContributionSubjectType;
use App\Enums\MemberSubjectType;
use App\Http\Controllers\Api\Admin\CatalogController as AdminCatalogController;
use App\Http\Controllers\Api\Admin\ContributionRequestReviewController as AdminContributionRequestReviewController;
use App\Http\Controllers\Api\Admin\EventModerationController as AdminEventModerationController;
use App\Http\Controllers\Api\Admin\EventSearchController;
use App\Http\Controllers\Api\Admin\ManifestController as AdminManifestController;
use App\Http\Controllers\Api\Admin\MembershipApplicationReviewController as AdminMembershipApplicationReviewController;
use App\Http\Controllers\Api\Admin\ReportTriageController as AdminReportTriageController;
use App\Http\Controllers\Api\Admin\ResourceController as AdminResourceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CurrentUserController;
use App\Http\Controllers\Api\Documentation\DocumentationController;
use App\Http\Controllers\Api\EventCheckInController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\EventGoingController;
use App\Http\Controllers\Api\EventRegistrationController;
use App\Http\Controllers\Api\EventSaveController;
use App\Http\Controllers\Api\Frontend\AccountSettingsController;
use App\Http\Controllers\Api\Frontend\AccountSettingsMcpTokenController;
use App\Http\Controllers\Api\Frontend\AdvancedEventController;
use App\Http\Controllers\Api\Frontend\CatalogController;
use App\Http\Controllers\Api\Frontend\ContributionController;
use App\Http\Controllers\Api\Frontend\EventSubmissionController;
use App\Http\Controllers\Api\Frontend\FollowController;
use App\Http\Controllers\Api\Frontend\GitHubIssueController;
use App\Http\Controllers\Api\Frontend\InstitutionWorkspaceController;
use App\Http\Controllers\Api\Frontend\ManifestController;
use App\Http\Controllers\Api\Frontend\MembershipApplicationController;
use App\Http\Controllers\Api\Frontend\MobileTelemetryController;
use App\Http\Controllers\Api\Frontend\SearchController;
use App\Http\Controllers\Api\Frontend\ShareAnalyticsController;
use App\Http\Controllers\Api\NotificationDestinationController;
use App\Http\Controllers\Api\NotificationMessageController;
use App\Http\Controllers\Api\NotificationSettingsController;
use App\Http\Controllers\Api\RegistrationExportController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SavedSearchController;
use App\Http\Controllers\Api\UserRegistrationController;
use App\Http\Controllers\DawahShareController;
use App\Http\Middleware\EnsureAdminApiAccess;
use Illuminate\Support\Facades\Route;

// Public API endpoints
Route::prefix('v1')->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register'])
        ->middleware('throttle:api-auth-register')
        ->name('api.auth.register');
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:api-auth-login')
        ->name('api.auth.login');
    Route::post('/auth/social/google', [AuthController::class, 'google'])
        ->middleware('throttle:api-auth-social')
        ->name('api.auth.social.google');
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])
        ->middleware('throttle:api-auth-password')
        ->name('api.auth.forgot-password');
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])
        ->middleware('throttle:api-auth-password')
        ->name('api.auth.reset-password');

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
            Route::get('/admin-area-level-1', [CatalogController::class, 'adminAreaLevel1'])->name('admin-area-level-1');
            Route::get('/admin-area-level-2', [CatalogController::class, 'adminAreaLevel2'])->name('admin-area-level-2');
            Route::get('/languages', [CatalogController::class, 'languages'])->name('languages');
            Route::get('/taxonomy-terms/{type}', [CatalogController::class, 'taxonomyTerms'])->name('taxonomy-terms');
            Route::get('/tags/{type}', [CatalogController::class, 'tags'])->name('tags');
            Route::get('/references', [CatalogController::class, 'references'])->name('references');
            Route::get('/submit-institutions', [CatalogController::class, 'submitInstitutions'])->name('submit-institutions');
            Route::get('/submit-persons', [CatalogController::class, 'submitPersons'])->name('submit-persons');
            Route::get('/venues', [CatalogController::class, 'venues'])->name('venues');
            Route::get('/spaces', [CatalogController::class, 'spaces'])->name('spaces');
            Route::get('/membership-application-subjects/{subjectType}', [CatalogController::class, 'membershipClaimSubjects'])
                ->whereIn('subjectType', MemberSubjectType::claimableRouteSegments())
                ->name('membership-application-subjects');
            Route::get('/prayer-institutions', [CatalogController::class, 'prayerInstitutions'])->name('prayer-institutions');
        });

        Route::get('/search', [SearchController::class, 'search'])->name('search.index');
        Route::get('/share/payload', [DawahShareController::class, 'payload'])
            ->middleware('throttle:share-tracking')
            ->name('share.payload');
        Route::post('/share/track', [DawahShareController::class, 'track'])
            ->middleware('throttle:30,1')
            ->name('share.track');
        Route::post('/mobile/telemetry/events', [MobileTelemetryController::class, 'store'])
            ->middleware('throttle:mobile-telemetry')
            ->name('mobile-telemetry.store');
        Route::get('/institutions', [SearchController::class, 'institutions'])->name('institutions.index');
        Route::get('/institutions/near', [SearchController::class, 'institutionsNear'])->name('institutions.near');
        Route::get('/institutions/{institutionKey}', [SearchController::class, 'showInstitution'])->name('institutions.show');
        Route::get('/persons', [SearchController::class, 'persons'])->name('persons.index');
        Route::get('/persons/{personKey}', [SearchController::class, 'showPerson'])->name('persons.show');
        Route::get('/inspirations/random', [SearchController::class, 'randomInspiration'])->name('inspirations.random');
        Route::get('/venues/{venueKey}', [SearchController::class, 'showVenue'])->name('venues.show');
        Route::get('/references', [SearchController::class, 'references'])->name('references.index');
        Route::get('/references/{referenceKey}', [SearchController::class, 'showReference'])->name('references.show');
        Route::get('/series/{series}', [SearchController::class, 'showSeries'])->name('series.show');
        Route::post('/submit-event', [EventSubmissionController::class, 'store'])->name('submit-event.store');
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
                Route::get('/admin-area-level-1', [AdminCatalogController::class, 'adminAreaLevel1'])->name('admin-area-level-1');
                Route::get('/admin-area-level-2', [AdminCatalogController::class, 'adminAreaLevel2'])->name('admin-area-level-2');
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
            Route::get('/membership-applications/{recordKey}/review-schema', [AdminMembershipApplicationReviewController::class, 'schema'])->name('membership-applications.review-schema');
            Route::post('/membership-applications/{recordKey}/review', [AdminMembershipApplicationReviewController::class, 'review'])->name('membership-applications.review');
            Route::get('/{resourceKey}/{recordKey}/relations/{relation}', [AdminResourceController::class, 'relatedRecords'])->name('resources.related');
            Route::get('/{resourceKey}/{recordKey}', [AdminResourceController::class, 'showRecord'])->name('resources.show');
            Route::put('/{resourceKey}/{recordKey}', [AdminResourceController::class, 'updateRecord'])->name('resources.update');
        });

    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');
    Route::post('/auth/email/verification-notification', [AuthController::class, 'resendVerificationEmail'])
        ->name('api.auth.verification-notification');

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
            Route::get('/membership-applications/{subjectType}', [ManifestController::class, 'membershipClaim'])
                ->whereIn('subjectType', MemberSubjectType::claimableRouteSegments())
                ->name('membership-applications');
            Route::get('/contributions/{subjectType}/{subject}/suggest', [ContributionController::class, 'suggestContext'])
                ->whereIn('subjectType', ContributionSubjectType::publicRouteSegments())
                ->name('contributions.suggest');
        });

        Route::prefix('catalogs')->name('catalogs.')->group(function () {
            Route::get('/institution-roles', [CatalogController::class, 'institutionRoles'])->name('institution-roles');
        });

        Route::get('/account-settings', [AccountSettingsController::class, 'show'])->name('account-settings.show');
        Route::put('/account-settings', [AccountSettingsController::class, 'update'])->name('account-settings.update');
        Route::get('/account-settings/mcp-tokens', [AccountSettingsMcpTokenController::class, 'index'])->name('account-settings.mcp-tokens.index');
        Route::post('/account-settings/mcp-tokens', [AccountSettingsMcpTokenController::class, 'store'])->name('account-settings.mcp-tokens.store');
        Route::delete('/account-settings/mcp-tokens/{tokenId}', [AccountSettingsMcpTokenController::class, 'destroy'])->name('account-settings.mcp-tokens.destroy');
        Route::post('/github-issues', [GitHubIssueController::class, 'store'])
            ->middleware('throttle:github-issues')
            ->name('github-issues.store');

        Route::get('/contributions', [ContributionController::class, 'index'])->name('contributions.index');
        Route::post('/contributions/institutions', [ContributionController::class, 'storeInstitution'])->name('contributions.institutions.store');
        Route::post('/contributions/persons', [ContributionController::class, 'storePerson'])->name('contributions.persons.store');
        Route::post('/contributions/{subjectType}/{subject}/suggest', [ContributionController::class, 'suggestUpdate'])
            ->whereIn('subjectType', ContributionSubjectType::publicRouteSegments())
            ->name('contributions.suggest.store');
        Route::post('/contributions/{requestId}/approve', [ContributionController::class, 'approve'])->name('contributions.approve');
        Route::post('/contributions/{requestId}/reject', [ContributionController::class, 'reject'])->name('contributions.reject');
        Route::post('/contributions/{requestId}/cancel', [ContributionController::class, 'cancel'])->name('contributions.cancel');

        Route::get('/membership-applications', [MembershipApplicationController::class, 'index'])->name('membership-applications.index');
        Route::post('/membership-applications/{subjectType}/{subject}', [MembershipApplicationController::class, 'store'])
            ->whereIn('subjectType', MemberSubjectType::claimableRouteSegments())
            ->name('membership-applications.store');
        Route::delete('/membership-applications/{applicationId}', [MembershipApplicationController::class, 'cancel'])->name('membership-applications.cancel');

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

    // Reports (per documentation B5b) - Rate limited
    Route::post('/reports', [ReportController::class, 'store'])
        ->middleware('throttle:reports')
        ->name('api.reports.store');

    // User
    Route::get('/user', CurrentUserController::class)->name('api.user.show');
    Route::delete('/user', [CurrentUserController::class, 'destroy'])->name('api.user.destroy');
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

    // Saved Searches (per documentation B5a)
    Route::apiResource('saved-searches', SavedSearchController::class)
        ->parameters(['saved-searches' => 'savedSearch'])
        ->names('api.saved-searches');
    Route::post('/saved-searches/{savedSearch}/execute', [SavedSearchController::class, 'execute'])
        ->name('api.saved-searches.execute');

    // Event Saves / Bookmarks (per documentation B5)
    Route::put('/events/{event}/saved', [EventSaveController::class, 'store'])->name('api.events.saved.update');
    Route::delete('/events/{event}/saved', [EventSaveController::class, 'destroy'])->name('api.events.saved.destroy');

    // Registration Exports (per documentation B9d)
    Route::get('/events/{event}/registrations/export', [RegistrationExportController::class, 'export'])
        ->name('api.registrations.export');

    // Notification center
    Route::get('/notifications', [NotificationMessageController::class, 'index'])
        ->name('api.notifications.index');
    Route::post('/notifications/{message}/read', [NotificationMessageController::class, 'read'])
        ->name('api.notifications.read');
    Route::post('/notifications/read-all', [NotificationMessageController::class, 'readAll'])
        ->name('api.notifications.read-all');
    Route::get('/notification-settings/catalog', [NotificationSettingsController::class, 'catalog'])
        ->name('api.notification-settings.catalog');
    Route::get('/notification-settings', [NotificationSettingsController::class, 'show'])
        ->name('api.notification-settings.show');
    Route::put('/notification-settings', [NotificationSettingsController::class, 'update'])
        ->name('api.notification-settings.update');
    Route::post('/notification-destinations/push', [NotificationDestinationController::class, 'storePush'])
        ->name('api.notification-destinations.push.store');
    Route::put('/notification-destinations/push/{installation}', [NotificationDestinationController::class, 'updatePush'])
        ->name('api.notification-destinations.push.update');
    Route::delete('/notification-destinations/push/{installation}', [NotificationDestinationController::class, 'destroyPush'])
        ->name('api.notification-destinations.push.destroy');
});
