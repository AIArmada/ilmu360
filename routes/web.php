<?php

use App\Enums\ContributionSubjectType;
use App\Enums\MemberSubjectType;
use App\Http\Controllers\Auth\SocialiteController;
use App\Http\Controllers\DawahShareController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\NetworkDiagnosticsController;
use App\Http\Controllers\Public\EventPassController;
use App\Http\Controllers\Public\EventsController;
use App\Http\Controllers\SitemapController;
use App\Http\Middleware\ResolvePublicSlugRedirect;
use App\Http\Middleware\SetLocale;
use App\Livewire\Pages\About\Show as AboutPage;
use App\Livewire\Pages\Contributions\Index as ContributionsIndex;
use App\Livewire\Pages\Contributions\SubmitInstitution;
use App\Livewire\Pages\Contributions\SubmitSpeaker;
use App\Livewire\Pages\Contributions\SuggestUpdate as SuggestContributionUpdate;
use App\Livewire\Pages\Dashboard\AccountSettings;
use App\Livewire\Pages\Dashboard\DawahImpactIndex;
use App\Livewire\Pages\Dashboard\DawahImpactLinkShow;
use App\Livewire\Pages\Dashboard\Events\CreateAdvanced;
use App\Livewire\Pages\Dashboard\InstitutionDashboard;
use App\Livewire\Pages\Dashboard\NotificationsIndex;
use App\Livewire\Pages\Dashboard\UserDashboard;
use App\Livewire\Pages\Membership\ShowInvitation as ShowMemberInvitation;
use App\Livewire\Pages\MembershipApplications\Create as CreateMembershipApplicationPage;
use App\Livewire\Pages\MembershipApplications\Index as MembershipApplicationsIndex;
use App\Livewire\Pages\Reports\Create as CreateReportPage;
use App\Livewire\Pages\SavedSearches\Index;
use App\Livewire\Pages\Search\Index as SearchIndex;
use App\Livewire\Pages\SubmitEvent\Create;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Laravel\Fortify\Features;

Route::livewire('/', 'pages.⚡home')->name('home');
Route::livewire('/tentang-kami', AboutPage::class)->name('about');
Route::get('/bahasa/{locale}', LocaleController::class)->name('locale.switch');

// Socialite OAuth Routes
Route::get('/oauth/{provider}/redirect', [SocialiteController::class, 'redirect'])
    ->name('socialite.redirect')
    ->whereIn('provider', ['google']);
Route::get('/oauth/{provider}/callback', [SocialiteController::class, 'callback'])
    ->name('socialite.callback')
    ->whereIn('provider', ['google']);

Route::get('/kongsi/payload', [DawahShareController::class, 'payload'])
    ->middleware('throttle:share-tracking')
    ->name('dawah-share.payload');
Route::post('/kongsi/track', [DawahShareController::class, 'track'])
    ->middleware('throttle:30,1')
    ->name('dawah-share.track');
Route::get('/kongsi/{provider}', [DawahShareController::class, 'redirect'])
    ->middleware('throttle:share-tracking')
    ->whereIn('provider', ['whatsapp', 'telegram', 'threads', 'facebook', 'x', 'instagram', 'tiktok', 'email'])
    ->name('dawah-share.redirect');

// Authentication is handled by Fortify

// Events (with search rate limiting)
Route::livewire('/carian', SearchIndex::class)
    ->middleware('throttle:search')
    ->name('search.index');
Route::livewire('/majlis', 'pages.events.index')
    ->middleware('throttle:search')
    ->name('events.index');
Route::livewire('/majlis/{event:slug}', 'pages.events.show')
    ->middleware(ResolvePublicSlugRedirect::class)
    ->name('events.show');
Route::get('/majlis/{event:slug}/kalendar.ics', [EventsController::class, 'calendar'])
    ->middleware(ResolvePublicSlugRedirect::class)
    ->name('events.calendar');

// Event Submission (Public)
Route::livewire('/tambah-majlis', 'pages.submit-event.landing')
    ->name('submit-event.landing');
Route::livewire('/hantar-majlis', Create::class)
    ->name('submit-event.create');
Route::livewire('/hantar-majlis/berjaya', 'pages.submit-event.success')->name('submit-event.success');

Route::get('/ops/network-diagnostics', NetworkDiagnosticsController::class)
    ->withoutMiddleware([
        PreventRequestForgery::class,
        SetLocale::class,
        StartSession::class,
        ShareErrorsFromSession::class,
    ])
    ->name('network-diagnostics');

Route::middleware('auth')->group(function () {
    Route::livewire('/dashboard', UserDashboard::class)->name('dashboard');
    Route::livewire('/dashboard/dawah-impact', DawahImpactIndex::class)->name('dashboard.dawah-impact');
    Route::livewire('/dashboard/dawah-impact/links', DawahImpactIndex::class)->name('dashboard.dawah-impact.links');
    Route::livewire('/dashboard/dawah-impact/links/{link}', DawahImpactLinkShow::class)->name('dashboard.dawah-impact.links.show');
    Route::livewire('/dashboard/notifications', NotificationsIndex::class)->name('dashboard.notifications');
    Route::livewire('/tetapan-akaun', AccountSettings::class)->name('dashboard.account-settings');
    Route::livewire('/dashboard/institusi', InstitutionDashboard::class)->name('dashboard.institutions');
    Route::livewire('/dashboard/institusi/senarai-majlis', InstitutionDashboard::class)->name('dashboard.institutions.events');
    Route::livewire('/dashboard/institusi/tambah-majlis', Create::class)->name('dashboard.institutions.submit-event');
    Route::livewire('/dashboard/majlis/cipta-lanjutan', CreateAdvanced::class)->name('dashboard.events.create-advanced');
    Route::livewire('/carian-tersimpan', Index::class)->name('saved-searches.index');
    Route::livewire('/jemputan-ahli/{token}', ShowMemberInvitation::class)->name('member-invitations.show');
    Route::livewire('/sumbangan', ContributionsIndex::class)->name('contributions.index');
    Route::livewire('/sumbangan/institusi/baru', SubmitInstitution::class)->name('contributions.submit-institution');
    Route::livewire('/sumbangan/penceramah/baru', SubmitSpeaker::class)->name('contributions.submit-speaker');
    Route::livewire('/sumbangan/{subjectType}/berjaya', 'pages.contributions.submission-success')
        ->whereIn('subjectType', [
            ContributionSubjectType::Institution->publicRouteSegment(),
            ContributionSubjectType::Speaker->publicRouteSegment(),
        ])
        ->name('contributions.submission-success');
    Route::livewire('/permohonan-keahlian', MembershipApplicationsIndex::class)->name('membership-applications.index');
    Route::livewire('/pohon-keahlian/{subjectType}/{subjectId}', CreateMembershipApplicationPage::class)
        ->whereIn('subjectType', MemberSubjectType::claimableRouteSegments())
        ->name('membership-applications.create');
    Route::livewire('/sumbangan/{subjectType}/{subjectId}/kemas-kini', SuggestContributionUpdate::class)
        ->whereIn('subjectType', ContributionSubjectType::publicRouteSegments())
        ->name('contributions.suggest-update');

    Route::livewire('/lapor/{subjectType}/{subjectId}', CreateReportPage::class)
        ->whereIn('subjectType', ContributionSubjectType::publicRouteSegments())
        ->name('reports.create');
});

// Event Registration - Rate limited
Route::post('/majlis/{event:slug}/daftar', [EventsController::class, 'register'])
    ->middleware(['throttle:registration', ResolvePublicSlugRedirect::class])
    ->name('events.register');

Route::middleware('auth')->get('/majlis/{event:slug}/pas/{pass}', EventPassController::class)
    ->middleware(ResolvePublicSlugRedirect::class)
    ->name('events.pass');

// Institutions (with search rate limiting)
Route::livewire('/institusi', 'pages.institutions.index')
    ->middleware('throttle:search')
    ->name('institutions.index');
Route::livewire('/institusi/{institution:slug}', 'pages.institutions.show')
    ->middleware(ResolvePublicSlugRedirect::class)
    ->name('institutions.show');

// Speakers (with search rate limiting)
Route::livewire('/penceramah', 'pages.speakers.index')
    ->middleware('throttle:search')
    ->name('speakers.index');
Route::livewire('/penceramah/{speaker:slug}', 'pages.speakers.show')
    ->middleware(ResolvePublicSlugRedirect::class)
    ->name('speakers.show');

// Venues
Route::livewire('/tempat', 'pages.venues.index')
    ->middleware('throttle:search')
    ->name('venues.index');
Route::livewire('/lokasi/{venue:slug}', 'pages.venues.show')
    ->middleware(ResolvePublicSlugRedirect::class)
    ->name('venues.show');

// Series
Route::livewire('/siri/{series:slug}', 'pages.series.show')->name('series.show');

// References
Route::livewire('/rujukan', 'pages.references.index')
    ->middleware('throttle:search')
    ->name('references.index');
Route::livewire('/rujukan/{reference:slug}', 'pages.references.show')
    ->middleware(ResolvePublicSlugRedirect::class)
    ->name('references.show');

// Sitemap
Route::get('/peta-laman.xml', [SitemapController::class, 'index'])->name('sitemap.index');
Route::get('/peta-laman-majlis.xml', [SitemapController::class, 'events'])->name('sitemap.events');
Route::get('/peta-laman-institusi.xml', [SitemapController::class, 'institutions'])->name('sitemap.institutions');
Route::get('/peta-laman-penceramah.xml', [SitemapController::class, 'speakers'])->name('sitemap.speakers');

Route::view('/welcome', 'welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('welcome');
