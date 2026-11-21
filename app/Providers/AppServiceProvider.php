<?php

namespace App\Providers;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\Addressable;
use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\AddressAreaRelationship;
use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\Addressing\Models\City;
use AIArmada\Addressing\Models\State;
use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Checkout\Models\CheckoutSession;
use AIArmada\CommerceSupport\Models\Language as CommerceLanguage;
use AIArmada\Contacting\Models\ContactMethod;
use AIArmada\Contacting\Models\SocialProfile;
use AIArmada\Customers\Models\Customer;
use AIArmada\Events\Events\EventRegistrationCancelled;
use AIArmada\Events\Events\EventRegistrationRefunded;
use AIArmada\Events\Events\EventRegistrationRejected;
use AIArmada\Events\Models\Event as PackageEvent;
use AIArmada\Events\Models\EventAccessPolicy;
use AIArmada\Events\Models\EventLocation;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventRegistrationParticipant;
use AIArmada\Events\Models\EventSession;
use AIArmada\Events\Models\EventTaxonomy;
use AIArmada\Events\Models\EventTerm;
use AIArmada\Events\Models\EventTimeExpression;
use AIArmada\FilamentSignals\Policies\TrackedPropertyPolicy;
use AIArmada\Inventory\Models\InventoryAllocation;
use AIArmada\Inventory\Models\InventoryLevel;
use AIArmada\Inventory\Models\InventoryLocation;
use AIArmada\Inventory\Models\InventoryMovement;
use AIArmada\Membership\Contracts\MembershipApplicationNotifier;
use AIArmada\Membership\Contracts\MembershipHook;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\Models\OrderItem;
use AIArmada\Orders\Models\OrderNote;
use AIArmada\Orders\Models\OrderPayment;
use AIArmada\Orders\Models\OrderRefund;
use AIArmada\Organizations\Models\Organization;
use AIArmada\Persons\Models\PersonName as PackagePersonName;
use AIArmada\Persons\Models\Title;
use AIArmada\Persons\Models\TitleAssignment;
use AIArmada\Persons\Models\TitleCategory;
use AIArmada\References\Models\ReferenceContributor;
use AIArmada\Rewards\Contracts\RewardCorrectionKeySource;
use AIArmada\Rewards\Contracts\RewardSourceFactReader;
use AIArmada\Signals\Models\TrackedProperty;
use AIArmada\Ticketing\Models\TicketType;
use App\Features\Contributions\Api\ContributionEntityState;
use App\Features\Contributions\Api\Orm\EntitySubmissionAccessContract;
use App\Features\Contributions\Api\Orm\SubmissionContext;
use App\Features\Contributions\Api\Orm\SubmissionEntityAccess;
use App\Features\Contributions\Models\ContributionRequest;
use App\Features\Contributions\Notifications\ContributionRequestNotificationService;
use App\Features\Contributions\Services\ContributionEntityStateService;
use App\Features\Contributions\Services\EntitySubmissionAccess;
use App\Features\Contributions\Services\SubmissionContextResolver;
use App\Features\Contributions\Support\ContributionRequestNotifier;
use App\Features\Discovery\Api\EventSearchReader;
use App\Features\Discovery\Api\Orm\EventListPayloads;
use App\Features\Discovery\Api\Orm\PublicScheduleOrmPort;
use App\Features\Discovery\Api\SearchFilterLabels;
use App\Features\Discovery\EntryPoints\Http\EventListPayloadPresenter;
use App\Features\Discovery\Queries\DatabaseSearchFilterLabels;
use App\Features\Discovery\Services\EventSearchService;
use App\Features\Discovery\Services\PublicScheduleDiscoveryService;
use App\Features\DonationChannels\Models\DonationChannel;
use App\Features\Engagement\Api\EngagementActivityReader;
use App\Features\Engagement\Api\EngagementShareOutcomes;
use App\Features\Engagement\Api\EventEngagement;
use App\Features\Engagement\Api\EventEngagementToggles;
use App\Features\Engagement\Api\SubjectFollows;
use App\Features\Engagement\Queries\EloquentEngagementReader;
use App\Features\Engagement\Services\EventEngagementToggleService;
use App\Features\Events\Api\AccountEventFacts;
use App\Features\Events\Api\AdminEventSearch;
use App\Features\Events\Api\EventCollections;
use App\Features\Events\Api\EventEngagementEligibility;
use App\Features\Events\Api\EventScheduleSql;
use App\Features\Events\Api\EventTicketing;
use App\Features\Events\Api\OrganizerLookup;
use App\Features\Events\Api\Orm\EventCategoryPayloads;
use App\Features\Events\Api\Orm\EventContributionStateMapper;
use App\Features\Events\Actions\Delete\GuardEventOccurrenceDeletionAction;
use App\Features\Events\Api\Orm\EventPageScheduleOrmPort;
use App\Features\Events\Api\Orm\EventSchedulePageData;
use App\Features\Events\Api\Orm\PublicScheduleEligibility;
use App\Features\Events\Api\PublicSubmissionLockSweeper;
use App\Features\Events\Contracts\EventChangeNoticeNotifier;
use App\Features\Events\Contracts\EventEscalationTransport;
use App\Features\Events\Contracts\EventReminderNotificationDispatch;
use App\Features\Events\Contracts\EventShareCardPrewarm;
use App\Features\Events\Contracts\OccurrenceReviewNotifier;
use App\Features\Events\Contributors\ModerationAccountLifecycleContributor;
use App\Features\Events\EntryPoints\Http\EventCategoryPayloadPresenter;
use App\Features\Events\EntryPoints\Http\PrimaryOccurrenceScheduleSql;
use App\Features\Events\EntryPoints\Mcp\McpEventSearchService;
use App\Features\Events\EntryPoints\Ui\EventJsonLd;
use App\Features\Events\Models\Event;
use App\Features\Events\Models\EventKeyPerson;
use App\Features\Events\Models\EventSubmission;
use App\Features\Events\Models\MediaLink;
use App\Features\Events\Models\Series;
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
use App\Features\Events\Policies\EventPolicy;
use App\Features\Events\Policies\EventTicketingPolicy;
use App\Features\Events\Queries\EloquentAccountEventFacts;
use App\Features\Events\Queries\EloquentEventCollections;
use App\Features\Events\Services\EventContributionStateMapperService;
use App\Features\Events\Services\EventEngagementEligibilityService;
use App\Features\Events\Services\EventPageScheduleService;
use App\Features\Events\Services\EventSchedulePageDataService;
use App\Features\Events\Services\OrganizerLookupService;
use App\Features\Events\Services\PublicScheduleEligibilityService;
use App\Features\Events\Services\PublicSubmissionLockService;
use App\Features\Events\Services\ShareCards\ShareCardService;
use App\Features\Identity\Api\AccountAccess;
use App\Features\Identity\Api\AccountSettingsStore;
use App\Features\Identity\Api\SignupAttributionContextResolver;
use App\Features\Identity\Api\SignupAttributionSourceResolver;
use App\Features\Identity\Integrations\Signup\SignupAttributionContextResolverAdapter;
use App\Features\Identity\Listeners\ClaimGuestSubmissionsOnVerified;
use App\Features\Identity\Listeners\RecordVerifiedEmail;
use App\Features\Identity\Listeners\SendRegisteredUserEmails;
use App\Features\Identity\Observers\UserObserver;
use App\Features\Identity\Persistence\EloquentAccountAccess;
use App\Features\Identity\Persistence\EloquentAccountDirectory;
use App\Features\Identity\Persistence\EloquentAccountSettingsStore;
use App\Features\Identity\Persistence\EloquentUserMorphType;
use App\Features\Identity\Support\NullSignupAttributionSourceResolver;
use App\Features\Institutions\Api\InstitutionCards;
use App\Features\Institutions\Api\InstitutionSearch;
use App\Features\Institutions\Models\Institution;
use App\Features\Institutions\Models\InstitutionImportExclusion;
use App\Features\Institutions\Models\InstitutionName;
use App\Features\Institutions\Observers\InstitutionImportExclusionObserver;
use App\Features\Institutions\Observers\InstitutionObserver;
use App\Features\Institutions\Queries\InstitutionCardsQuery;
use App\Features\Institutions\Queries\InstitutionResourceSearchAdapter;
use App\Features\Institutions\Services\InstitutionSearchService;
use App\Features\Membership\Api\OrganizationEventAccessContract;
use App\Features\Membership\Api\ScopedMembershipRoleStore;
use App\Features\Membership\Contracts\MembershipPermissionEvaluator;
use App\Features\Membership\Integrations\AppMembershipHook;
use App\Features\Membership\Models\MemberInvitation;
use App\Features\Membership\Models\MembershipApplication;
use App\Features\Membership\Notifications\AppMembershipApplicationNotifier;
use App\Features\Membership\Persistence\EloquentScopedMembershipRoleStore;
use App\Features\Membership\Services\MemberPermissionGate;
use App\Features\Membership\Services\OrganizationEventAccess;
use App\Features\Moderation\Models\ModerationReview;
use App\Features\Persons\Actions\PruneOrphanedPersonsAction;
use App\Features\Persons\Api\PersonCards;
use App\Features\Persons\Api\PersonSearch;
use App\Features\Persons\Api\PersonSearchMaintenance;
use App\Features\Persons\Api\PruneOrphanedPersons;
use App\Features\Persons\Models\Affiliation;
use App\Features\Persons\Models\Person;
use App\Features\Persons\Models\PersonName;
use App\Features\Persons\Observers\PersonLifecycleObserver;
use App\Features\Persons\Observers\PersonNameObserver;
use App\Features\Persons\Observers\PersonObserver;
use App\Features\Persons\Observers\PersonTitleObserver;
use App\Features\Persons\Observers\TitleCategoryObserver;
use App\Features\Persons\Queries\PersonCardsQuery;
use App\Features\Persons\Queries\PersonResourceSearchAdapter;
use App\Features\Persons\Services\PersonSearchService;
use App\Features\Places\Actions\PruneOrphanedVenuesAction;
use App\Features\Places\Api\Orm\SpaceLocation;
use App\Features\Places\Api\PruneOrphanedVenues;
use App\Features\Places\Models\Space;
use App\Features\Places\Models\Venue;
use App\Features\Places\Observers\VenueObserver;
use App\Features\Places\Services\SpaceLocationResolver;
use App\Features\Prayer\Api\Orm\PrayerLocationResolver;
use App\Features\Prayer\Services\PrayerLocationResolverService;
use App\Features\References\Api\ReferenceSearch;
use App\Features\References\Models\Reference;
use App\Features\References\Observers\ReferenceContributorObserver;
use App\Features\References\Observers\ReferenceObserver;
use App\Features\References\Services\ReferenceSearchService;
use App\Features\References\Support\ReferenceScopedSearch;
use App\Features\Registration\Api\CheckInNotifier;
use App\Features\Registration\Models\EventCheckin;
use App\Features\Registration\Models\Registration;
use App\Features\Registration\Notifications\EventNotificationCheckInNotifier;
use App\Features\Registration\Policies\RegistrationPolicy;
use App\Features\Rewards\Models\RewardDigestSlot;
use App\Features\Rewards\Queries\CompositeRewardSourceFactReader;
use App\Features\Rewards\Services\ShareCorrectionKeySource;
use App\Features\SavedSearches\Api\SavedSearchExecutor;
use App\Features\SavedSearches\Services\EventSavedSearchExecutor;
use App\Features\ShareTracking\Api\ActiveAttribution;
use App\Features\ShareTracking\Api\GuestClaims;
use App\Features\ShareTracking\Api\Orm\GuestClaimScope;
use App\Features\ShareTracking\Api\ParticipationAttribution;
use App\Features\ShareTracking\Api\SavedSearchShareOutcomes;
use App\Features\ShareTracking\Api\ShareAnalytics;
use App\Features\ShareTracking\Api\ShareTrackingContract;
use App\Features\ShareTracking\Api\SignupRecorder;
use App\Features\ShareTracking\Contracts\ClaimRecipients;
use App\Features\ShareTracking\EntryPoints\Listeners\ReverseRegistrationShareOutcome;
use App\Features\ShareTracking\Integrations\Auth\AffiliateSignupAttributionSourceResolver;
use App\Features\ShareTracking\Models\ShareBrowserSelection;
use App\Features\ShareTracking\Models\ShareCohortProjection;
use App\Features\ShareTracking\Models\ShareEffectReceipt;
use App\Features\ShareTracking\Models\ShareOutcomeCorrection;
use App\Features\ShareTracking\Models\ShareSignalReaderCursor;
use App\Features\ShareTracking\Models\ShareSignalReaderSkip;
use App\Features\ShareTracking\Models\ShareSourceAttributionReceipt;
use App\Features\ShareTracking\Observers\ShareOutcomeCorrectionObserver;
use App\Features\ShareTracking\Observers\ShareSourceAttributionReceiptObserver;
use App\Features\ShareTracking\Queries\ClaimRecipientLookup;
use App\Features\ShareTracking\Queries\GuestClaimLookup;
use App\Features\ShareTracking\Queries\ShareAnalyticsReader;
use App\Features\ShareTracking\Services\AffiliatesShareTrackingService;
use App\Features\ShareTracking\Services\EngagementOutcomeRecorder;
use App\Features\ShareTracking\Services\GuestClaimScopeService;
use App\Features\ShareTracking\Services\SavedSearchOutcomeRecorder;
use App\Features\ShareTracking\Services\ShareTrackingActiveAttribution;
use App\Features\ShareTracking\Services\ShareTrackingParticipationAttribution;
use App\Features\ShareTracking\Services\ShareTrackingSignupRecorder;
use App\Features\SlugRedirects\Api\PublicSlugLookup;
use App\Host\Pango\ShareCards\PangoShareCardRenderer;
use App\Host\Typesense\TypesenseHealthProbe;
use App\Models\User;
use App\Platform\Addressing\Observers\AddressableObserver;
use App\Platform\Addressing\Observers\AddressAreaObserver;
use App\Platform\Addressing\Observers\AddressAreaRelationshipObserver;
use App\Platform\Addressing\Observers\AddressCatalogObserver;
use App\Platform\Addressing\Observers\AddressCountryObserver;
use App\Platform\Addressing\Observers\AddressObserver;
use App\Platform\Addressing\Policies\AddressAreaPolicy;
use App\Platform\Addressing\Policies\AddressCountryPolicy;
use App\Platform\Api\Admin\AdminValidateOnlyRemediationEnricher;
use App\Platform\Api\ApiErrorDetailsEnricher;
use App\Platform\ApiDocumentation\RequestExampleRegistry;
use App\Platform\Auditing\AuditRecorder;
use App\Platform\Auditing\EloquentAuditRecorder;
use App\Platform\Auditing\Models\Audit as FilamentAudit;
use App\Platform\Auditing\Policies\FilamentAuditPolicy;
use App\Platform\Auth\PassportKeyProvisioner;
use App\Platform\Captcha\NullCaptchaVerifier;
use App\Platform\Captcha\TurnstileVerifier;
use App\Platform\Language\Api\LanguageOptions;
use App\Platform\Language\DatabaseLanguageCatalog;
use App\Platform\Language\EloquentLanguageOptions;
use App\Platform\Language\Models\Language;
use App\Platform\Language\Observers\LanguageObserver;
use App\Platform\Location\Api\AddressCatalog;
use App\Platform\Location\EloquentAddressCatalog;
use App\Platform\Location\VisitorCountryResolver;
use App\Platform\Mcp\EntryPoints\Http\OAuthRegisterController;
use App\Platform\Mcp\McpSurfaceRegistry;
use App\Platform\Media\EloquentMediaCollectionSync;
use App\Platform\Media\MediaCollectionSync;
use App\Platform\Media\MediaFileNamer;
use App\Platform\Models\SlugOrUuidResolver;
use App\Platform\Routing\PublicSlugRegistry;
use App\Platform\Routing\PublicSlugResource;
use App\Platform\Search\ResourceSearchAdapter;
use App\Platform\Telemetry\EntryPoints\ApiDocumentation\MobileTelemetryRequestExampleContributor;
use App\Platform\Telemetry\ProductSignalRecorder;
use App\Platform\Telemetry\SignalSchemaRegistry;
use App\Platform\Time\DefaultUserDateTimeFormatter;
use App\Platform\Ui\ContentVersionedJs;
use App\Shared\Auth\AccountDirectory;
use App\Shared\Auth\AccountLifecycleContributor;
use App\Shared\Auth\UserMorphType;
use App\Shared\Captcha\CaptchaVerifier;
use App\Shared\Contracts\SearchHealthProbe;
use App\Shared\Language\LanguageCatalog;
use App\Shared\Location\VisitorCountryResolving;
use App\Shared\Media\ShareCards\ShareCardRenderer;
use App\Shared\Signals\SignalRecorder;
use App\Shared\Time\UserDateTimeFormatting;
use BezhanSalleh\LanguageSwitch\LanguageSwitch;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Support\Assets\Js;
use Filament\Support\Colors\Color;
use Filament\Support\Components\ComponentManager;
use Filament\Support\Components\Contracts\ScopedComponentManager;
use Filament\Support\Facades\FilamentAsset;
use Filament\Support\Facades\FilamentColor;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController as McpOAuthRegisterController;
use Laravel\Passport\Passport;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use PhpParser\PrettyPrinter;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class AppServiceProvider extends ServiceProvider
{
    protected static bool $languageSwitchConfigured = false;

    /**
     * Filament stores component configuration on its container-scoped manager.
     * Keying the guard by that manager re-registers after a rebuilt container
     * (tests) while a long-lived Octane worker still registers only once.
     */
    protected static ?ScopedComponentManager $mediaUploadConfiguredFor = null;

    protected static bool $filamentColorsRegistered = false;

    protected static bool $publicSlugBindingsRegistered = false;

    /**
     * Route parameters whose bindings must exist before any feature registers routes.
     * The registry itself is resolved lazily inside each binding callback.
     *
     * @var list<string>
     */
    private const array PUBLIC_SLUG_PARAMETERS = ['event', 'institution', 'person', 'venue', 'reference'];

    /**
     * Register any application services.
     */
    #[\Override]
    public function register(): void
    {
        $this->app->bind(SearchHealthProbe::class, TypesenseHealthProbe::class);
        $this->app->singleton(
            ShareCardRenderer::class,
            static fn (): ShareCardRenderer => new PangoShareCardRenderer(public_path('fonts/share-cards')),
        );
        $this->app->bind(EventContributionStateMapper::class, EventContributionStateMapperService::class);
        $this->app->bind(OrganizerLookup::class, OrganizerLookupService::class);
        $this->app->bind(PublicScheduleEligibility::class, PublicScheduleEligibilityService::class);
        $this->app->bind(EventEngagementEligibility::class, EventEngagementEligibilityService::class);
        $this->app->bind(EventSchedulePageData::class, EventSchedulePageDataService::class);
        $this->app->bind(EventPageScheduleOrmPort::class, EventPageScheduleService::class);
        $this->app->bind(PrayerLocationResolver::class, PrayerLocationResolverService::class);
        $this->app->bind(PruneOrphanedVenues::class, PruneOrphanedVenuesAction::class);
        $this->app->bind(EntitySubmissionAccessContract::class, EntitySubmissionAccess::class);
        $this->app->bind(PublicScheduleOrmPort::class, PublicScheduleDiscoveryService::class);
        $this->app->bind(EventEngagementToggles::class, EventEngagementToggleService::class);
        $this->app->bind(GuestClaimScope::class, GuestClaimScopeService::class);
        $this->app->bind(OrganizationEventAccessContract::class, OrganizationEventAccess::class);
        $this->app->bind(PruneOrphanedPersons::class, PruneOrphanedPersonsAction::class);
        $this->app->bind(InstitutionSearch::class, InstitutionSearchService::class);
        $this->app->bind(ReferenceSearch::class, ReferenceSearchService::class);
        $this->app->bind(EventSearchReader::class, EventSearchService::class);
        $this->app->bind(EventCollections::class, EloquentEventCollections::class);
        $this->app->bind(InstitutionCards::class, InstitutionCardsQuery::class);
        $this->app->bind(PersonCards::class, PersonCardsQuery::class);
        $this->app->bind(EventListPayloads::class, EventListPayloadPresenter::class);
        $this->app->bind(AdminEventSearch::class, McpEventSearchService::class);
        $this->app->bind(EventCategoryPayloads::class, EventCategoryPayloadPresenter::class);
        $this->app->bind(EventScheduleSql::class, PrimaryOccurrenceScheduleSql::class);
        $this->app->bind(EventTicketing::class, EventTicketingPolicy::class);
        $this->app->bind(SpaceLocation::class, SpaceLocationResolver::class);
        $this->app->bind(SubmissionEntityAccess::class, EntitySubmissionAccess::class);
        $this->app->bind(SubmissionContext::class, SubmissionContextResolver::class);
        $this->app->bind(LanguageOptions::class, EloquentLanguageOptions::class);
        $this->app->bind(AddressCatalog::class, EloquentAddressCatalog::class);
        // Register before the final vendor EventOccurrence model boots so
        // integrity preflight precedes the vendor's cascading deleting hook.
        EventFacade::listen('eloquent.deleting: '.EventOccurrence::class, [GuardEventOccurrenceDeletionAction::class, 'handle']);

        $this->app->bind(AuditRecorder::class, EloquentAuditRecorder::class);
        $this->app->bind(AccountAccess::class, EloquentAccountAccess::class);
        $this->app->bind(AccountDirectory::class, EloquentAccountDirectory::class);
        $this->app->bind(PersonSearch::class, PersonSearchService::class);
        $this->app->bind(PersonSearchMaintenance::class, PersonSearchService::class);
        $this->app->bind(PublicSubmissionLockSweeper::class, PublicSubmissionLockService::class);
        $this->app->bind(EventEscalationTransport::class, EloquentEventEscalationTransport::class);
        $this->app->bind(EventShareCardPrewarm::class, ShareCardService::class);
        $this->app->bind(EventReminderNotificationDispatch::class, EventNotificationService::class);
        $this->app->bind(LanguageCatalog::class, DatabaseLanguageCatalog::class);
        $this->app->bind(AccountSettingsStore::class, EloquentAccountSettingsStore::class);
        $this->app->bind(SignupAttributionContextResolver::class, SignupAttributionContextResolverAdapter::class);
        $this->app->bind(GuestClaims::class, GuestClaimLookup::class);
        $this->app->bind(ClaimRecipients::class, ClaimRecipientLookup::class);
        $this->app->bind(SignupRecorder::class, ShareTrackingSignupRecorder::class);
        $this->app->bind(ParticipationAttribution::class, ShareTrackingParticipationAttribution::class);
        $this->app->bind(ActiveAttribution::class, ShareTrackingActiveAttribution::class);
        $this->app->bind(CheckInNotifier::class, EventNotificationCheckInNotifier::class);
        $this->app->bind(UserMorphType::class, EloquentUserMorphType::class);
        $this->app->bind(ScopedMembershipRoleStore::class, EloquentScopedMembershipRoleStore::class);
        $this->app->bind(MembershipPermissionEvaluator::class, MemberPermissionGate::class);
        $this->app->bind(AccountEventFacts::class, EloquentAccountEventFacts::class);
        $this->app->bind(OccurrenceReviewNotifier::class, EventOccurrenceReviewNotifier::class);
        $this->app->bind(EventChangeNoticeNotifier::class, EventChangeNoticeNotificationAdapter::class);
        $this->app->bind(ShareAnalytics::class, ShareAnalyticsReader::class);
        $this->app->singleton(RewardSourceFactReader::class, CompositeRewardSourceFactReader::class);
        $this->app->singleton(RewardCorrectionKeySource::class, ShareCorrectionKeySource::class);
        $this->app->bind(EngagementActivityReader::class, EloquentEngagementReader::class);
        $this->app->bind(EventEngagement::class, EloquentEngagementReader::class);
        $this->app->bind(SubjectFollows::class, EloquentEngagementReader::class);
        $this->app->singleton(SignalSchemaRegistry::class);
        $this->app->singleton(RequestExampleRegistry::class);
        $this->app->singleton(McpSurfaceRegistry::class);
        $this->app->bind(ContributionEntityState::class, ContributionEntityStateService::class);
        $this->app->bind(ContributionRequestNotifier::class, ContributionRequestNotificationService::class);
        $this->app->tag([
            ModerationAccountLifecycleContributor::class,
        ], AccountLifecycleContributor::TAG);

        $this->app->bind(SavedSearchExecutor::class, EventSavedSearchExecutor::class);
        $this->app->bind(SavedSearchShareOutcomes::class, SavedSearchOutcomeRecorder::class);
        $this->app->bind(EngagementShareOutcomes::class, EngagementOutcomeRecorder::class);
        $this->app->bind(SignalRecorder::class, ProductSignalRecorder::class);
        $this->app->bind(VisitorCountryResolving::class, VisitorCountryResolver::class);
        $this->app->bind(UserDateTimeFormatting::class, DefaultUserDateTimeFormatter::class);
        $this->app->bind(MediaCollectionSync::class, EloquentMediaCollectionSync::class);
        $this->app->bind(ApiErrorDetailsEnricher::class, AdminValidateOnlyRemediationEnricher::class);
        $this->app->tag([
            PersonResourceSearchAdapter::class,
            InstitutionResourceSearchAdapter::class,
        ], ResourceSearchAdapter::TAG);
        $this->app->bind(ReferenceScopedSearch::class, ReferenceSearchService::class);
        $this->app->scoped(SearchFilterLabels::class, DatabaseSearchFilterLabels::class);
        $this->app->bind(Js::class, ContentVersionedJs::class);

        $this->registerPublicSlugRegistry();

        $this->app->singleton(PrettyPrinter::class, PrettyPrinter\Standard::class);
        $this->app->bind(McpOAuthRegisterController::class, OAuthRegisterController::class);

        $filamentAuditingViews = base_path('vendor/tapp/filament-auditing/resources/views');

        if (is_dir($filamentAuditingViews)) {
            $this->loadViewsFrom($filamentAuditingViews, 'filament-auditing');
        }

        $this->app->singleton(MembershipHook::class, AppMembershipHook::class);
        $this->app->singleton(MembershipApplicationNotifier::class, AppMembershipApplicationNotifier::class);
        $this->app->bind(
            function ($app): CaptchaVerifier {
                $verifier = $app->make(TurnstileVerifier::class);

                return $verifier->isEnabled()
                    ? $verifier
                    : $app->make(NullCaptchaVerifier::class);
            },
        );

        // ShareTrackingContract stays the public seam for test doubles and
        // optional integrations; contract resolution is a singleton of the
        // concrete affiliates service. Direct concrete injection stays transient.
        $this->app->singleton(ShareTrackingContract::class, AffiliatesShareTrackingService::class);
        $this->app->bind(SignupAttributionSourceResolver::class, class_exists(Affiliate::class)
            ? AffiliateSignupAttributionSourceResolver::class
            : NullSignupAttributionSourceResolver::class);

        $this->registerPackageMigrations();
    }

    /**
     * Ensure all installed AIArmada package migrations are loaded.
     *
     * Some packages use spatie/laravel-package-tools runsMigrations() which
     * doesn't reliably register in the test (SQLite) environment. This
     * explicitly loads migrations from every installed package directory.
     */
    private function registerPackageMigrations(): void
    {
        foreach (glob(base_path('vendor/aiarmada/*')) as $packagePath) {
            $migrationDir = $packagePath.'/database/migrations';

            if (is_dir($migrationDir)) {
                $this->loadMigrationsFrom($migrationDir);
            }
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->make(RequestExampleRegistry::class)->register(new MobileTelemetryRequestExampleContributor);

        // Preserve the public <x-event-json-ld> tag after removing App/View.
        Blade::component(EventJsonLd::class, 'event-json-ld');

        // Explicit registration replaces Laravel's old app/Listeners/Auth discovery.
        // Preserve registered-email and verified-email after-commit listeners.
        EventFacade::listen(Registered::class, SendRegisteredUserEmails::class);
        EventFacade::listen(Verified::class, ClaimGuestSubmissionsOnVerified::class);
        EventFacade::listen(Verified::class, RecordVerifiedEmail::class);

        PassportKeyProvisioner::ensure();

        Passport::authorizationView('mcp.authorize');

        RateLimiter::for('api-auth-login', static fn (Request $request): Limit => Limit::perMinute(5)->by(sprintf('%s|%s|%s', $request->ip(), $request->path(), strtolower(trim((string) $request->input('login'))))));

        RateLimiter::for('api-auth-register', static fn (Request $request): Limit => Limit::perMinute(5)->by(sprintf('%s|%s|%s|%s', $request->ip(), $request->path(), strtolower(trim((string) $request->input('email'))), strtolower(trim((string) $request->input('phone'))))));

        RateLimiter::for('api-auth-social', static fn (Request $request): Limit => Limit::perMinute(5)->by(sprintf('%s|%s', $request->ip(), $request->path())));

        RateLimiter::for('api-auth-password', static fn (Request $request): Limit => Limit::perMinute(5)->by(sprintf('%s|%s|%s', $request->ip(), $request->path(), strtolower(trim((string) $request->input('email'))))));

        // Register custom scripts
        FilamentAsset::register([
            Js::make('close-on-select', __DIR__.'/../../resources/js/filament/close-on-select.js'),
            Js::make('user-timezone', __DIR__.'/../../resources/js/filament/user-timezone.js'),
        ]);

        Select::configureUsing(
            static fn (Select $select): Select => $select->native(false),
            isImportant: true,
        );
        SelectFilter::configureUsing(
            static fn (SelectFilter $filter): SelectFilter => $filter->native(false),
            isImportant: true,
        );
        Table::configureUsing(
            static fn (Table $table): Table => $table->deferFilters(false),
        );

        // Filament rendered outside a panel (public forms) defaults to amber;
        // match the panel brand so every surface shares one palette.
        if (! self::$filamentColorsRegistered) {
            FilamentColor::register([
                'primary' => Color::Emerald,
                'gray' => Color::Slate,
            ]);
            self::$filamentColorsRegistered = true;
        }

        $this->registerModelObservers();

        User::observe(UserObserver::class);

        // Event ownership is an application projection of the reusable
        // organization aggregate; the organizations package remains event-agnostic.
        Organization::resolveRelationUsing(
            'events',
            static fn (Organization $organization): MorphMany => $organization->morphMany(Event::class, 'owner'),
        );

        if (! app()->bound('share.registration_reversal.listeners.registered')) {
            EventFacade::listen(EventRegistrationCancelled::class, [ReverseRegistrationShareOutcome::class, 'handle']);
            EventFacade::listen(EventRegistrationRejected::class, [ReverseRegistrationShareOutcome::class, 'handle']);
            EventFacade::listen(EventRegistrationRefunded::class, [ReverseRegistrationShareOutcome::class, 'handle']);

            app()->instance('share.registration_reversal.listeners.registered', true);
        }

        if (! self::$languageSwitchConfigured) {
            LanguageSwitch::configureUsing(function (LanguageSwitch $switch): void {
                $switch->locales(['en', 'ms', 'id', 'ar', 'jv', 'ta', 'zh']);
            });
            self::$languageSwitchConfigured = true;
        }

        if (app()->runningUnitTests() || ! self::$publicSlugBindingsRegistered) {
            $this->registerPublicSlugBindings();

            if (! app()->runningUnitTests()) {
                self::$publicSlugBindingsRegistered = true;
            }
        }

        // Merged map, not enforced: polymorphic writes use configured aliases.
        Relation::morphMap([
            'address' => Address::class,

            'contact' => ContactMethod::class,
            'customer' => Customer::class,
            'user' => User::class,
            'event' => Event::class,
            'package_event' => PackageEvent::class,
            'event_access_policy' => EventAccessPolicy::class,
            'event_key_person' => EventKeyPerson::class,
            'event_location' => EventLocation::class,
            'event_occurrence' => EventOccurrence::class,
            'event_submission' => EventSubmission::class,
            'event_session' => EventSession::class,
            'checkin' => EventCheckin::class,
            'checkout_session' => CheckoutSession::class,
            'order' => Order::class,
            'order_item' => OrderItem::class,
            'order_note' => OrderNote::class,
            'order_payment' => OrderPayment::class,
            'order_refund' => OrderRefund::class,
            'contribution_request' => ContributionRequest::class,
            'event_registration_participant' => EventRegistrationParticipant::class,
            'membership_application' => MembershipApplication::class,
            'moderation_review' => ModerationReview::class,
            'media_link' => MediaLink::class,
            'institution' => Institution::class,
            'institution_name' => InstitutionName::class,
            'institution_import_exclusion' => InstitutionImportExclusion::class,
            'donation_channel' => DonationChannel::class,
            'member_invitation' => MemberInvitation::class,
            'registration' => Registration::class,
            'person' => Person::class,
            'person_name' => PersonName::class,
            'affiliation' => Affiliation::class,
            'series' => Series::class,
            'social_media' => SocialProfile::class,
            'space' => Space::class,
            'venue' => Venue::class,
            'language' => Language::class,
            'reference' => Reference::class,
            'share_browser_selection' => ShareBrowserSelection::class,
            'share_cohort_projection' => ShareCohortProjection::class,
            'share_effect_receipt' => ShareEffectReceipt::class,
            'share_outcome_correction' => ShareOutcomeCorrection::class,
            'share_signal_reader_cursor' => ShareSignalReaderCursor::class,
            'share_signal_reader_skip' => ShareSignalReaderSkip::class,
            'share_source_attribution_receipt' => ShareSourceAttributionReceipt::class,
            'reward_digest_slot' => RewardDigestSlot::class,
            'organization' => Organization::class,
            'inventory_location' => InventoryLocation::class,
            'inventory_level' => InventoryLevel::class,
            'inventory_allocation' => InventoryAllocation::class,
            'inventory_movement' => InventoryMovement::class,
            'ticket_type' => TicketType::class,
        ]);

        Gate::policy(FilamentAudit::class, FilamentAuditPolicy::class);
        Gate::policy(AddressArea::class, AddressAreaPolicy::class);
        Gate::policy(AddressCountry::class, AddressCountryPolicy::class);
        Gate::policy(Event::class, EventPolicy::class);
        Gate::policy(Registration::class, RegistrationPolicy::class);
        Gate::policy(TrackedProperty::class, TrackedPropertyPolicy::class);

        Gate::define('audit', static fn (mixed $user, mixed $resource): bool => $user instanceof User
            && $user->hasAnyRole(['super_admin', 'admin', 'moderator']));

        Gate::define('restoreAudit', static fn (mixed $user, mixed $resource): bool => false);

        $componentManager = ComponentManager::resolve();

        if (self::$mediaUploadConfiguredFor !== $componentManager) {
            // Configure SpatieMediaLibraryFileUpload to use slug-based filenames globally.
            // This runs after the model is saved so $record always has a slug/name.
            SpatieMediaLibraryFileUpload::configureUsing(function (SpatieMediaLibraryFileUpload $upload): void {
                $maxUploadSizeKb = (int) ceil(((int) config('media-library.max_file_size', 10 * 1024 * 1024)) / 1024);

                $upload
                    ->placeholder(__('filament-forms::components.file_upload.placeholder'))
                    ->maxSize($maxUploadSizeKb)
                    ->maxParallelUploads(2)
                    ->appendFiles()
                    ->customHeaders([
                        'CacheControl' => 'public, max-age=31536000, immutable',
                    ]);

                // The package default uses load('media') for every upload field.
                // A form with several collections then reloads the same relation
                // once per field. Keep the relation request-local and already
                // loaded for the preview URL resolver as well.
                $upload->loadStateFromRelationshipsUsing(
                    static function (SpatieMediaLibraryFileUpload $component, HasMedia $record): void {
                        /** @var Model&HasMedia $record */
                        $record->loadMissing('media');

                        $media = $record->getMedia($component->getCollection() ?? 'default')
                            ->when(
                                $component->hasMediaFilter(),
                                fn (Collection $media) => $component->filterMedia($media),
                            )
                            ->when(
                                ! $component->isMultiple(),
                                fn (Collection $media): Collection => $media->take(1),
                            )
                            ->mapWithKeys(function (Media $media): array {
                                $uuid = $media->getAttributeValue('uuid');

                                return [$uuid => $uuid];
                            })
                            ->toArray();

                        $component->rawState($media);
                    },
                );

                $upload->getUploadedFileNameForStorageUsing(
                    static function (SpatieMediaLibraryFileUpload $component, TemporaryUploadedFile $file): string {
                        $record = $component->getRecord();
                        $extension = $file->getClientOriginalExtension();
                        $baseName = MediaFileNamer::resolveBaseNameFromModel($record);

                        $suffix = strtolower(substr((string) Str::ulid(), -8));

                        return "{$baseName}-{$suffix}.{$extension}";
                    }
                );

                $upload->mediaName(
                    static fn (TemporaryUploadedFile $file): string => MediaFileNamer::resolveDisplayNameFromModel(
                        $upload->getRecord(),
                        $upload->getCollection() ?? 'media',
                        $file->getClientOriginalName(),
                    )
                );

                $upload->customProperties(
                    static fn (TemporaryUploadedFile $file): array => [
                        'collection' => $upload->getCollection() ?? 'default',
                        'original_file_name' => $file->getClientOriginalName(),
                    ]
                );
            });
            self::$mediaUploadConfiguredFor = $componentManager;
        }
    }

    private function registerModelObservers(): void
    {
        $registrationKey = 'ilmu360.model_observers.registered';

        if (app()->bound($registrationKey)) {
            return;
        }

        Event::observe(EventLifecycleObserver::class);
        Event::observe(EventLocationSyncObserver::class);
        Event::observe(EventObserver::class);
        EventTerm::observe(EventTermObserver::class);
        Address::observe(AddressObserver::class);
        Institution::observe(InstitutionObserver::class);
        Institution::observe(InstitutionImportExclusionObserver::class);
        AddressArea::observe(AddressAreaObserver::class);
        AddressAreaRelationship::observe(AddressAreaRelationshipObserver::class);
        Addressable::observe(AddressableObserver::class);
        AddressCountry::observe(AddressCountryObserver::class);
        City::observe(AddressCatalogObserver::class);
        State::observe(AddressCatalogObserver::class);
        EventKeyPerson::observe(EventKeyPersonObserver::class);
        EventOccurrence::observe(EventOccurrenceObserver::class);
        EventOccurrence::observe(EventOccurrenceFeaturedObserver::class);
        EventTimeExpression::observe(EventTimeExpressionObserver::class);
        EventTaxonomy::observe(EventTaxonomyObserver::class);
        Language::observe(LanguageObserver::class);
        CommerceLanguage::observe(LanguageObserver::class);
        Person::observe(PersonLifecycleObserver::class);
        Person::observe(PersonObserver::class);
        PersonName::observe(PersonNameObserver::class);
        PackagePersonName::observe(PersonNameObserver::class);
        Title::observe(PersonTitleObserver::class);
        TitleAssignment::observe(PersonTitleObserver::class);
        TitleCategory::observe(TitleCategoryObserver::class);
        Reference::observe(ReferenceObserver::class);
        ReferenceContributor::observe(ReferenceContributorObserver::class);
        Venue::observe(VenueObserver::class);
        ShareSourceAttributionReceipt::observe(ShareSourceAttributionReceiptObserver::class);
        ShareOutcomeCorrection::observe(ShareOutcomeCorrectionObserver::class);

        app()->instance($registrationKey, true);
    }

    private function registerPublicSlugRegistry(): void
    {
        $this->app->singleton(PublicSlugRegistry::class, static fn (): PublicSlugRegistry => new PublicSlugRegistry([
            new PublicSlugResource(
                parameter: 'institution',
                routeName: 'institutions.show',
                modelClass: Institution::class,
                searchColumns: ['name', 'slug'],
                labelAttribute: 'name',
            ),
            new PublicSlugResource(
                parameter: 'person',
                routeName: 'persons.show',
                modelClass: Person::class,
                searchColumns: ['name', 'slug'],
                labelAttribute: 'formatted_name',
            ),
        ]));
    }

    private function registerPublicSlugBindings(): void
    {
        foreach (self::PUBLIC_SLUG_PARAMETERS as $publicSlugParameter) {
            Route::bind($publicSlugParameter, function (mixed $value) use ($publicSlugParameter) {
                if (! is_string($value) || trim($value) === '') {
                    throw new ModelNotFoundException;
                }

                $value = trim($value);
                $resource = app(PublicSlugRegistry::class)->resourceForParameter($publicSlugParameter);

                // A current canonical slug is already resolved by this model
                // lookup. Reuse that hydrated model for route binding instead
                // of resolving it to a scalar ID and querying it again.
                if ($resource instanceof PublicSlugResource) {
                    $prototype = new $resource->modelClass;
                    $currentModel = app(SlugOrUuidResolver::class)->first(
                        $prototype->newQuery(),
                        $prototype->qualifyColumn('slug'),
                        $value,
                    );

                    if ($currentModel instanceof Model) {
                        request()->attributes->set("public_slug_resolution.{$publicSlugParameter}", [
                            'model' => $currentModel,
                            'redirect_to' => null,
                            'slug_redirect_id' => null,
                        ]);

                        return $currentModel;
                    }
                }

                // Only unresolved identifiers need the SlugRedirects lookup.
                $resolved = app(PublicSlugLookup::class)->resolve($publicSlugParameter, $value);
                $resource = app(PublicSlugRegistry::class)->resourceForType($resolved->modelType);

                if (! $resource instanceof PublicSlugResource) {
                    throw new ModelNotFoundException;
                }

                $model = $resource->modelClass::query()->findOrFail($resolved->modelId);

                request()->attributes->set("public_slug_resolution.{$publicSlugParameter}", [
                    'model' => $model,
                    'redirect_to' => $resolved->redirectTo,
                    'slug_redirect_id' => $resolved->slugRedirectId,
                ]);

                return $model;
            });
        }

        if ($this->app->runningUnitTests()) {
            ParallelTesting::setUpProcess(function (int $token): void {
                if (config('database.default') !== 'pgsql') {
                    return;
                }

                $database = "test_test_{$token}";

                config()->set('database.connections.pgsql.database', $database);
                DB::purge('pgsql');
                DB::reconnect('pgsql');
            });
        }
    }
}
