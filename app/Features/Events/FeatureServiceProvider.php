<?php

declare(strict_types=1);

namespace App\Features\Events;

use App\Features\Events\Actions\Authoring\SyncEventLocationsAction;
use App\Features\Events\Actions\Authoring\SyncEventProjectionsAction;
use App\Features\Events\Api\EventAuthoring;
use App\Features\Events\Api\EventLocations;
use App\Features\Events\Api\EventProjections;
use App\Features\Events\Api\EventReportSubjectProvider;
use App\Features\Events\Api\GuestSubmissionClaims;
use App\Features\Events\Api\SeriesAuthoring;
use App\Features\Events\Services\EventAuthoringService;
use App\Features\Events\Services\EventCategoryCatalog;
use App\Features\Events\Services\EventCategoryPolicy;
use App\Features\Events\Services\EventReportSubjectResolver;
use App\Features\Events\Services\SeriesAuthoringService;
use App\Features\Events\Support\EventModel;
use App\Platform\FeatureModule;
use App\Platform\Routing\PublicSlugRegistry;
use App\Platform\Routing\PublicSlugResource;
use App\Shared\Events\EventCategoryCatalog as EventCategoryCatalogContract;
use App\Shared\Events\EventCategoryPolicyResolver;

/**
 * Events owns event authoring, submissions, registration, and attendance.
 *
 * Table writers (see feature.json) are the named Actions under
 * Actions/Authoring and Actions/Scheduling. The following external writers
 * remain intentional during the slice-by-slice conversion:
 *
 * - events package: SyncPrimaryEventOccurrenceAction (schedule_kind through
 *   the feature scheduling adapter), lifecycle state machine/transitions.
 * - root SaveAdminEventAction: interim moderation-status adapter (E3).
 * - root event create/submission actions: `PersistOccurrenceSubmissionAction`
 *   and `PersistSessionSubmissionAction` still write scoped event_audiences
 *   rows until E2; `CreateEventWithInitialOccurrenceAction` /
 *   `CreateEventWithRegistrationAction` remain E4 create paths.
 * - root Event model: booted saved/deleting hooks delegate projections,
 *   audiences, languages, locations and organizer writes to feature actions.
 * - event_taxonomies/event_terms are created by the package synchronizer that
 *   SyncEventClassificationsAction invokes.
 * - vendor Filament event resources (until E4) and events package cascades
 *   remain external writers for series; app-owned series authoring and
 *   deletion go through the SeriesAuthoring boundary.
 */
class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(
            GuestSubmissionClaims::class,
            (string) config('events.bindings.guest_submission_claims'),
        );
        $this->app->bind(EventReportSubjectProvider::class, EventReportSubjectResolver::class);

        $this->app->bind(EventAuthoring::class, EventAuthoringService::class);
        $this->app->bind(EventLocations::class, SyncEventLocationsAction::class);
        $this->app->bind(EventProjections::class, SyncEventProjectionsAction::class);
        $this->app->bind(SeriesAuthoring::class, SeriesAuthoringService::class);

        $this->app->singleton(EventCategoryCatalogContract::class, EventCategoryCatalog::class);
        $this->app->singleton(EventCategoryPolicyResolver::class, EventCategoryPolicy::class);
    }

    public function boot(): void
    {
        parent::boot();

        $this->app->make(PublicSlugRegistry::class)->prepend(new PublicSlugResource(
            parameter: 'event',
            routeName: 'events.show',
            modelClass: EventModel::event(),
            requiresVisitedSource: false,
            searchColumns: ['title', 'slug'],
            labelAttribute: 'title',
        ));
    }
}
