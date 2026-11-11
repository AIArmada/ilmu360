<?php

declare(strict_types=1);

namespace App\Features\Events;

use App\Features\Events\Actions\Authoring\SyncEventLocationsAction;
use App\Features\Events\Actions\Authoring\SyncEventProjectionsAction;
use App\Features\Events\Actions\Delete\CleanupEventAction;
use App\Features\Events\Actions\Submission\ClaimGuestSubmissionsAction;
use App\Features\Events\Api\EventAuthoring;
use App\Features\Events\Api\EventDeletion;
use App\Features\Events\Api\EventLocations;
use App\Features\Events\Api\EventModeration;
use App\Features\Events\Api\EventProjections;
use App\Features\Events\Api\EventReportSubjectProvider;
use App\Features\Events\Api\EventSubmissions;
use App\Features\Events\Api\GuestSubmissionClaims;
use App\Features\Events\Api\SeriesAuthoring;
use App\Features\Events\Services\EventAuthoringService;
use App\Features\Events\Services\EventCategoryCatalog;
use App\Features\Events\Services\EventCategoryPolicy;
use App\Features\Events\Services\EventReportSubjectResolver;
use App\Features\Events\Services\EventSubmissionsService;
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
 * Actions/Authoring, Actions/Scheduling and Actions/Submission. The following
 * external writers remain intentional during the slice-by-slice conversion:
 *
 * - events package: SyncPrimaryEventOccurrenceAction (schedule_kind through
 *   the feature scheduling adapter), CreateEventOccurrenceAction /
 *   CreateEventSessionAction / SyncEventLanguagesAction invoked by the
 *   submission persist actions, and lifecycle state machine/transitions.
 * - root SaveAdminEventAction: interim moderation-status adapter (E3b); E3a
 *   exposes Events\Api\EventModeration for admin/MCP/queue callers.
 * - root submission orchestrators: `SubmitFrontendEventAction` validates and
 *   persists through the EventSubmissions boundary, then delegates completion
 *   to `CompleteFrontendEventSubmissionAction`. That adapter keeps moderation
 *   (root ModerationService until E3b), share outcomes and occurrence-review
 *   notifications; guest contacts, registration mode and access-policy writes
 *   go through CompleteEventSubmissionWritesAction.
 * - root `ModerateEventOccurrenceSubmissionAction` writes `event_submissions`
 *   status until the occurrence moderation slice; `event_submission_logs` and
 *   `event_submission_attachments` stay unclaimed for the same reason (their
 *   only app-side writer is that root action).
 * - root Event model: booted saved/deleting hooks delegate projections,
 *   audiences, languages, locations, organizer writes and deletion cleanup
 *   to feature actions. The `deleted` hook keeps the vendor
 *   EventDeleteCascade for the remaining event-scoped package tables.
 *   Deletion cleanup deliberately excludes share-outcome reversal,
 *   contribution mapping, registration cascade changes and engagement-counter
 *   updates; those stay with their own feature migrations.
 * - event_taxonomies/event_terms are created by the package synchronizer that
 *   SyncEventClassificationsAction invokes.
 * - admission setup (registration/pricing modes, access policy, occurrence
 *   ticket types and optional seating) is written by
 *   ConfigureEventAdmissionAction. Ticket, seating and occurrence tables stay
 *   package-canonical and unclaimed.
 * - vendor Filament event resources (until E4) and events package cascades
 *   remain external writers for series; app-owned series authoring and
 *   deletion go through the SeriesAuthoring boundary.
 * - the E4a schedule workspace writes occurrences/sessions through
 *   Actions/Schedule (SaveOccurrenceAction, SaveSessionAction), but
 *   `event_occurrences`/`event_sessions` stay unclaimed in feature.json: vendor
 *   Filament schedule pages, package actions invoked by submission persist
 *   actions and root Event observers still write them. E4b claims those tables
 *   after the Filament/observer cutover.
 */
class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(GuestSubmissionClaims::class, ClaimGuestSubmissionsAction::class);
        $this->app->bind(EventReportSubjectProvider::class, EventReportSubjectResolver::class);

        $this->app->bind(EventAuthoring::class, EventAuthoringService::class);
        $this->app->bind(EventDeletion::class, CleanupEventAction::class);
        $this->app->bind(EventSubmissions::class, EventSubmissionsService::class);
        $this->app->bind(EventLocations::class, SyncEventLocationsAction::class);
        $this->app->bind(EventProjections::class, SyncEventProjectionsAction::class);
        $this->app->bind(SeriesAuthoring::class, SeriesAuthoringService::class);

        $moderationAdapter = config('events.moderation.adapter');

        if (! is_string($moderationAdapter) || ! is_a($moderationAdapter, EventModeration::class, true)) {
            throw new \InvalidArgumentException(sprintf(
                'The [events.moderation.adapter] config must be a class-string implementing [%s].',
                EventModeration::class,
            ));
        }

        $this->app->bind(EventModeration::class, $moderationAdapter);

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
