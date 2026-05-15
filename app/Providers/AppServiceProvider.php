<?php

namespace App\Providers;

use AIArmada\Affiliates\Models\Affiliate;
use App\Actions\Slugs\ResolvePublicSlugAction;
use App\Ai\Listeners\RecordAiUsage;
use App\Http\Controllers\Mcp\OAuthRegisterController;
use App\Models\Address;
use App\Models\AiModelPricing;
use App\Models\Audit as FilamentAudit;
use App\Models\Contact;
use App\Models\ContributionRequest;
use App\Models\Country;
use App\Models\District;
use App\Models\DonationChannel;
use App\Models\Event;
use App\Models\EventKeyPerson;
use App\Models\EventSettings;
use App\Models\EventSubmission;
use App\Models\Inspiration;
use App\Models\Institution;
use App\Models\MediaLink;
use App\Models\MemberInvitation;
use App\Models\MembershipClaim;
use App\Models\ModerationReview;
use App\Models\Reference;
use App\Models\Registration;
use App\Models\Report;
use App\Models\Series;
use App\Models\SocialMedia;
use App\Models\Space;
use App\Models\Speaker;
use App\Models\State;
use App\Models\Subdistrict;
use App\Models\Tag;
use App\Models\User;
use App\Models\Venue;
use App\Observers\AddressObserver;
use App\Observers\EventKeyPersonObserver;
use App\Observers\EventObserver;
use App\Observers\GeographyObserver;
use App\Observers\InstitutionObserver;
use App\Observers\ReferenceObserver;
use App\Observers\SpeakerObserver;
use App\Observers\TagObserver;
use App\Observers\VenueObserver;
use App\Policies\FilamentAuditPolicy;
use App\Support\Media\MediaFileNamer;
use App\Support\Passport\PassportKeyProvisioner;
use BezhanSalleh\LanguageSwitch\LanguageSwitch;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Facades\Gate;
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

class AppServiceProvider extends ServiceProvider
{
    protected static bool $eventObserverRegistered = false;

    protected static bool $languageSwitchConfigured = false;

    protected static bool $mediaUploadConfigured = false;

    protected static bool $publicListingObserversRegistered = false;

    protected static bool $publicSlugBindingsRegistered = false;

    /**
     * Register any application services.
     */
    #[\Override]
    public function register(): void
    {
        $this->app->singleton(PrettyPrinter::class, PrettyPrinter\Standard::class);
        $this->app->bind(McpOAuthRegisterController::class, OAuthRegisterController::class);

        $filamentAuditingViews = base_path('vendor/tapp/filament-auditing/resources/views');
        $filamentSignalsViews = base_path('../commerce/packages/filament-signals/resources/views');

        if (is_dir($filamentAuditingViews)) {
            $this->loadViewsFrom($filamentAuditingViews, 'filament-auditing');
        }

        if (is_dir($filamentSignalsViews)) {
            $this->loadViewsFrom($filamentSignalsViews, 'filament-signals');
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

        $signalsRoutes = base_path('../commerce/packages/signals/routes/api.php');

        if (! $this->app->routesAreCached() && is_file($signalsRoutes) && ! app('router')->has('signals.collect.pageview')) {
            $this->loadRoutesFrom($signalsRoutes);
        }

        // Register custom scripts
        FilamentAsset::register([
            Js::make('close-on-select', __DIR__.'/../../resources/js/filament/close-on-select.js'),
            Js::make('user-timezone', __DIR__.'/../../resources/js/filament/user-timezone.js'),
        ]);

        if (app()->runningUnitTests() || ! self::$eventObserverRegistered) {
            Event::observe(EventObserver::class);

            if (! app()->runningUnitTests()) {
                self::$eventObserverRegistered = true;
            }
        }

        if (app()->runningUnitTests() || ! self::$publicListingObserversRegistered) {
            Address::observe(AddressObserver::class);
            EventKeyPerson::observe(EventKeyPersonObserver::class);
            Institution::observe(InstitutionObserver::class);
            Reference::observe(ReferenceObserver::class);
            Speaker::observe(SpeakerObserver::class);
            Venue::observe(VenueObserver::class);
            Tag::observe(TagObserver::class);
            Country::observe(GeographyObserver::class);
            State::observe(GeographyObserver::class);
            District::observe(GeographyObserver::class);
            Subdistrict::observe(GeographyObserver::class);

            if (! app()->runningUnitTests()) {
                self::$publicListingObserversRegistered = true;
            }
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
            'contact' => Contact::class,
            'user' => User::class,
            'event' => Event::class,
            'event_key_person' => EventKeyPerson::class,
            'event_submission' => EventSubmission::class,
            'event_settings' => EventSettings::class,
            'contribution_request' => ContributionRequest::class,
            'affiliate' => Affiliate::class,
            'membership_claim' => MembershipClaim::class,
            'moderation_review' => ModerationReview::class,
            'institution' => Institution::class,
            'media_link' => MediaLink::class,
            'member_invitation' => MemberInvitation::class,
            'registration' => Registration::class,
            'speaker' => Speaker::class,
            'series' => Series::class,
            'social_media' => SocialMedia::class,
            'space' => Space::class,
            'tag' => Tag::class,
            'venue' => Venue::class,
            'donation_channel' => DonationChannel::class,
            'reference' => Reference::class,
            'report' => Report::class,
            'inspiration' => Inspiration::class,
        ]);

        Gate::policy(FilamentAudit::class, FilamentAuditPolicy::class);

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

    private function registerPublicSlugBindings(): void
    {
        foreach (['event', 'institution', 'speaker', 'venue', 'reference'] as $publicSlugParameter) {
            Route::bind($publicSlugParameter, function (mixed $value) use ($publicSlugParameter) {
                if (! is_string($value) || trim($value) === '') {
                    throw new ModelNotFoundException;
                }

                $resolved = app(ResolvePublicSlugAction::class)->handle($publicSlugParameter, trim($value));

                request()->attributes->set("public_slug_resolution.{$publicSlugParameter}", $resolved);

                return $resolved['model'];
            });
        }
    }
}
