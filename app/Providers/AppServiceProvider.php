<?php

namespace App\Providers;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\Checkout\Models\CheckoutSession;
use AIArmada\Contacting\Models\ContactMethod;
use AIArmada\Contacting\Models\SocialProfile;
use AIArmada\Customers\Models\Customer;
use AIArmada\Events\Models\Event as PackageEvent;
use AIArmada\Events\Models\EventAccessPolicy;
use AIArmada\Events\Models\EventLocation;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventRegistrationParticipant;
use AIArmada\Events\Models\EventSession;
use AIArmada\FilamentSignals\Policies\TrackedPropertyPolicy;
use AIArmada\Inventory\Models\InventoryAllocation;
use AIArmada\Inventory\Models\InventoryLevel;
use AIArmada\Inventory\Models\InventoryLocation;
use AIArmada\Inventory\Models\InventoryMovement;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\Models\OrderItem;
use AIArmada\Orders\Models\OrderNote;
use AIArmada\Orders\Models\OrderPayment;
use AIArmada\Orders\Models\OrderRefund;
use AIArmada\Organizations\Models\Organization;
use AIArmada\Signals\Models\TrackedProperty;
use AIArmada\Ticketing\Models\TicketType;
use App\Features\Events\Actions\Delete\GuardEventOccurrenceDeletionAction;
use App\Features\Events\EntryPoints\Ui\EventJsonLd;
use App\Features\Events\Models\Event;
use App\Features\Events\Policies\EventPolicy;
use App\Features\Membership\Models\MemberInvitation;
use App\Features\Membership\Models\MembershipApplication;
use App\Features\Moderation\Models\ModerationReview;
use App\Features\Persons\Models\Affiliation;
use App\Features\Persons\Models\Person;
use App\Features\Persons\Models\PersonName;
use App\Features\Places\Models\Space;
use App\Features\Places\Models\Venue;
use App\Features\References\Models\Reference;
use App\Features\Registration\Models\EventCheckin;
use App\Features\Registration\Models\Registration;
use App\Features\Registration\Policies\RegistrationPolicy;
use App\Features\Rewards\Models\RewardDigestSlot;
use App\Features\ShareTracking\Models\ShareBrowserSelection;
use App\Features\ShareTracking\Models\ShareCohortProjection;
use App\Features\ShareTracking\Models\ShareEffectReceipt;
use App\Features\ShareTracking\Models\ShareSignalReaderCursor;
use App\Features\ShareTracking\Models\ShareSignalReaderSkip;
use App\Features\SlugRedirects\Api\PublicSlugLookup;
use App\Host\Affiliates\EloquentAffiliateSnapshotPersistence;
use App\Host\Pango\ShareCards\PangoShareCardRenderer;
use App\Host\Typesense\TypesenseHealthProbe;
use App\Models\User;
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
use App\Platform\Telemetry\EntryPoints\ApiDocumentation\MobileTelemetryRequestExampleContributor;
use App\Platform\Telemetry\ProductSignalRecorder;
use App\Platform\Telemetry\SignalSchemaRegistry;
use App\Platform\Time\DefaultUserDateTimeFormatter;
use App\Platform\Ui\ContentVersionedJs;
use App\Shared\Captcha\CaptchaVerifier;
use App\Shared\Contracts\AffiliateSnapshotPersistence;
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
        $this->app->bind(AffiliateSnapshotPersistence::class, EloquentAffiliateSnapshotPersistence::class);
        $this->app->singleton(
            ShareCardRenderer::class,
            static fn (): ShareCardRenderer => new PangoShareCardRenderer(public_path('fonts/share-cards')),
        );
        $this->app->bind(LanguageOptions::class, EloquentLanguageOptions::class);
        $this->app->bind(AddressCatalog::class, EloquentAddressCatalog::class);
        // Register before the final vendor EventOccurrence model boots so
        // integrity preflight precedes the vendor's cascading deleting hook.
        EventFacade::listen('eloquent.deleting: '.EventOccurrence::class, [GuardEventOccurrenceDeletionAction::class, 'handle']);

        $this->app->bind(AuditRecorder::class, EloquentAuditRecorder::class);
        $this->app->bind(LanguageCatalog::class, DatabaseLanguageCatalog::class);
        $this->app->singleton(SignalSchemaRegistry::class);
        $this->app->singleton(RequestExampleRegistry::class);
        $this->app->singleton(McpSurfaceRegistry::class);

        $this->app->bind(SignalRecorder::class, ProductSignalRecorder::class);
        $this->app->bind(VisitorCountryResolving::class, VisitorCountryResolver::class);
        $this->app->bind(UserDateTimeFormatting::class, DefaultUserDateTimeFormatter::class);
        $this->app->bind(MediaCollectionSync::class, EloquentMediaCollectionSync::class);
        $this->app->bind(ApiErrorDetailsEnricher::class, AdminValidateOnlyRemediationEnricher::class);
        $this->app->bind(Js::class, ContentVersionedJs::class);

        $this->app->singleton(PrettyPrinter::class, PrettyPrinter\Standard::class);
        $this->app->bind(McpOAuthRegisterController::class, OAuthRegisterController::class);

        $filamentAuditingViews = base_path('vendor/tapp/filament-auditing/resources/views');

        if (is_dir($filamentAuditingViews)) {
            $this->loadViewsFrom($filamentAuditingViews, 'filament-auditing');
        }

        $this->app->bind(
            function ($app): CaptchaVerifier {
                $verifier = $app->make(TurnstileVerifier::class);

                return $verifier->isEnabled()
                    ? $verifier
                    : $app->make(NullCaptchaVerifier::class);
            },
        );

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

        // Event ownership is an application projection of the reusable
        // organization aggregate; the organizations package remains event-agnostic.
        Organization::resolveRelationUsing(
            'events',
            static fn (Organization $organization): MorphMany => $organization->morphMany(Event::class, 'owner'),
        );

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
            'package_event' => PackageEvent::class,
            'event_access_policy' => EventAccessPolicy::class,
            'event_location' => EventLocation::class,
            'event_occurrence' => EventOccurrence::class,
            'event_session' => EventSession::class,
            'checkin' => EventCheckin::class,
            'checkout_session' => CheckoutSession::class,
            'order' => Order::class,
            'order_item' => OrderItem::class,
            'order_note' => OrderNote::class,
            'order_payment' => OrderPayment::class,
            'order_refund' => OrderRefund::class,
            'event_registration_participant' => EventRegistrationParticipant::class,
            'membership_application' => MembershipApplication::class,
            'moderation_review' => ModerationReview::class,
            'member_invitation' => MemberInvitation::class,
            'registration' => Registration::class,
            'person' => Person::class,
            'person_name' => PersonName::class,
            'affiliation' => Affiliation::class,
            'social_media' => SocialProfile::class,
            'space' => Space::class,
            'venue' => Venue::class,
            'language' => Language::class,
            'reference' => Reference::class,
            'share_browser_selection' => ShareBrowserSelection::class,
            'share_cohort_projection' => ShareCohortProjection::class,
            'share_effect_receipt' => ShareEffectReceipt::class,
            'share_signal_reader_cursor' => ShareSignalReaderCursor::class,
            'share_signal_reader_skip' => ShareSignalReaderSkip::class,
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
