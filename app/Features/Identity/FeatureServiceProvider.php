<?php

declare(strict_types=1);

namespace App\Features\Identity;

use App\Features\Identity\Actions\RecordNewAccountAttributionAction;
use App\Features\Identity\Api\AccountAccess;
use App\Features\Identity\Api\AccountAdministration;
use App\Features\Identity\Api\AccountLifecycle;
use App\Features\Identity\Api\AccountPreferences;
use App\Features\Identity\Api\AccountSettingsStore;
use App\Features\Identity\Api\AccountSubmissionEligibility;
use App\Features\Identity\Api\Frontend\AccountSettingsFormContractContributor;
use App\Features\Identity\Api\NewAccountAttribution;
use App\Features\Identity\Api\SignupAttributionContextResolver;
use App\Features\Identity\Api\SignupAttributions;
use App\Features\Identity\Api\SignupAttributionWorkflow;
use App\Features\Identity\Api\SocialAccounts;
use App\Features\Identity\Contributors\IdentityAccountLifecycleContributor;
use App\Features\Identity\Contributors\ReportsAccountLifecycleContributor;
use App\Features\Identity\Contributors\SavedSearchesAccountLifecycleContributor;
use App\Features\Identity\Contributors\VerificationAttributionContributor;
use App\Features\Identity\EntryPoints\Api\Admin\UserResourceDefinition;
use App\Features\Identity\EntryPoints\ApiDocumentation\IdentityRequestExampleContributor;
use App\Features\Identity\EntryPoints\Filament\Pages\DeletedUsers;
use App\Features\Identity\EntryPoints\Filament\Resources\Authz\UserResource;
use App\Features\Identity\Integrations\Signup\SignupAttributionContextResolverAdapter;
use App\Features\Identity\Listeners\ClaimGuestSubmissionsOnVerified;
use App\Features\Identity\Listeners\RecordVerifiedEmail;
use App\Features\Identity\Listeners\SendRegisteredUserEmails;
use App\Features\Identity\Observers\UserObserver;
use App\Features\Identity\Persistence\EloquentAccountAccess;
use App\Features\Identity\Persistence\EloquentAccountDirectory;
use App\Features\Identity\Persistence\EloquentAccountSettingsStore;
use App\Features\Identity\Persistence\EloquentUserMorphType;
use App\Features\Identity\Services\AccountAdministrationService;
use App\Features\Identity\Services\AccountLifecycleCoordinator;
use App\Features\Identity\Services\AccountPreferencesService;
use App\Features\Identity\Services\AccountSubmissionEligibilityService;
use App\Features\Identity\Services\SignupAttributionsService;
use App\Features\Identity\Services\SignupAttributionWorkflowService;
use App\Features\Identity\Services\SocialAccountsService;
use App\Features\Identity\Support\Telemetry\IdentitySignalSchemaContributor;
use App\Models\User;
use App\Platform\Admin\FilamentPageRegistry;
use App\Platform\Admin\FilamentResourceRegistry;
use App\Platform\Api\Frontend\FrontendFormContractRegistry;
use App\Platform\Api\Resources\ResourceDefinitionRegistry;
use App\Platform\ApiDocumentation\RequestExampleRegistry;
use App\Platform\FeatureModule;
use App\Platform\Telemetry\SignalSchemaRegistry;
use App\Shared\Auth\AccountDirectory;
use App\Shared\Auth\AccountLifecycleContributor;
use App\Shared\Auth\UserMorphType;
use App\Shared\Contracts\AccountTimezoneUpdater;
use App\Shared\Contracts\AccountTokenRevoker;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;

class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(SignupAttributionContextResolver::class, SignupAttributionContextResolverAdapter::class);
        $this->app->bind(AccountAccess::class, EloquentAccountAccess::class);
        $this->app->bind(AccountDirectory::class, EloquentAccountDirectory::class);
        $this->app->bind(AccountSettingsStore::class, EloquentAccountSettingsStore::class);
        $this->app->bind(AccountTimezoneUpdater::class, EloquentAccountSettingsStore::class);
        $this->app->bind(AccountTokenRevoker::class, EloquentAccountSettingsStore::class);
        $this->app->bind(UserMorphType::class, EloquentUserMorphType::class);
        $this->app->bind(AccountSubmissionEligibility::class, AccountSubmissionEligibilityService::class);
        $this->app->bind(AccountPreferences::class, AccountPreferencesService::class);
        $this->app->bind(SignupAttributions::class, SignupAttributionsService::class);
        $this->app->bind(SignupAttributionWorkflow::class, SignupAttributionWorkflowService::class);
        $this->app->bind(NewAccountAttribution::class, RecordNewAccountAttributionAction::class);
        $this->app->bind(AccountAdministration::class, AccountAdministrationService::class);
        $this->app->bind(SocialAccounts::class, SocialAccountsService::class);

        $this->app->tag([
            IdentityAccountLifecycleContributor::class,
            ReportsAccountLifecycleContributor::class,
            SavedSearchesAccountLifecycleContributor::class,
            VerificationAttributionContributor::class,
        ], AccountLifecycleContributor::TAG);

        $this->app->bind(AccountLifecycle::class, static fn (Application $app): AccountLifecycleCoordinator => new AccountLifecycleCoordinator(
            $app->tagged(AccountLifecycleContributor::TAG),
        ));

        $this->app->make(FilamentPageRegistry::class)->register(DeletedUsers::class);
        $this->app->make(FilamentResourceRegistry::class)->register(UserResource::class);
    }

    public function boot(): void
    {
        User::observe(UserObserver::class);
        Event::listen(Registered::class, SendRegisteredUserEmails::class);
        Event::listen(Verified::class, ClaimGuestSubmissionsOnVerified::class);
        Event::listen(Verified::class, RecordVerifiedEmail::class);

        $this->app->make(ResourceDefinitionRegistry::class)->register($this->app->make(UserResourceDefinition::class));
        parent::boot();

        $this->app->make(SignalSchemaRegistry::class)->register(new IdentitySignalSchemaContributor);
        $this->app->make(RequestExampleRegistry::class)->register(new IdentityRequestExampleContributor);
        $this->app->make(FrontendFormContractRegistry::class)->register(AccountSettingsFormContractContributor::class);
    }
}
