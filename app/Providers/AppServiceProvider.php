<?php

namespace App\Providers;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\Addressable;
use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\AddressAreaRelationship;
use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\Addressing\Models\City;
use AIArmada\Addressing\Models\State;
use AIArmada\CommerceSupport\Models\Language as CommerceLanguage;
use AIArmada\Communications\Contracts\ConsentResolver;
use AIArmada\Communications\Contracts\PreferenceResolver;
use AIArmada\Communications\Contracts\QuietHoursResolver;
use AIArmada\Communications\Contracts\SuppressionResolver;
use AIArmada\Contacting\Models\ContactMethod;
use AIArmada\Contacting\Models\SocialProfile;
use AIArmada\Events\Models\EventAccessPolicy;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventRegistrationParticipant;
use AIArmada\Events\Models\EventSession;
use AIArmada\Events\Models\EventTaxonomy;
use AIArmada\Events\Models\EventTerm;
use AIArmada\Events\Models\EventTimeExpression;
use AIArmada\FilamentSignals\Policies\TrackedPropertyPolicy;
use AIArmada\Inventory\Models\InventoryLevel;
use AIArmada\Inventory\Models\InventoryLocation;
use AIArmada\Membership\Contracts\MembershipApplicationNotifier;
use AIArmada\Membership\Contracts\MembershipHook;
use AIArmada\Organizations\Contracts\CurrentOrganizationResolver;
use AIArmada\Organizations\Models\Organization;
use AIArmada\Persons\Models\PersonName;
use AIArmada\Persons\Models\Title;
use AIArmada\Persons\Models\TitleAssignment;
use AIArmada\Persons\Models\TitleCategory;
use AIArmada\Signals\Models\TrackedProperty;
use AIArmada\Ticketing\Models\TicketType;
use App\Actions\Slugs\ResolvePublicSlugAction;
use App\Ai\Listeners\RecordAiUsage;
use App\Contracts\CaptchaVerifier;
use App\Contracts\EventCategoryCatalog;
use App\Contracts\EventCategoryPolicyResolver;
use App\Contracts\GitHubIssueReporterContract;
use App\Contracts\NullCaptchaVerifier;
use App\Contracts\NullGitHubIssueReporter;
use App\Contracts\ShareTrackingContract;
use App\Contracts\SpaceEligibilityResolver;
use App\Http\Controllers\Mcp\OAuthRegisterController;
use App\Models\AiModelPricing;
use App\Models\Audit as FilamentAudit;
use App\Models\ContributionRequest;
use App\Models\DonationChannel;
use App\Models\Event;
use App\Models\EventKeyPerson;
use App\Models\EventSubmission;
use App\Models\Inspiration;
use App\Models\Institution;
use App\Models\Language;
use App\Models\MediaLink;
use App\Models\MemberInvitation;
use App\Models\MembershipApplication;
use App\Models\ModerationReview;
use App\Models\Person;
use App\Models\Reference;
use App\Models\Registration;
use App\Models\Report;
use App\Models\Series;
use App\Models\Space;
use App\Models\User;
use App\Models\Venue;
use App\Observers\AddressableObserver;
use App\Observers\AddressAreaObserver;
use App\Observers\AddressAreaRelationshipObserver;
use App\Observers\AddressCatalogObserver;
use App\Observers\AddressCountryObserver;
use App\Observers\AddressObserver;
use App\Observers\EventKeyPersonObserver;
use App\Observers\EventObserver;
use App\Observers\EventOccurrenceObserver;
use App\Observers\EventTaxonomyObserver;
use App\Observers\EventTermObserver;
use App\Observers\EventTimeExpressionObserver;
use App\Observers\InstitutionObserver;
use App\Observers\LanguageObserver;
use App\Observers\PersonNameObserver;
use App\Observers\PersonObserver;
use App\Observers\PersonTitleObserver;
use App\Observers\ReferenceObserver;
use App\Observers\TitleCategoryObserver;
use App\Observers\VenueObserver;
use App\Organizations\CurrentOrganizationResolver as AppCurrentOrganizationResolver;
use App\Policies\AddressAreaPolicy;
use App\Policies\AddressCountryPolicy;
use App\Policies\EventPolicy;
use App\Policies\FilamentAuditPolicy;
use App\Services\Captcha\TurnstileVerifier;
use App\Services\EventCategoryCatalog as DefaultEventCategoryCatalog;
use App\Services\EventCategoryPolicy;
use App\Services\GitHub\GitHubIssueReporter;
use App\Services\ShareTrackingService;
use App\Services\Spaces\DefaultSpaceEligibilityResolver;
use App\Support\Communications\AppConsentResolver;
use App\Support\Communications\AppPreferenceResolver;
use App\Support\Communications\AppQuietHoursResolver;
use App\Support\Communications\AppSuppressionResolver;
use App\Support\Media\MediaFileNamer;
use App\Support\Membership\AppMembershipApplicationNotifier;
use App\Support\Membership\AppMembershipHook;
use App\Support\Passport\PassportKeyProvisioner;
use BezhanSalleh\LanguageSwitch\LanguageSwitch;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Support\Assets\Js;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentAsset;
use Filament\Support\Facades\FilamentColor;
use Filament\Tables\Filters\SelectFilter;
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

    protected static bool $mediaUploadConfigured = false;

    protected static bool $filamentColorsRegistered = false;

    protected static bool $publicSlugBindingsRegistered = false;

    /**
     * Register any application services.
     */
    #[\Override]
    public function register(): void
    {
        $this->app->singleton(EventCategoryCatalog::class, DefaultEventCategoryCatalog::class);
        $this->app->singleton(EventCategoryPolicyResolver::class, EventCategoryPolicy::class);
        $this->app->singleton(SpaceEligibilityResolver::class, DefaultSpaceEligibilityResolver::class);

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

        $filamentAuditingViews = base_path('vendor/tapp/filament-auditing/resources/views');

        if (is_dir($filamentAuditingViews)) {
            $this->loadViewsFrom($filamentAuditingViews, 'filament-auditing');
        }

        $this->app->singleton(MembershipHook::class, AppMembershipHook::class);
        $this->app->singleton(MembershipApplicationNotifier::class, AppMembershipApplicationNotifier::class);
        $this->app->singleton(CurrentOrganizationResolver::class, AppCurrentOrganizationResolver::class);

        $this->app->bind(
            function ($app): CaptchaVerifier {
                $verifier = $app->make(TurnstileVerifier::class);

                return $verifier->isEnabled()
                    ? $verifier
                    : $app->make(NullCaptchaVerifier::class);
            },
        );

        $this->app->singleton(
            function ($app): GitHubIssueReporterContract {
                $reporter = $app->make(GitHubIssueReporter::class);

                return $reporter->isConfigured()
                    ? $reporter
                    : $app->make(NullGitHubIssueReporter::class);
            },
        );

        // Prefer concrete facade for constructor injection of ShareTrackingService;
        // also bind the contract so optional integrations can type-hint ShareTrackingContract.
        $this->app->singleton(ShareTrackingContract::class, ShareTrackingService::class);

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

        // Event ownership is an application projection of the reusable
        // organization aggregate; the organizations package remains event-agnostic.
        Organization::resolveRelationUsing(
            'events',
            static fn (Organization $organization): MorphMany => $organization->morphMany(Event::class, 'owner'),
        );

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
                $switch->locales(['en', 'ms', 'ar', 'jv', 'ta', 'zh']);
            });
            self::$languageSwitchConfigured = true;
        }

        if (app()->runningUnitTests() || ! self::$publicSlugBindingsRegistered) {
            $this->registerPublicSlugBindings();

            if (! app()->runningUnitTests()) {
                self::$publicSlugBindingsRegistered = true;
            }
        }

        Relation::enforceMorphMap([
            'address' => Address::class,

            'ai_model_pricing' => AiModelPricing::class,
            'contact' => ContactMethod::class,
            'user' => User::class,
            'event' => Event::class,
            'event_access_policy' => EventAccessPolicy::class,
            'event_key_person' => EventKeyPerson::class,
            'event_occurrence' => EventOccurrence::class,
            'event_submission' => EventSubmission::class,
            'event_session' => EventSession::class,
            'contribution_request' => ContributionRequest::class,
            'event_registration_participant' => EventRegistrationParticipant::class,
            'membership_application' => MembershipApplication::class,
            'moderation_review' => ModerationReview::class,
            'institution' => Institution::class,
            'media_link' => MediaLink::class,
            'member_invitation' => MemberInvitation::class,
            'registration' => Registration::class,
            'person' => Person::class,
            'series' => Series::class,
            'social_media' => SocialProfile::class,
            'space' => Space::class,
            'venue' => Venue::class,
            'donation_channel' => DonationChannel::class,
            'reference' => Reference::class,
            'report' => Report::class,
            'inspiration' => Inspiration::class,
            'organization' => Organization::class,
            'inventory_location' => InventoryLocation::class,
            'inventory_level' => InventoryLevel::class,
            'ticket_type' => TicketType::class,
        ]);

        Gate::policy(FilamentAudit::class, FilamentAuditPolicy::class);
        Gate::policy(AddressArea::class, AddressAreaPolicy::class);
        Gate::policy(AddressCountry::class, AddressCountryPolicy::class);
        Gate::policy(Event::class, EventPolicy::class);
        Gate::policy(TrackedProperty::class, TrackedPropertyPolicy::class);

        Gate::define('audit', static fn (mixed $user, mixed $resource): bool => $user instanceof User
            && $user->hasAnyRole(['super_admin', 'admin', 'moderator']));

        Gate::define('restoreAudit', static fn (mixed $user, mixed $resource): bool => false);

        if (! self::$mediaUploadConfigured) {
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

                        // Append 8-char ULID suffix for uniqueness
                        $suffix = strtolower(substr(Str::ulid(), 0, 8));

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
            self::$mediaUploadConfigured = true;
        }
    }

    private function registerModelObservers(): void
    {
        $registrationKey = 'ilmu360.model_observers.registered';

        if (app()->bound($registrationKey)) {
            return;
        }

        Event::observe(EventObserver::class);
        EventTerm::observe(EventTermObserver::class);
        Address::observe(AddressObserver::class);
        AddressArea::observe(AddressAreaObserver::class);
        AddressAreaRelationship::observe(AddressAreaRelationshipObserver::class);
        Addressable::observe(AddressableObserver::class);
        AddressCountry::observe(AddressCountryObserver::class);
        City::observe(AddressCatalogObserver::class);
        State::observe(AddressCatalogObserver::class);
        EventKeyPerson::observe(EventKeyPersonObserver::class);
        EventOccurrence::observe(EventOccurrenceObserver::class);
        EventTimeExpression::observe(EventTimeExpressionObserver::class);
        EventTaxonomy::observe(EventTaxonomyObserver::class);
        Institution::observe(InstitutionObserver::class);
        Language::observe(LanguageObserver::class);
        CommerceLanguage::observe(LanguageObserver::class);
        Person::observe(PersonObserver::class);
        PersonName::observe(PersonNameObserver::class);
        Title::observe(PersonTitleObserver::class);
        TitleAssignment::observe(PersonTitleObserver::class);
        TitleCategory::observe(TitleCategoryObserver::class);
        Reference::observe(ReferenceObserver::class);
        Venue::observe(VenueObserver::class);

        app()->instance($registrationKey, true);
    }

    private function registerPublicSlugBindings(): void
    {
        foreach (['event', 'institution', 'person', 'venue', 'reference'] as $publicSlugParameter) {
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
