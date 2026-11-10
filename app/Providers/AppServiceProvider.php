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
use AIArmada\Affiliates\Models\AffiliateTouchpoint;
use AIArmada\Checkout\Contracts\StepContributor;
use AIArmada\Checkout\Models\CheckoutSession;
use AIArmada\CommerceSupport\Models\Language as CommerceLanguage;
use AIArmada\Communications\Contracts\ConsentResolver;
use AIArmada\Communications\Contracts\DestinationResolver;
use AIArmada\Communications\Contracts\PayloadRedactor;
use AIArmada\Communications\Contracts\PreferenceResolver;
use AIArmada\Communications\Contracts\QuietHoursResolver;
use AIArmada\Communications\Contracts\SuppressionResolver;
use AIArmada\Contacting\Models\ContactMethod;
use AIArmada\Contacting\Models\SocialProfile;
use AIArmada\Customers\Models\Customer;
use AIArmada\Events\Events\EventCancelled;
use AIArmada\Events\Events\EventOccurrenceCancelled;
use AIArmada\Events\Events\EventRegistrationCancelled;
use AIArmada\Events\Events\EventRegistrationRefunded;
use AIArmada\Events\Events\EventRegistrationRejected;
use AIArmada\Events\Events\EventSessionCancelled;
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
use AIArmada\Organizations\Contracts\CurrentOrganizationResolver;
use AIArmada\Organizations\Models\Organization;
use AIArmada\Persons\Models\PersonName as PackagePersonName;
use AIArmada\Persons\Models\Title;
use AIArmada\Persons\Models\TitleAssignment;
use AIArmada\Persons\Models\TitleCategory;
use AIArmada\References\Models\ReferenceContributor;
use AIArmada\Rewards\Contracts\RewardBenefitCatalog;
use AIArmada\Rewards\Contracts\RewardBenefitCouponFulfillment;
use AIArmada\Rewards\Contracts\RewardCorrectionKeySource;
use AIArmada\Rewards\Contracts\RewardFinancialReadinessChecker;
use AIArmada\Rewards\Contracts\RewardGratitudePlanner;
use AIArmada\Rewards\Contracts\RewardReadinessChecker;
use AIArmada\Rewards\Contracts\RewardReservationResolver;
use AIArmada\Rewards\Contracts\RewardSourceFactReader;
use AIArmada\Rewards\Models\RewardAppeal;
use AIArmada\Rewards\Models\RewardAward;
use AIArmada\Rewards\Models\RewardBenefitGrant;
use AIArmada\Rewards\Models\RewardBenefitItem;
use AIArmada\Rewards\Models\RewardFundingEntry;
use AIArmada\Rewards\Models\RewardPayee;
use AIArmada\Rewards\Models\RewardPayoutRequest;
use AIArmada\Rewards\Models\RewardProgram;
use AIArmada\Rewards\Models\RewardRecovery;
use AIArmada\Rewards\Support\FundedRewardFinancialReadinessChecker;
use AIArmada\Rewards\Support\FundedRewardReadinessChecker;
use AIArmada\Rewards\Support\PointLedgerRewardReservationResolver;
use AIArmada\Rewards\Support\RewardBenefitCatalogService;
use AIArmada\Signals\Models\TrackedProperty;
use AIArmada\Ticketing\Models\TicketType;
use App\Actions\Events\Submission\ClaimGuestSubmissionsAction;
use App\Actions\Slugs\ResolvePublicSlugAction;
use App\Ai\Listeners\RecordAiUsage;
use App\Checkout\ShareCheckoutStepContributor;
use App\Contracts\CaptchaVerifier;
use App\Contracts\EventCategoryCatalog;
use App\Contracts\EventCategoryPolicyResolver;
use App\Contracts\NullCaptchaVerifier;
use App\Contracts\ShareTrackingContract;
use App\Contracts\SpaceEligibilityResolver;
use App\Features\Directory\Api\DirectoryAdminContract;
use App\Features\Directory\Api\DirectoryCommands;
use App\Features\Directory\Api\DirectoryReader;
use App\Features\Events\Api\GuestSubmissionClaims;
use App\Features\SavedSearches\Api\SavedSearchExecutor;
use App\Http\Controllers\Mcp\OAuthRegisterController;
use App\Listeners\ShareTracking\ReverseRegistrationShareOutcome;
use App\Listeners\ShareTracking\ReverseScheduleCancellationShareOutcome;
use App\Models\Affiliation;
use App\Models\AiModelPricing;
use App\Models\Audit as FilamentAudit;
use App\Models\ContributionRequest;
use App\Models\DonationChannel;
use App\Models\Event;
use App\Models\EventKeyPerson;
use App\Models\EventSubmission;
use App\Models\Institution;
use App\Models\InstitutionImportExclusion;
use App\Models\InstitutionName;
use App\Models\Language;
use App\Models\MediaLink;
use App\Models\MemberInvitation;
use App\Models\MembershipApplication;
use App\Models\ModerationReview;
use App\Models\Person;
use App\Models\PersonName;
use App\Models\Reference;
use App\Models\Registration;
use App\Models\Series;
use App\Models\ShareOutcomeCorrection;
use App\Models\ShareSourceAttributionReceipt;
use App\Models\Space;
use App\Models\User;
use App\Models\Venue;
use App\Observers\AddressableObserver;
use App\Observers\AddressAreaObserver;
use App\Observers\AddressAreaRelationshipObserver;
use App\Observers\AddressCatalogObserver;
use App\Observers\AddressCountryObserver;
use App\Observers\AddressObserver;
use App\Observers\CommittedShareTouchpointObserver;
use App\Observers\EventKeyPersonObserver;
use App\Observers\EventLocationSyncObserver;
use App\Observers\EventObserver;
use App\Observers\EventOccurrenceFeaturedObserver;
use App\Observers\EventOccurrenceObserver;
use App\Observers\EventTaxonomyObserver;
use App\Observers\EventTermObserver;
use App\Observers\EventTimeExpressionObserver;
use App\Observers\InstitutionImportExclusionObserver;
use App\Observers\InstitutionObserver;
use App\Observers\LanguageObserver;
use App\Observers\PersonNameObserver;
use App\Observers\PersonObserver;
use App\Observers\PersonTitleObserver;
use App\Observers\ReferenceContributorObserver;
use App\Observers\ReferenceObserver;
use App\Observers\ShareOutcomeCorrectionObserver;
use App\Observers\ShareSourceAttributionReceiptObserver;
use App\Observers\TitleCategoryObserver;
use App\Observers\UserObserver;
use App\Observers\VenueObserver;
use App\Organizations\CurrentOrganizationResolver as AppCurrentOrganizationResolver;
use App\Platform\Api\ApiErrorDetailsEnricher;
use App\Platform\Auth\EloquentAccountAccess;
use App\Platform\Auth\EloquentAccountDirectory;
use App\Platform\Auth\EloquentAccountSettingsStore;
use App\Platform\Auth\EloquentUserMorphType;
use App\Platform\Media\EloquentMediaCollectionSync;
use App\Platform\Media\MediaCollectionSync;
use App\Platform\Media\MediaFileNamer;
use App\Platform\Routing\PublicSlugRegistry;
use App\Platform\Routing\PublicSlugResource;
use App\Platform\Search\ResourceSearchAdapter;
use App\Platform\Telemetry\ProductSignalRecorder;
use App\Platform\Time\DefaultUserDateTimeFormatter;
use App\Policies\AddressAreaPolicy;
use App\Policies\AddressCountryPolicy;
use App\Policies\EventPolicy;
use App\Policies\FilamentAuditPolicy;
use App\Policies\RegistrationPolicy;
use App\Policies\RewardAppealPolicy;
use App\Policies\RewardAwardPolicy;
use App\Policies\RewardBenefitGrantPolicy;
use App\Policies\RewardBenefitItemPolicy;
use App\Policies\RewardFundingEntryPolicy;
use App\Policies\RewardPayeePolicy;
use App\Policies\RewardPayoutRequestPolicy;
use App\Policies\RewardProgramPolicy;
use App\Policies\RewardRecoveryPolicy;
use App\Services\Captcha\TurnstileVerifier;
use App\Services\EventCategoryCatalog as DefaultEventCategoryCatalog;
use App\Services\EventCategoryPolicy;
use App\Services\ShareTrackingService;
use App\Services\Spaces\DefaultSpaceEligibilityResolver;
use App\Shared\Auth\AccountAccess;
use App\Shared\Auth\AccountDirectory;
use App\Shared\Auth\AccountLifecycleContributor;
use App\Shared\Auth\AccountSettingsStore;
use App\Shared\Auth\NullSignupAttributionSourceResolver;
use App\Shared\Auth\SignupAttributionContextResolver;
use App\Shared\Auth\SignupAttributionSourceResolver;
use App\Shared\Auth\UserMorphType;
use App\Shared\Communications\AccountNotificationSettings;
use App\Shared\Contributions\ContributionRequestReader;
use App\Shared\Directory\DirectoryCatalog;
use App\Shared\Directory\PrayerInstitutionCatalog;
use App\Shared\Directory\ReferenceScopedSearch;
use App\Shared\Directory\SubmissionEligibilitySync;
use App\Shared\Discovery\SearchFilterCatalog;
use App\Shared\Discovery\SearchFilterLabels;
use App\Shared\Discovery\SearchFilterNormalizer;
use App\Shared\Discovery\SearchFilterRules;
use App\Shared\Engagement\EngagementReader;
use App\Shared\Events\AccountEventFacts;
use App\Shared\Location\VisitorCountryResolving;
use App\Shared\Membership\MembershipApplicationReader;
use App\Shared\Membership\MembershipAuthorization;
use App\Shared\Membership\MembershipDirectory;
use App\Shared\Reports\ReportEscalation;
use App\Shared\Reports\ReportSubjectResolver;
use App\Shared\ShareTracking\GuestClaims;
use App\Shared\ShareTracking\SavedSearchShareOutcomes;
use App\Shared\ShareTracking\SignupRecorder;
use App\Shared\Signals\SignalRecorder;
use App\Shared\Time\UserDateTimeFormatting;
use App\Support\Ai\AiAccountLifecycleContributor;
use App\Support\Api\Admin\AdminValidateOnlyRemediationEnricher;
use App\Support\Auth\AffiliateSignupAttributionSourceResolver;
use App\Support\Auth\SignupAttributionContextResolverAdapter;
use App\Support\Authz\InterimMembershipAuthorization;
use App\Support\Authz\InterimMembershipDirectory;
use App\Support\Communications\AccountNotificationSettingsAdapter;
use App\Support\Communications\AppConsentResolver;
use App\Support\Communications\AppPreferenceResolver;
use App\Support\Communications\AppQuietHoursResolver;
use App\Support\Communications\AppSuppressionResolver;
use App\Support\Communications\CommunicationsAccountLifecycleContributor;
use App\Support\Communications\ListSafePayloadRedactor;
use App\Support\Communications\ProbeSafeDestinationResolver;
use App\Support\Contributions\ContributionsAccountLifecycleContributor;
use App\Support\Contributions\EloquentContributionRequestReader;
use App\Support\Directory\DirectoryAccountLifecycleContributor;
use App\Support\Directory\EloquentDirectoryAdmin;
use App\Support\Directory\EloquentDirectoryCatalog;
use App\Support\Directory\EloquentDirectoryCommands;
use App\Support\Directory\EloquentDirectoryReader;
use App\Support\Directory\EloquentPrayerInstitutionCatalog;
use App\Support\Discovery\DatabaseSearchFilterLabels;
use App\Support\Discovery\EventSavedSearchExecutor;
use App\Support\Discovery\EventSearchFilterCatalog;
use App\Support\Discovery\EventSearchFilterNormalizer;
use App\Support\Discovery\EventSearchFilterRules;
use App\Support\Engagement\EloquentEngagementReader;
use App\Support\Engagement\EngagementAccountLifecycleContributor;
use App\Support\Events\EloquentAccountEventFacts;
use App\Support\Events\EventsAccountLifecycleContributor;
use App\Support\Filament\ContentVersionedJs;
use App\Support\Location\VisitorCountryResolver;
use App\Support\Membership\AppMembershipApplicationNotifier;
use App\Support\Membership\AppMembershipHook;
use App\Support\Membership\EloquentMembershipApplicationReader;
use App\Support\Membership\MembershipAccountLifecycleContributor;
use App\Support\Moderation\ModerationAccountLifecycleContributor;
use App\Support\Passport\PassportKeyProvisioner;
use App\Support\Reports\CompositeReportSubjectResolver;
use App\Support\Reports\GlobalReportEscalation;
use App\Support\Rewards\CompositeRewardSourceFactReader;
use App\Support\Rewards\DigestRewardGratitudePlanner;
use App\Support\Rewards\ShareCorrectionKeySource;
use App\Support\Rewards\VouchersRewardBenefitCouponFulfillment;
use App\Support\Search\Adapters\InstitutionResourceSearchAdapter;
use App\Support\Search\Adapters\PersonResourceSearchAdapter;
use App\Support\Search\ReferenceSearchService;
use App\Support\ShareTracking\GuestClaimLookup;
use App\Support\ShareTracking\SavedSearchOutcomeRecorder;
use App\Support\ShareTracking\ShareTrackingSignupRecorder;
use App\Support\Submission\PublicSubmissionEligibilitySync;
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
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\AudioGenerated;
use Laravel\Ai\Events\EmbeddingsGenerated;
use Laravel\Ai\Events\ImageGenerated;
use Laravel\Ai\Events\Reranked;
use Laravel\Ai\Events\TranscriptionGenerated;
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
    private const PUBLIC_SLUG_PARAMETERS = ['event', 'institution', 'person', 'venue', 'reference'];

    /**
     * Register any application services.
     */
    #[\Override]
    public function register(): void
    {
        $this->app->bind(AccountAccess::class, EloquentAccountAccess::class);
        $this->app->bind(AccountDirectory::class, EloquentAccountDirectory::class);
        $this->app->bind(AccountSettingsStore::class, EloquentAccountSettingsStore::class);
        $this->app->bind(SignupAttributionContextResolver::class, SignupAttributionContextResolverAdapter::class);
        $this->app->bind(GuestClaims::class, GuestClaimLookup::class);
        $this->app->bind(SignupRecorder::class, ShareTrackingSignupRecorder::class);
        $this->app->bind(AccountNotificationSettings::class, AccountNotificationSettingsAdapter::class);
        $this->app->bind(PrayerInstitutionCatalog::class, EloquentPrayerInstitutionCatalog::class);
        $this->app->bind(UserMorphType::class, EloquentUserMorphType::class);
        $this->app->bind(MembershipDirectory::class, InterimMembershipDirectory::class);
        $this->app->bind(MembershipAuthorization::class, InterimMembershipAuthorization::class);
        $this->app->bind(MembershipApplicationReader::class, EloquentMembershipApplicationReader::class);
        $this->app->bind(DirectoryCatalog::class, EloquentDirectoryCatalog::class);
        $this->app->bind(DirectoryReader::class, EloquentDirectoryReader::class);
        $this->app->bind(DirectoryCommands::class, EloquentDirectoryCommands::class);
        $this->app->bind(DirectoryAdminContract::class, EloquentDirectoryAdmin::class);
        $this->app->bind(SubmissionEligibilitySync::class, PublicSubmissionEligibilitySync::class);
        $this->app->bind(AccountEventFacts::class, EloquentAccountEventFacts::class);
        $this->app->bind(ContributionRequestReader::class, EloquentContributionRequestReader::class);
        $this->app->bind(EngagementReader::class, EloquentEngagementReader::class);
        $this->app->bind(GuestSubmissionClaims::class, ClaimGuestSubmissionsAction::class);
        $this->app->tag([
            MembershipAccountLifecycleContributor::class,
            DirectoryAccountLifecycleContributor::class,
            EventsAccountLifecycleContributor::class,
            ContributionsAccountLifecycleContributor::class,
            ModerationAccountLifecycleContributor::class,
            EngagementAccountLifecycleContributor::class,
            AiAccountLifecycleContributor::class,
            CommunicationsAccountLifecycleContributor::class,
        ], AccountLifecycleContributor::TAG);

        $this->app->bind(ReportEscalation::class, GlobalReportEscalation::class);
        $this->app->bind(ReportSubjectResolver::class, CompositeReportSubjectResolver::class);
        $this->app->bind(SearchFilterNormalizer::class, EventSearchFilterNormalizer::class);
        $this->app->bind(SearchFilterCatalog::class, EventSearchFilterCatalog::class);
        $this->app->bind(SearchFilterRules::class, EventSearchFilterRules::class);
        $this->app->bind(SavedSearchExecutor::class, EventSavedSearchExecutor::class);
        $this->app->bind(SavedSearchShareOutcomes::class, SavedSearchOutcomeRecorder::class);
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

        $this->app->singleton(EventCategoryCatalog::class, DefaultEventCategoryCatalog::class);
        $this->app->singleton(EventCategoryPolicyResolver::class, EventCategoryPolicy::class);
        $this->app->singleton(SpaceEligibilityResolver::class, DefaultSpaceEligibilityResolver::class);

        $this->registerPublicSlugRegistry();

        $this->app->singleton(PrettyPrinter::class, PrettyPrinter\Standard::class);
        $this->app->bind(McpOAuthRegisterController::class, OAuthRegisterController::class);

        $this->app->bind(
            QuietHoursResolver::class,
            AppQuietHoursResolver::class,
        );

        $this->app->bind(
            PreferenceResolver::class,
            AppPreferenceResolver::class,
        );

        $this->app->bind(
            ConsentResolver::class,
            AppConsentResolver::class,
        );

        $this->app->bind(
            SuppressionResolver::class,
            AppSuppressionResolver::class,
        );

        $this->app->singleton(
            PayloadRedactor::class,
            ListSafePayloadRedactor::class,
        );

        $this->app->bind(
            DestinationResolver::class,
            ProbeSafeDestinationResolver::class,
        );

        $filamentAuditingViews = base_path('vendor/tapp/filament-auditing/resources/views');

        if (is_dir($filamentAuditingViews)) {
            $this->loadViewsFrom($filamentAuditingViews, 'filament-auditing');
        }

        $this->app->singleton(MembershipHook::class, AppMembershipHook::class);
        $this->app->singleton(MembershipApplicationNotifier::class, AppMembershipApplicationNotifier::class);
        $this->app->singleton(CurrentOrganizationResolver::class, AppCurrentOrganizationResolver::class);
        config(['organizations.resolver' => AppCurrentOrganizationResolver::class]);

        $this->app->bind(
            function ($app): CaptchaVerifier {
                $verifier = $app->make(TurnstileVerifier::class);

                return $verifier->isEnabled()
                    ? $verifier
                    : $app->make(NullCaptchaVerifier::class);
            },
        );

        // Prefer concrete facade for constructor injection of ShareTrackingService;
        // also bind the contract so optional integrations can type-hint ShareTrackingContract.
        $this->app->singleton(ShareTrackingContract::class, ShareTrackingService::class);
        $this->app->bind(SignupAttributionSourceResolver::class, class_exists(Affiliate::class)
            ? AffiliateSignupAttributionSourceResolver::class
            : NullSignupAttributionSourceResolver::class);

        // Reward host adapters (stateless; safe to share under Octane).
        // Finance scope binds funded readiness: money/benefit activation
        // proves funding, verified payees, and supported processors from
        // immutable rows, and keeps refusing anything it cannot prove.
        // Payout execution uses the package's manual operator rail with
        // the methods configured in config/rewards.php.
        $this->app->singleton(RewardSourceFactReader::class, CompositeRewardSourceFactReader::class);
        $this->app->singleton(RewardReservationResolver::class, PointLedgerRewardReservationResolver::class);
        $this->app->singleton(RewardReadinessChecker::class, FundedRewardReadinessChecker::class);
        $this->app->singleton(RewardFinancialReadinessChecker::class, FundedRewardFinancialReadinessChecker::class);
        $this->app->singleton(RewardBenefitCatalog::class, RewardBenefitCatalogService::class);
        $this->app->singleton(RewardBenefitCouponFulfillment::class, VouchersRewardBenefitCouponFulfillment::class);
        $this->app->singleton(RewardCorrectionKeySource::class, ShareCorrectionKeySource::class);
        $this->app->singleton(RewardGratitudePlanner::class, DigestRewardGratitudePlanner::class);

        if (interface_exists(StepContributor::class)) {
            $this->app->tag(ShareCheckoutStepContributor::class, 'checkout.steps');
        }

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

        if (! app()->bound('share.schedule_reversal.listeners.registered')) {
            EventFacade::listen(EventCancelled::class, [ReverseScheduleCancellationShareOutcome::class, 'handle']);
            EventFacade::listen(EventOccurrenceCancelled::class, [ReverseScheduleCancellationShareOutcome::class, 'handle']);
            EventFacade::listen(EventSessionCancelled::class, [ReverseScheduleCancellationShareOutcome::class, 'handle']);

            app()->instance('share.schedule_reversal.listeners.registered', true);
        }

        if (! app()->bound('ai.usage.listeners.registered')) {
            EventFacade::listen(AgentPrompted::class, [RecordAiUsage::class, 'handle']);
            EventFacade::listen(AgentStreamed::class, [RecordAiUsage::class, 'handle']);
            EventFacade::listen(ImageGenerated::class, [RecordAiUsage::class, 'handle']);
            EventFacade::listen(TranscriptionGenerated::class, [RecordAiUsage::class, 'handle']);
            EventFacade::listen(EmbeddingsGenerated::class, [RecordAiUsage::class, 'handle']);
            EventFacade::listen(Reranked::class, [RecordAiUsage::class, 'handle']);
            EventFacade::listen(AudioGenerated::class, [RecordAiUsage::class, 'handle']);

            app()->instance('ai.usage.listeners.registered', true);
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

        // Merged map, not enforced: legacy organizer-involvement rows may still
        // store fully-qualified class names (normalized to aliases by migration
        // + MorphAlias-tolerant resolvers; see EventOrganizerInvolvementSyncTest).
        // Writes canonicalize through getMorphClass().
        Relation::morphMap([
            'address' => Address::class,

            'ai_model_pricing' => AiModelPricing::class,
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

        Gate::policy(RewardAppeal::class, RewardAppealPolicy::class);
        Gate::policy(RewardAward::class, RewardAwardPolicy::class);
        Gate::policy(RewardBenefitGrant::class, RewardBenefitGrantPolicy::class);
        Gate::policy(RewardBenefitItem::class, RewardBenefitItemPolicy::class);
        Gate::policy(RewardFundingEntry::class, RewardFundingEntryPolicy::class);
        Gate::policy(RewardPayee::class, RewardPayeePolicy::class);
        Gate::policy(RewardPayoutRequest::class, RewardPayoutRequestPolicy::class);
        Gate::policy(RewardProgram::class, RewardProgramPolicy::class);
        Gate::policy(RewardRecovery::class, RewardRecoveryPolicy::class);

        Gate::define('audit', static fn (mixed $user, mixed $resource): bool => $user instanceof User
            && $user->hasAnyRole(['super_admin', 'admin', 'moderator']));

        Gate::define('restoreAudit', static fn (mixed $user, mixed $resource): bool => false);

        // Fraud stewardship is authorized separately from award approval:
        // stewards review fraud evidence, never approve awards.
        Gate::define('reward-fraud-steward', static fn (mixed $user): bool => $user instanceof User
            && $user->hasAnyRole(['super_admin', 'moderator']));

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
        AffiliateTouchpoint::observe(CommittedShareTouchpointObserver::class);

        app()->instance($registrationKey, true);
    }

    private function registerPublicSlugRegistry(): void
    {
        $this->app->singleton(PublicSlugRegistry::class, static fn (): PublicSlugRegistry => new PublicSlugRegistry([
            new PublicSlugResource(
                parameter: 'event',
                routeName: 'events.show',
                modelClass: Event::class,
                requiresVisitedSource: false,
                searchColumns: ['title', 'slug'],
                labelAttribute: 'title',
            ),
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
            new PublicSlugResource(
                parameter: 'venue',
                routeName: 'venues.show',
                modelClass: Venue::class,
                searchColumns: ['name', 'slug'],
                labelAttribute: 'name',
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

                $resolved = app(ResolvePublicSlugAction::class)->handle($publicSlugParameter, trim($value));

                request()->attributes->set("public_slug_resolution.{$publicSlugParameter}", $resolved);

                return $resolved['model'];
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
