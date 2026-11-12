<?php

declare(strict_types=1);

use AIArmada\Affiliates\AffiliatesServiceProvider;
use AIArmada\CommerceSupport\SupportServiceProvider;
use App\Features\Directory\FeatureServiceProvider as DirectoryServiceProvider;
use App\Features\Contributions\FeatureServiceProvider as ContributionsServiceProvider;
use App\Features\Events\FeatureServiceProvider as EventsServiceProvider;
use App\Features\Feedback\FeatureServiceProvider as FeedbackServiceProvider;
use App\Features\GitHubIssues\FeatureServiceProvider as GitHubIssuesServiceProvider;
use App\Features\Identity\FeatureServiceProvider as IdentityServiceProvider;
use App\Features\Inspirations\FeatureServiceProvider as InspirationsServiceProvider;
use App\Features\Membership\FeatureServiceProvider as MembershipServiceProvider;
use App\Features\Organizations\FeatureServiceProvider as OrganizationsServiceProvider;
use App\Features\Prayer\FeatureServiceProvider;
use App\Features\Registration\FeatureServiceProvider as RegistrationServiceProvider;
use App\Features\Reports\FeatureServiceProvider as ReportsServiceProvider;
use App\Features\SavedSearches\FeatureServiceProvider as SavedSearchesServiceProvider;
use App\Providers\AdminRegistryServiceProvider;
use App\Providers\ApiDocumentationServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\BlazeServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\AhliPanelProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\RateLimitServiceProvider;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Passport\PassportServiceProvider;
use Ysfkaya\FilamentPhoneInput\FilamentPhoneInputServiceProvider;

return [
    SupportServiceProvider::class,
    AffiliatesServiceProvider::class,
    AdminRegistryServiceProvider::class,
    ApiDocumentationServiceProvider::class,
    AppServiceProvider::class,
    ContributionsServiceProvider::class,
    DirectoryServiceProvider::class,
    EventsServiceProvider::class,
    FeedbackServiceProvider::class,
    GitHubIssuesServiceProvider::class,
    IdentityServiceProvider::class,
    InspirationsServiceProvider::class,
    MembershipServiceProvider::class,
    OrganizationsServiceProvider::class,
    FeatureServiceProvider::class,
    RegistrationServiceProvider::class,
    ReportsServiceProvider::class,
    SavedSearchesServiceProvider::class,
    BlazeServiceProvider::class,
    FilamentPhoneInputServiceProvider::class,
    AhliPanelProvider::class,
    AdminPanelProvider::class,
    RateLimitServiceProvider::class,
    FortifyServiceProvider::class,
    HorizonServiceProvider::class,
    McpServiceProvider::class,
    PassportServiceProvider::class,
];
