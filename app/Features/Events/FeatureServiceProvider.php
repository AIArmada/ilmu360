<?php

declare(strict_types=1);

namespace App\Features\Events;

use App\Features\Events\Actions\Authoring\CreateEventWithInitialOccurrenceAction;
use App\Features\Events\Actions\Authoring\CreateEventWithRegistrationAction;
use App\Features\Events\Actions\Authoring\PrepareAdvancedParentProgramSubmissionAction;
use App\Features\Events\Actions\Authoring\ResolveAdvancedBuilderContextAction;
use App\Features\Events\Actions\Authoring\ResolveAdvancedBuilderMembershipOptionsAction;
use App\Features\Events\Actions\Authoring\SyncEventLocationsAction;
use App\Features\Events\Actions\Authoring\SyncEventProjectionsAction;
use App\Features\Events\Actions\Commerce\RecordTicketAffiliateCommissionsAction;
use App\Features\Events\Actions\Commerce\ReverseTicketAffiliateCommissionAction;
use App\Features\Events\Actions\Delete\CleanupEventAction;
use App\Features\Events\Actions\Delete\GuardEventOccurrenceDeletionAction;
use App\Features\Events\Actions\ResolveRegistrationExportContextAction;
use App\Features\Events\Actions\Submission\ClaimGuestSubmissionsAction;
use App\Features\Events\Actions\Submission\ModerateEventOccurrenceSubmissionAction;
use App\Features\Events\Api\AdmissionEventCreator;
use App\Features\Events\Api\AdminEventWriter;
use App\Features\Events\Api\AdvancedEventCreator;
use App\Features\Events\Api\AdvancedBuilderContext;
use App\Features\Events\Api\AdvancedBuilderMembershipOptions;
use App\Features\Events\Api\AdvancedProgramPreparation;
use App\Features\Events\Api\EventAccountOwnership;
use App\Features\Events\Api\EventAuthoring;
use App\Features\Events\Api\EventDeletion;
use App\Features\Events\Api\EventDeletionScope;
use App\Features\Events\Api\EventHomepageStats;
use App\Features\Events\Api\EventImageUploads;
use App\Features\Events\Api\EventInstitutionQuickAddMapsProofStore;
use App\Features\Events\Api\EventInvolvementFingerprint;
use App\Features\Events\Api\EventLocations;
use App\Features\Events\Api\EventMedia;
use App\Features\Events\Api\EventMediaExtraction;
use App\Features\Events\Api\EventModeration;
use App\Features\Events\Api\EventOccurrenceDeletionPreflight;
use App\Features\Events\Api\EventProjections;
use App\Features\Events\Api\EventReportSubjectProvider;
use App\Features\Events\Api\EventSearchCache;
use App\Features\Events\Api\EventShareCardCache;
use App\Features\Events\Api\EventSubmissionOptionsCache;
use App\Features\Events\Api\EventSubmissions;
use App\Features\Events\Api\GuestSubmissionClaims;
use App\Features\Events\Api\OccurrenceSubmissionReviews;
use App\Features\Events\Api\ProductSignalsDashboardCache;
use App\Features\Events\Api\RegistrationExportAccess;
use App\Features\Events\Api\SeriesAuthoring;
use App\Features\Events\Api\TicketAffiliateCommissions;
use App\Features\Events\Api\TicketAffiliateReversals;
use App\Features\Events\EntryPoints\Filament\TermDomainMappingExtension;
use App\Features\Events\Persistence\EventHomepageStatsCacheStore;
use App\Features\Events\Persistence\EventInstitutionQuickAddMapsProofCacheStore;
use App\Features\Events\Persistence\EventInvolvementFingerprintStore;
use App\Features\Events\Persistence\EventSearchCacheStore;
use App\Features\Events\Persistence\EventShareCardCacheStore;
use App\Features\Events\Persistence\EventSubmissionOptionsCacheStore;
use App\Features\Events\Persistence\ProductSignalsDashboardCacheStore;
use App\Features\Events\Services\AdminEventWriterService;
use App\Features\Events\Services\EventAccountOwnershipService;
use App\Features\Events\Services\EventAuthoringService;
use App\Features\Events\Services\EventCategoryCatalog;
use App\Features\Events\Services\EventCategoryPolicy;
use App\Features\Events\Services\EventImageUploadsService;
use App\Features\Events\Services\EventMediaExtractionService;
use App\Features\Events\Services\EventMediaService;
use App\Features\Events\Services\EventReportSubjectResolver;
use App\Features\Events\Services\EventSubmissionsService;
use App\Features\Events\Services\SeriesAuthoringService;
use App\Features\Events\Support\EventModel;
use App\Features\Events\Support\ScopedEventDeletionScope;
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
 * - root SaveAdminEventAction composes initial status changes through
 *   Events\Api\EventModeration. The state machine and moderation effects
 *   remain implemented by the configured Events adapter.
 * - root submission orchestrators: `SubmitFrontendEventAction` validates and
 *   persists through the EventSubmissions boundary, then delegates completion
 *   to `CompleteFrontendEventSubmissionAction`. That adapter composes the
 *   Events moderation API with share outcomes and occurrence-review
 *   notifications; guest contacts, registration mode and access-policy writes
 *   go through CompleteEventSubmissionWritesAction.
 * - occurrence moderation status and review-log writes are owned by
 *   ReviewOccurrenceSubmissionAction behind EventSubmissions. The root
 *   moderation orchestrator keeps its transaction, share-outcome correction,
 *   Signals and after-commit notifications. Submission attachments remain
 *   package-owned and unclaimed.
 * - account lifecycle links are exposed through EventAccountOwnership; the
 *   named account actions own event owner and event submission link writes.
 * - root Event model: booted saved/deleting hooks delegate projections,
 *   audiences, languages, locations, organizer writes and deletion cleanup
 *   to feature actions. CleanupEventAction delegates registration and
 *   check-in cleanup through Registration before the event row is gone. The
 *   vendor EventDeleteCascade runs from the `deleting` hook while the event is
 *   still available to owner-scoped child queries; it removes the remaining
 *   event-scoped package tables before the event row is deleted.
 *   Deletion cleanup deliberately
 *   excludes share-outcome reversal, contribution mapping, registration
 *   cascade changes and engagement-counter updates; those stay with their own
 *   feature migrations.
 * - event_taxonomies/event_terms are created by the package synchronizer that
 *   SyncEventClassificationsAction invokes, and are also written by
 *   EnsureEventTaxonomyTermAction for the contribution form's create-option
 *   flow and SyncEventTermDomainMappingAction for Filament domain mapping.
 * - admission setup (registration/pricing modes, access policy, occurrence
 *   ticket types and optional seating) is written by
 *   ConfigureEventAdmissionAction. Events claims the direct app writes to
 *   occurrences, seat maps, sections, seats and ticket seating options in
 *   feature.json; ticket types remain package-written by the vendor action.
 *   The package models still define their canonical schemas.
 * - app EventResource create/edit pages write through SaveAdminEventAction
 *   and Events\Api\EventAuthoring. Package event relation managers remain
 *   external writers for their own registrations, attendances, occurrences,
 *   sessions and classifications until those admin surfaces are composed.
 *   Vendor term, registration and attendance resources and the check-in
 *   console remain external writers for event_taxonomies/event_terms,
 *   event_registrations and event_attendances/event_attendance_logs. Package
 *   cascades remain external writers for series; app-owned series authoring
 *   and deletion go through the SeriesAuthoring boundary.
 * - the schedule workspace delegates occurrence/session persistence to package
 *   actions. Events claims its direct `event_occurrences` admission writes in
 *   ConfigureEventAdmissionAction; `event_sessions` remains package-owned.
 *   Vendor Filament schedule pages and package submission actions remain
 *   external writers until the admin composition cutover.
 */
class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        config()->set('filament-events.resources.term_form_extensions', [
            ...array_values((array) config('filament-events.resources.term_form_extensions', [])),
            TermDomainMappingExtension::class,
        ]);

        $this->app->bind(GuestSubmissionClaims::class, ClaimGuestSubmissionsAction::class);
        $this->app->bind(EventReportSubjectProvider::class, EventReportSubjectResolver::class);
        $this->app->bind(RegistrationExportAccess::class, ResolveRegistrationExportContextAction::class);

        $this->app->bind(AdvancedBuilderContext::class, ResolveAdvancedBuilderContextAction::class);
        $this->app->bind(AdvancedBuilderMembershipOptions::class, ResolveAdvancedBuilderMembershipOptionsAction::class);
        $this->app->bind(AdvancedProgramPreparation::class, PrepareAdvancedParentProgramSubmissionAction::class);
        $this->app->bind(AdminEventWriter::class, AdminEventWriterService::class);
        $this->app->bind(AdvancedEventCreator::class, CreateEventWithInitialOccurrenceAction::class);
        $this->app->bind(AdmissionEventCreator::class, CreateEventWithRegistrationAction::class);
        $this->app->bind(EventAccountOwnership::class, EventAccountOwnershipService::class);
        $this->app->bind(EventAuthoring::class, EventAuthoringService::class);
        $this->app->bind(EventDeletion::class, CleanupEventAction::class);
        $this->app->bind(EventOccurrenceDeletionPreflight::class, GuardEventOccurrenceDeletionAction::class);
        $this->app->scoped(EventDeletionScope::class, ScopedEventDeletionScope::class);
        $this->app->bind(EventSubmissions::class, EventSubmissionsService::class);
        $this->app->bind(OccurrenceSubmissionReviews::class, ModerateEventOccurrenceSubmissionAction::class);
        $this->app->bind(EventLocations::class, SyncEventLocationsAction::class);
        $this->app->bind(EventMedia::class, EventMediaService::class);
        $this->app->bind(EventMediaExtraction::class, EventMediaExtractionService::class);
        $this->app->bind(EventImageUploads::class, EventImageUploadsService::class);
        $this->app->bind(EventHomepageStats::class, EventHomepageStatsCacheStore::class);
        $this->app->bind(EventInstitutionQuickAddMapsProofStore::class, EventInstitutionQuickAddMapsProofCacheStore::class);
        $this->app->bind(EventInvolvementFingerprint::class, EventInvolvementFingerprintStore::class);
        $this->app->bind(EventShareCardCache::class, EventShareCardCacheStore::class);
        $this->app->bind(EventSearchCache::class, EventSearchCacheStore::class);
        $this->app->bind(EventSubmissionOptionsCache::class, EventSubmissionOptionsCacheStore::class);
        $this->app->bind(ProductSignalsDashboardCache::class, ProductSignalsDashboardCacheStore::class);
        $this->app->bind(EventProjections::class, SyncEventProjectionsAction::class);
        $this->app->bind(SeriesAuthoring::class, SeriesAuthoringService::class);
        $this->app->bind(TicketAffiliateCommissions::class, RecordTicketAffiliateCommissionsAction::class);
        $this->app->bind(TicketAffiliateReversals::class, ReverseTicketAffiliateCommissionAction::class);

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
