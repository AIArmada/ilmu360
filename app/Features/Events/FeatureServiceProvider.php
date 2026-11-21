<?php

declare(strict_types=1);

namespace App\Features\Events;

use AIArmada\Checkout\Contracts\StepContributor;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventTaxonomy;
use AIArmada\Events\Models\EventTerm;
use AIArmada\Events\Models\EventTimeExpression;
use AIArmada\FilamentEvents\Resources\EventAttendanceResource;
use AIArmada\FilamentEvents\Resources\EventChangeLogResource;
use AIArmada\FilamentEvents\Resources\EventOccurrenceResource;
use AIArmada\FilamentEvents\Resources\EventRegistrationParticipantResource;
use AIArmada\FilamentEvents\Resources\EventRegistrationResource;
use AIArmada\FilamentEvents\Resources\EventSessionResource;
use AIArmada\FilamentEvents\Resources\EventTaxonomyResource;
use AIArmada\FilamentEvents\Resources\EventTemplateResource;
use AIArmada\FilamentEvents\Resources\EventTermResource;
use App\Features\Events\Actions\Authoring\CreateEventWithInitialOccurrenceAction;
use App\Features\Events\Actions\Authoring\CreateEventWithRegistrationAction;
use App\Features\Events\Actions\Authoring\PrepareAdvancedParentProgramSubmissionAction;
use App\Features\Events\Actions\Authoring\ResolveAdvancedBuilderContextAction;
use App\Features\Events\Actions\Authoring\ResolveAdvancedBuilderMembershipOptionsAction;
use App\Features\Events\Actions\Authoring\SyncEventLocationsAction;
use App\Features\Events\Actions\Authoring\SyncEventProjectionsAction;
use App\Features\Events\Actions\Changes\PublishEventChangeAnnouncementAction;
use App\Features\Events\Actions\Commerce\RecordTicketAffiliateCommissionsAction;
use App\Features\Events\Actions\Commerce\ReverseTicketAffiliateCommissionAction;
use App\Features\Events\Actions\Delete\CleanupEventAction;
use App\Features\Events\Actions\ResolveRegistrationExportContextAction;
use App\Features\Events\Actions\ScheduleShareCardWarmAction;
use App\Features\Events\Actions\Submission\ClaimGuestSubmissionsAction;
use App\Features\Events\Actions\Submission\CompleteFrontendEventSubmissionAction;
use App\Features\Events\Actions\Submission\ModerateEventOccurrenceSubmissionAction;
use App\Features\Events\Api\AccountEventFacts;
use App\Features\Events\Api\AdminEventModeration;
use App\Features\Events\Api\AdminEventSearch;
use App\Features\Events\Api\AdminEventWriter;
use App\Features\Events\Api\AdmissionEventCreator;
use App\Features\Events\Api\AdvancedBuilderContext;
use App\Features\Events\Api\AdvancedBuilderMembershipOptions;
use App\Features\Events\Api\AdvancedEventCreator;
use App\Features\Events\Api\AdvancedProgramPreparation;
use App\Features\Events\Api\ContributionProjection;
use App\Features\Events\Api\EventAccountOwnership;
use App\Features\Events\Api\EventAuthoring;
use App\Features\Events\Api\EventCards;
use App\Features\Events\Api\EventCategoryCatalog as EventCategoryCatalogContract;
use App\Features\Events\Api\EventCategoryPolicyResolver;
use App\Features\Events\Api\EventChangePublisher;
use App\Features\Events\Api\EventCollections;
use App\Features\Events\Api\EventContributionOptionsCountry;
use App\Features\Events\Api\EventContributionStateMapper;
use App\Features\Events\Api\EventDeletion;
use App\Features\Events\Api\EventDeletionScope;
use App\Features\Events\Api\EventDirectoryProjectionProvider;
use App\Features\Events\Api\EventDiscoveryReader;
use App\Features\Events\Api\EventDiscoveryReaders;
use App\Features\Events\Api\EventEngagementEligibility;
use App\Features\Events\Api\EventHomepageStats;
use App\Features\Events\Api\EventImageUploads;
use App\Features\Events\Api\EventInstitutionQuickAddMapsProofStore;
use App\Features\Events\Api\EventInvolvementFingerprint;
use App\Features\Events\Api\EventLocations;
use App\Features\Events\Api\EventMedia;
use App\Features\Events\Api\EventMediaExtraction;
use App\Features\Events\Api\EventMemberInvitationEligibility;
use App\Features\Events\Api\EventModeration;
use App\Features\Events\Api\EventPageSchedule;
use App\Features\Events\Api\EventProjections;
use App\Features\Events\Api\EventRegistrationNotifications;
use App\Features\Events\Api\EventReportSubjectProvider;
use App\Features\Events\Api\EventScheduleReads;
use App\Features\Events\Api\EventScheduleSql;
use App\Features\Events\Api\EventSearchCache;
use App\Features\Events\Api\EventShareCardCache;
use App\Features\Events\Api\EventSubmissionCompletion;
use App\Features\Events\Api\EventSubmissionOptionsCache;
use App\Features\Events\Api\EventTicketing;
use App\Features\Events\Api\Filament\AdminEventsRelationManager;
use App\Features\Events\Api\Frontend\AdvancedEventFormContractContributor;
use App\Features\Events\Api\Frontend\SubmitEventFormContractContributor;
use App\Features\Events\Api\GuestEventSubmissionClaims;
use App\Features\Events\Api\GuestSubmissionClaims;
use App\Features\Events\Api\OccurrenceSubmissionReviews;
use App\Features\Events\Api\OrganizerLookup;
use App\Features\Events\Api\Orm\EventOrmPort;
use App\Features\Events\Api\Orm\EventSchedulePageData;
use App\Features\Events\Api\Orm\EventSubmissions;
use App\Features\Events\Api\Orm\PublicScheduleEventReads;
use App\Features\Events\Api\ProductSignalsDashboardCache;
use App\Features\Events\Api\ProductSignalsInsights;
use App\Features\Events\Api\PublicScheduleVisibility;
use App\Features\Events\Api\PublicSubmissionLockSweeper;
use App\Features\Events\Api\RegistrationExportAccess;
use App\Features\Events\Api\SeriesAuthoring;
use App\Features\Events\Api\ShareCardWarmScheduler;
use App\Features\Events\Api\SubmissionTimingContract;
use App\Features\Events\Api\TaxonomyCatalog;
use App\Features\Events\Api\TicketAffiliateCommissions;
use App\Features\Events\Api\TicketAffiliateReversals;
use App\Features\Events\Contracts\EventChangeNoticeNotifier;
use App\Features\Events\Contracts\EventEscalationTransport;
use App\Features\Events\Contracts\EventReminderNotificationDispatch;
use App\Features\Events\Contracts\EventShareCardPrewarm;
use App\Features\Events\Contracts\OccurrenceReviewNotifier;
use App\Features\Events\EntryPoints\Admin\AdminEventModerationService;
use App\Features\Events\EntryPoints\Admin\Member\EventMemberResourceHooks;
use App\Features\Events\EntryPoints\Admin\RecordActions\EventRecordActionContributor;
use App\Features\Events\EntryPoints\Admin\Resources\EventAdminHooks;
use App\Features\Events\EntryPoints\Admin\Resources\EventMutationHooks;
use App\Features\Events\EntryPoints\Api\Admin\EventResourceDefinition;
use App\Features\Events\EntryPoints\Api\Admin\EventsPackageResourceDefinition;
use App\Features\Events\EntryPoints\Api\Admin\SeriesResourceDefinition;
use App\Features\Events\EntryPoints\Api\Member\EventResourceDefinition as EventMemberResourceDefinition;
use App\Features\Events\EntryPoints\ApiDocumentation\EventsRequestExampleContributor;
use App\Features\Events\EntryPoints\Checkout\TicketCheckoutStepContributor;
use App\Features\Events\EntryPoints\Filament\Pages\ModerationQueue;
use App\Features\Events\EntryPoints\Filament\Pages\ProductSignals;
use App\Features\Events\EntryPoints\Filament\Resources\Events\AdminEventsRelationManagerProvider;
use App\Features\Events\EntryPoints\Filament\Resources\Events\EventResource;
use App\Features\Events\EntryPoints\Filament\Resources\Series\SeriesResource;
use App\Features\Events\EntryPoints\Filament\Stats\PendingEventsStat;
use App\Features\Events\EntryPoints\Filament\TermDomainMappingExtension;
use App\Features\Events\EntryPoints\Filament\Widgets\EventInventoryOverview;
use App\Features\Events\EntryPoints\Filament\Widgets\PendingApprovalEventsWidget;
use App\Features\Events\EntryPoints\Http\PrimaryOccurrenceScheduleSql;
use App\Features\Events\EntryPoints\Jobs\DispatchEventReminderNotifications;
use App\Features\Events\EntryPoints\Jobs\EscalatePendingEvents;
use App\Features\Events\EntryPoints\Mcp\McpEventSearchService;
use App\Features\Events\EntryPoints\Mcp\Prompts\AdminEventCoverImagePrompt;
use App\Features\Events\EntryPoints\Mcp\Prompts\AdminEventPosterImagePrompt;
use App\Features\Events\EntryPoints\Mcp\Prompts\MemberEventCoverImagePrompt;
use App\Features\Events\EntryPoints\Mcp\Prompts\MemberEventPosterImagePrompt;
use App\Features\Events\EntryPoints\Mcp\Tools\AdminBatchCreateEventsTool;
use App\Features\Events\EntryPoints\Mcp\Tools\AdminBatchUpdateEventsTool;
use App\Features\Events\EntryPoints\Mcp\Tools\AdminCreateEventTool;
use App\Features\Events\EntryPoints\Mcp\Tools\AdminGetEventModerationSchemaTool;
use App\Features\Events\EntryPoints\Mcp\Tools\AdminModerateEventTool;
use App\Features\Events\EntryPoints\Mcp\Tools\AdminSearchEventsTool;
use App\Features\Events\EntryPoints\Mcp\Tools\AdminUpdateEventTool;
use App\Features\Events\EntryPoints\Mcp\Tools\AdminUploadEventCoverImageTool;
use App\Features\Events\EntryPoints\Mcp\Tools\AdminUploadEventPosterImageTool;
use App\Features\Events\EntryPoints\Mcp\Tools\MemberSearchEventsTool;
use App\Features\Events\EntryPoints\Mcp\Tools\MemberUploadEventCoverImageTool;
use App\Features\Events\EntryPoints\Mcp\Tools\MemberUploadEventPosterImageTool;
use App\Features\Events\EntryPoints\Sitemap\EventSitemapUrls;
use App\Features\Events\Models\Event;
use App\Features\Events\Models\EventKeyPerson;
use App\Features\Events\Notifications\EloquentEventEscalationTransport;
use App\Features\Events\Notifications\EventChangeNoticeNotificationAdapter;
use App\Features\Events\Notifications\EventNotificationService;
use App\Features\Events\Notifications\EventOccurrenceReviewNotifier;
use App\Features\Events\Observers\EventKeyPersonObserver;
use App\Features\Events\Observers\EventLifecycleObserver;
use App\Features\Events\Observers\EventLocationSyncObserver;
use App\Features\Events\Observers\EventObserver;
use App\Features\Events\Observers\EventOccurrenceFeaturedObserver;
use App\Features\Events\Observers\EventOccurrenceObserver;
use App\Features\Events\Observers\EventTaxonomyObserver;
use App\Features\Events\Observers\EventTermObserver;
use App\Features\Events\Observers\EventTimeExpressionObserver;
use App\Features\Events\Persistence\EventHomepageStatsCacheStore;
use App\Features\Events\Persistence\EventInstitutionQuickAddMapsProofCacheStore;
use App\Features\Events\Persistence\EventInvolvementFingerprintStore;
use App\Features\Events\Persistence\EventSearchCacheStore;
use App\Features\Events\Persistence\EventShareCardCacheStore;
use App\Features\Events\Persistence\EventSubmissionOptionsCacheStore;
use App\Features\Events\Persistence\ProductSignalsDashboardCacheStore;
use App\Features\Events\Policies\EventTicketingPolicy;
use App\Features\Events\Queries\EloquentAccountEventFacts;
use App\Features\Events\Queries\EloquentEventCollections;
use App\Features\Events\Queries\EventCardsQuery;
use App\Features\Events\Queries\EventContributionOptionsCountryQuery;
use App\Features\Events\Queries\EventContributionProjection;
use App\Features\Events\Queries\EventDiscoveryReaderFactory;
use App\Features\Events\Queries\EventMemberInvitationEligibilityQuery;
use App\Features\Events\Queries\PostgresEventDiscovery;
use App\Features\Events\Queries\PublicScheduleVisibilityQuery;
use App\Features\Events\Services\AdminEventWriterService;
use App\Features\Events\Services\EventAccountOwnershipService;
use App\Features\Events\Services\EventAuthoringService;
use App\Features\Events\Services\EventCategoryCatalog;
use App\Features\Events\Services\EventCategoryPolicy;
use App\Features\Events\Services\EventContributionStateMapperService;
use App\Features\Events\Services\EventEngagementEligibilityService;
use App\Features\Events\Services\EventImageUploadsService;
use App\Features\Events\Services\EventMediaExtractionService;
use App\Features\Events\Services\EventMediaService;
use App\Features\Events\Services\EventPageScheduleService;
use App\Features\Events\Services\EventReportSubjectResolver;
use App\Features\Events\Services\EventSchedulePageDataService;
use App\Features\Events\Services\EventSubmissionsService;
use App\Features\Events\Services\OrganizerLookupService;
use App\Features\Events\Services\PackageTaxonomyCatalog;
use App\Features\Events\Services\ProductSignalsInsightsService;
use App\Features\Events\Services\PublicSubmissionLockService;
use App\Features\Events\Services\SeriesAuthoringService;
use App\Features\Events\Services\ShareCards\ShareCardService;
use App\Features\Events\Support\EventModel;
use App\Features\Events\Support\ScopedEventDeletionScope;
use App\Features\Events\Support\SubmissionTimingPolicy;
use App\Features\Events\Support\Telemetry\EventsSignalSchemaContributor;
use App\Platform\Admin\FilamentPageRegistry;
use App\Platform\Admin\FilamentResourceRegistry;
use App\Platform\Admin\FilamentWidgetRegistry;
use App\Platform\Api\Admin\AdminMutationHookRegistry;
use App\Platform\Api\Admin\RecordActionContributorRegistry;
use App\Platform\Api\Frontend\FrontendFormContractRegistry;
use App\Platform\Api\Member\MemberResourceHookRegistry;
use App\Platform\Api\Resources\ResourceDefinitionContext;
use App\Platform\Api\Resources\ResourceDefinitionRegistry;
use App\Platform\ApiDocumentation\RequestExampleRegistry;
use App\Platform\FeatureModule;
use App\Platform\Mcp\McpSurfaceRegistry;
use App\Platform\Routing\PublicSlugRegistry;
use App\Platform\Routing\PublicSlugResource;
use App\Platform\Telemetry\SignalSchemaRegistry;
use Illuminate\Support\Facades\Schedule;

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
        $this->app->bind(EventMemberInvitationEligibility::class, EventMemberInvitationEligibilityQuery::class);
        $this->app->bind(EventContributionStateMapper::class, EventContributionStateMapperService::class);
        $this->app->bind(OrganizerLookup::class, OrganizerLookupService::class);
        $this->app->bind(PublicScheduleVisibility::class, PublicScheduleVisibilityQuery::class);
        $this->app->bind(EventEngagementEligibility::class, EventEngagementEligibilityService::class);
        $this->app->bind(EventSchedulePageData::class, EventSchedulePageDataService::class);
        $this->app->bind(EventPageSchedule::class, EventPageScheduleService::class);
        $this->app->bind(EventCollections::class, EloquentEventCollections::class);
        $this->app->bind(AdminEventSearch::class, McpEventSearchService::class);
        $this->app->bind(EventScheduleSql::class, PrimaryOccurrenceScheduleSql::class);
        $this->app->bind(EventTicketing::class, EventTicketingPolicy::class);
        $this->app->bind(PublicSubmissionLockSweeper::class, PublicSubmissionLockService::class);
        $this->app->bind(EventEscalationTransport::class, EloquentEventEscalationTransport::class);
        $this->app->bind(EventShareCardPrewarm::class, ShareCardService::class);
        $this->app->bind(EventReminderNotificationDispatch::class, EventNotificationService::class);
        $this->app->bind(AccountEventFacts::class, EloquentAccountEventFacts::class);
        $this->app->bind(OccurrenceReviewNotifier::class, EventOccurrenceReviewNotifier::class);
        $this->app->bind(EventChangeNoticeNotifier::class, EventChangeNoticeNotificationAdapter::class);
        $this->app->bind(EventCards::class, EventCardsQuery::class);
        $this->app->bind(ContributionProjection::class, EventContributionProjection::class);
        $this->app->bind(EventContributionOptionsCountry::class, EventContributionOptionsCountryQuery::class);
        $this->app->bind(AdminEventsRelationManager::class, AdminEventsRelationManagerProvider::class);
        $this->app->bind(EventDiscoveryReaders::class, EventDiscoveryReaderFactory::class);
        $this->app->bind(EventDiscoveryReader::class, PostgresEventDiscovery::class);
        $this->app->bind(EventScheduleReads::class, PostgresEventDiscovery::class);
        $this->app->bind(EventRegistrationNotifications::class, EventNotificationService::class);
        $this->app->bind(EventOrmPort::class, PostgresEventDiscovery::class);
        $this->app->bind(EventDirectoryProjectionProvider::class, PostgresEventDiscovery::class);
        $this->app->bind(PublicScheduleEventReads::class, PostgresEventDiscovery::class);

        $this->app->bind(SubmissionTimingContract::class, SubmissionTimingPolicy::class);

        $this->app->bind(AdminEventModeration::class, AdminEventModerationService::class);

        $this->app->make(\App\Platform\Api\Admin\AdminResourceHookRegistry::class)->register(new EventAdminHooks);

        $this->app->bind(TaxonomyCatalog::class, PackageTaxonomyCatalog::class);
        $this->app->tag([PendingEventsStat::class], 'admin.stats');

        $this->app->tag([EventSitemapUrls::class], 'sitemap.urls');

        if (interface_exists(StepContributor::class)) {
            $this->app->tag(TicketCheckoutStepContributor::class, 'checkout.steps');
        }

        $this->app->bind(ShareCardWarmScheduler::class, ScheduleShareCardWarmAction::class);
        $this->app->bind(ProductSignalsInsights::class, ProductSignalsInsightsService::class);
        $this->commands([
            EntryPoints\Console\Commands\IndexEventsToTypesense::class,
            EntryPoints\Console\Commands\PruneShareCards::class,
            EntryPoints\Console\Commands\SyncPublicSubmissionLocks::class,
        ]);

        config()->set('filament-events.resources.term_form_extensions', [
            ...array_values((array) config('filament-events.resources.term_form_extensions', [])),
            TermDomainMappingExtension::class,
        ]);

        $this->app->bind(GuestSubmissionClaims::class, ClaimGuestSubmissionsAction::class);
        $this->app->bind(GuestEventSubmissionClaims::class, ClaimGuestSubmissionsAction::class);
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
        $this->app->bind(EventChangePublisher::class, PublishEventChangeAnnouncementAction::class);
        $this->app->bind(EventDeletion::class, CleanupEventAction::class);
        $this->app->scoped(EventDeletionScope::class, ScopedEventDeletionScope::class);
        $this->app->bind(EventSubmissions::class, EventSubmissionsService::class);
        $this->app->bind(EventSubmissionCompletion::class, CompleteFrontendEventSubmissionAction::class);
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

        $resources = $this->app->make(FilamentResourceRegistry::class);
        $resources->register(EventResource::class, FilamentResourceRegistry::PANEL_ADMIN);
        $resources->register(EventResource::class, FilamentResourceRegistry::PANEL_AHLI);
        $resources->register(SeriesResource::class);

        $pages = $this->app->make(FilamentPageRegistry::class);
        $pages->register(ModerationQueue::class);
        $pages->register(ProductSignals::class);

        $this->app->make(FilamentWidgetRegistry::class)->register(PendingApprovalEventsWidget::class, FilamentWidgetRegistry::PANEL_AHLI);
        $this->app->make(FilamentWidgetRegistry::class)->register(EventInventoryOverview::class);
    }

    public function boot(): void
    {
        Event::observe(EventLifecycleObserver::class);
        Event::observe(EventLocationSyncObserver::class);
        Event::observe(EventObserver::class);
        EventTerm::observe(EventTermObserver::class);
        EventKeyPerson::observe(EventKeyPersonObserver::class);
        EventOccurrence::observe(EventOccurrenceObserver::class);
        EventOccurrence::observe(EventOccurrenceFeaturedObserver::class);
        EventTimeExpression::observe(EventTimeExpressionObserver::class);
        EventTaxonomy::observe(EventTaxonomyObserver::class);

        $registry = $this->app->make(ResourceDefinitionRegistry::class);
        $memberDefinition = $this->app->make(EventMemberResourceDefinition::class);
        $registry->register($memberDefinition);
        $registry->registerWriter($memberDefinition);

        $registry = $this->app->make(ResourceDefinitionRegistry::class);
        $registry->register($this->app->make(EventResourceDefinition::class));
        $registry->register($this->app->make(SeriesResourceDefinition::class));

        $resourceContext = $this->app->make(ResourceDefinitionContext::class);

        foreach ([
            EventOccurrenceResource::class,
            EventSessionResource::class,
            EventRegistrationResource::class,
            EventRegistrationParticipantResource::class,
            EventAttendanceResource::class,
            EventChangeLogResource::class,
            EventTemplateResource::class,
            EventTaxonomyResource::class,
            EventTermResource::class,
        ] as $resourceClass) {
            $registry->register(new EventsPackageResourceDefinition($resourceContext, $resourceClass));
        }

        $this->registerFrontendFormContracts();

        $this->app->make(SignalSchemaRegistry::class)->register(new EventsSignalSchemaContributor);
        $this->app->make(RequestExampleRegistry::class)->register(new EventsRequestExampleContributor);

        parent::boot();

        $this->app->make(MemberResourceHookRegistry::class)->register($this->app->make(EventMemberResourceHooks::class));

        $this->app->make(AdminMutationHookRegistry::class)->register($this->app->make(EventMutationHooks::class));

        $this->app->make(RecordActionContributorRegistry::class)->register($this->app->make(EventRecordActionContributor::class));

        $this->registerMcpSurfaces();

        if ($this->app->runningInConsole()) {
            Schedule::job(new EscalatePendingEvents)
                ->hourly()
                ->timezone('Asia/Kuala_Lumpur')
                ->name('escalate-pending-events')
                ->withoutOverlapping();
        }

        if ($this->app->runningInConsole()) {
            Schedule::job(new DispatchEventReminderNotifications)
                ->everyFifteenMinutes()
                ->timezone('UTC')
                ->name('notification-reminders')
                ->withoutOverlapping();
        }

        $this->app->make(PublicSlugRegistry::class)->register(new PublicSlugResource(
            parameter: 'event',
            routeName: 'events.show',
            modelClass: EventModel::event(),
            requiresVisitedSource: false,
            searchColumns: ['title', 'slug'],
            labelAttribute: 'title',
        ));
    }

    private function registerFrontendFormContracts(): void
    {
        $contracts = $this->app->make(FrontendFormContractRegistry::class);

        $contracts->register(SubmitEventFormContractContributor::class);
        $contracts->register(AdvancedEventFormContractContributor::class);
    }

    private function registerMcpSurfaces(): void
    {
        $surfaces = $this->app->make(McpSurfaceRegistry::class);

        $surfaces->registerAdminPrompt(AdminEventCoverImagePrompt::class);
        $surfaces->registerAdminPrompt(AdminEventPosterImagePrompt::class);
        $surfaces->registerAdminTool(AdminBatchCreateEventsTool::class);
        $surfaces->registerAdminTool(AdminBatchUpdateEventsTool::class);
        $surfaces->registerAdminTool(AdminCreateEventTool::class);
        $surfaces->registerAdminTool(AdminGetEventModerationSchemaTool::class);
        $surfaces->registerAdminTool(AdminModerateEventTool::class);
        $surfaces->registerAdminTool(AdminSearchEventsTool::class);
        $surfaces->registerAdminTool(AdminUpdateEventTool::class);
        $surfaces->registerAdminTool(AdminUploadEventCoverImageTool::class);
        $surfaces->registerAdminTool(AdminUploadEventPosterImageTool::class);

        $surfaces->registerMemberPrompt(MemberEventCoverImagePrompt::class);
        $surfaces->registerMemberPrompt(MemberEventPosterImagePrompt::class);
        $surfaces->registerMemberTool(MemberSearchEventsTool::class);
        $surfaces->registerMemberTool(MemberUploadEventCoverImageTool::class);
        $surfaces->registerMemberTool(MemberUploadEventPosterImageTool::class);
    }
}
