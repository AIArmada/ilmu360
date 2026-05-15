<?php

declare(strict_types=1);

use AIArmada\Affiliates\AffiliatesServiceProvider;
use AIArmada\CommerceSupport\SupportServiceProvider;
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
    ApiDocumentationServiceProvider::class,
    AppServiceProvider::class,
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
