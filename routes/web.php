<?php

use App\Features\Contributions\Api\Enums\ContributionSubjectType;
use App\Features\Contributions\EntryPoints\Livewire\Pages\Index as ContributionsIndex;
use App\Features\Contributions\EntryPoints\Livewire\Pages\SubmitInstitution;
use App\Features\Contributions\EntryPoints\Livewire\Pages\SubmitPerson;
use App\Features\Contributions\EntryPoints\Livewire\Pages\SubmitReference;
use App\Features\Contributions\EntryPoints\Livewire\Pages\SuggestUpdate as SuggestContributionUpdate;
use App\Features\Events\EntryPoints\Http\ShareCardController;
use App\Features\Events\EntryPoints\Livewire\Pages\Dashboard\Events\CreateAdvanced;
use App\Features\Events\EntryPoints\Livewire\Pages\SubmitEvent\Create;
use App\Features\Identity\EntryPoints\Livewire\Pages\Dashboard\UserDashboard;
use App\Features\Institutions\EntryPoints\Livewire\Pages\InstitutionDashboard;
use App\Features\Persons\EntryPoints\Livewire\Pages\PersonDashboard;
use App\Features\Referrals\EntryPoints\Http\ReferralInviteLandingController;
use App\Features\Referrals\EntryPoints\Livewire\Pages\ReferralMemberIndex;
use App\Features\Registration\EntryPoints\Http\EventCheckoutResultController;
use App\Features\Registration\EntryPoints\Http\EventPassController;
use App\Features\Registration\EntryPoints\Livewire\Pages\Checkout as EventCheckout;
use App\Features\Registration\EntryPoints\Livewire\Pages\Dashboard\Events\OfflineAdmissions as EventOfflineAdmissions;
use App\Features\Registration\EntryPoints\Livewire\Pages\Dashboard\Events\Participants as EventParticipants;
use App\Features\Registration\EntryPoints\Livewire\Pages\Dashboard\Events\Refunds as EventRefunds;
use App\Features\Registration\EntryPoints\Livewire\Pages\Refund as EventRefund;
use App\Features\ShareTracking\EntryPoints\Http\Middleware\TrackShareAttribution;
use App\Features\ShareTracking\EntryPoints\Http\ShareClaimController;
use App\Features\ShareTracking\EntryPoints\Http\ShareController;
use App\Features\ShareTracking\EntryPoints\Http\ShareRedirectController;
use App\Features\ShareTracking\EntryPoints\Livewire\Pages\DakwahIndex;
use App\Features\ShareTracking\EntryPoints\Livewire\Pages\DakwahLinkShow;
use App\Features\SlugRedirects\EntryPoints\Http\Middleware\ResolvePublicSlugRedirect;
use App\Platform\Diagnostics\EntryPoints\Http\NetworkDiagnosticsController;
use App\Platform\Language\Http\Middleware\SetLocale;
use App\Platform\Routing\EntryPoints\Http\SitemapController;
use App\Platform\Ui\Pages\About\Show as AboutPage;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::livewire('/', 'pages.⚡home')->name('home');
Route::livewire('/tentang-kami', AboutPage::class)->name('about');
Route::view('/privasi', 'pages.legal', ['pageKey' => 'privacy'])->name('privacy');
Route::view('/terma', 'pages.legal', ['pageKey' => 'terms'])->name('terms');
Route::view('/hubungi-kami', 'pages.legal', ['pageKey' => 'contact'])->name('contact');

Route::get('/jemputan/{token}', ReferralInviteLandingController::class)
    ->middleware(['throttle:share-tracking', TrackShareAttribution::class])
    ->where('token', '[A-Za-z0-9]+')
    ->name('referrals.invite.landing');

Route::get('/kongsi/payload', [ShareController::class, 'payload'])
    ->middleware('throttle:share-tracking')
    ->name('share.payload');
Route::post('/kongsi/track', [ShareController::class, 'track'])
    ->middleware('throttle:30,1')
    ->name('share.track');
Route::get('/kongsi/{provider}', [ShareController::class, 'redirect'])
    ->middleware('throttle:share-tracking')
    ->whereIn('provider', ['whatsapp', 'telegram', 'threads', 'facebook', 'x', 'instagram', 'tiktok', 'email'])
    ->name('share.redirect');

// Explicit authenticated guest-share claim: surfacing never auto-claims,
// and the POST needs confirmation plus the private claim capability.
Route::get('/kongsi/claim', [ShareClaimController::class, 'show'])
    ->middleware('auth')
    ->name('share.claim.show');
Route::post('/kongsi/claim', [ShareClaimController::class, 'store'])
    ->middleware(['auth', 'throttle:share-claim'])
    ->name('share.claim.store');

// Public generated share card with occurrence/locale/revision selection.
// Immutable when pinned; stale pins redirect to the canonical current URL.
Route::get('/kongsi/kad/{event:slug}', [ShareCardController::class, 'show'])
    ->middleware('throttle:share-tracking')
    ->name('share.card');

// Honest destination for short links whose target is no longer public. Not
// a trackable route: landing here records nothing.
Route::view('/kongsi/tidak-tersedia', 'share.unavailable')
    ->name('share.unavailable');

// App share short-link adapter over the single links-managed tracked row.
// Pure redirect: the destination page records exactly once downstream.
Route::get('/k/{slug}', [ShareRedirectController::class, 'show'])
    ->middleware('throttle:share-tracking')
    ->where('slug', '[A-Za-z0-9_-]+')
    ->name('share.short');

// Authentication is handled by Fortify

// Events (with search rate limiting)
Route::livewire('/majlis', 'pages.events.index')
    ->middleware('throttle:search')
    ->name('events.index');
Route::livewire('/majlis/{event:slug}', 'pages.events.show')
    ->middleware(ResolvePublicSlugRedirect::class)
    ->name('events.show');
Route::livewire('/majlis/{event:slug}/checkout/{ticket?}', EventCheckout::class)
    ->middleware([ResolvePublicSlugRedirect::class, 'auth', 'verified', 'throttle:registration'])
    ->name('events.checkout');
Route::livewire('/majlis/{event:slug}/{occurrenceSlug}', 'pages.events.occurrence')
    ->middleware(ResolvePublicSlugRedirect::class)
    ->name('events.occurrence');

// Event Submission (Public)
Route::livewire('/tambah-majlis', Create::class)
    ->name('submit-event.create');
Route::livewire('/tambah-majlis/berjaya', 'pages.submit-event.success')->name('submit-event.success');

Route::get('/ops/network-diagnostics', NetworkDiagnosticsController::class)
    ->middleware('throttle:10,1')
    ->withoutMiddleware([
        PreventRequestForgery::class,
        SetLocale::class,
        StartSession::class,
        ShareErrorsFromSession::class,
    ])
    ->name('network-diagnostics');

Route::middleware('auth')->group(function () {
    Route::livewire('/dashboard', UserDashboard::class)->name('dashboard');
    Route::livewire('/dakwah', DakwahIndex::class)->name('dakwah');
    Route::livewire('/dakwah/links', DakwahIndex::class)->name('dakwah.links');
    Route::livewire('/dakwah/links/{link}', DakwahLinkShow::class)->name('dakwah.links.show');
    Route::livewire('/dashboard/ajak-kawan', ReferralMemberIndex::class)->name('dashboard.referrals');
    Route::livewire('/dashboard/institusi', InstitutionDashboard::class)->name('dashboard.institutions');
    Route::livewire('/dashboard/institusi/senarai-majlis', InstitutionDashboard::class)->name('dashboard.institutions.events');
    Route::livewire('/dashboard/institusi/tambah-majlis', Create::class)->name('dashboard.institutions.submit-event');
    Route::livewire('/dashboard/penceramah/{person:slug}', PersonDashboard::class)->name('dashboard.persons');
    Route::livewire('/dashboard/majlis/cipta-lanjutan', CreateAdvanced::class)->name('dashboard.events.create-advanced');
    Route::livewire('/dashboard/majlis/{event}/peserta', EventParticipants::class)->name('dashboard.events.participants');
    Route::livewire('/dashboard/majlis/{event}/kemasukan', EventOfflineAdmissions::class)->name('dashboard.events.offline-admissions');
    Route::livewire('/dashboard/majlis/{event}/bayaran-balik', EventRefunds::class)->name('dashboard.events.refunds');
    Route::livewire('/majlis/{event:slug}/pendaftaran/{registration}/refund', EventRefund::class)
        ->middleware('verified')
        ->name('events.registration.refund');
    Route::get('/checkout/result/{session}', [EventCheckoutResultController::class, 'show'])
        ->middleware('verified')
        ->name('checkout.result');
    Route::get('/checkout/result/{session}/receipt', [EventCheckoutResultController::class, 'receipt'])
        ->middleware('verified')
        ->name('checkout.receipt');
    Route::get('/checkout/free/{registration}', [EventCheckoutResultController::class, 'free'])
        ->middleware('verified')
        ->name('checkout.free');
    Route::livewire('/sumbangan', ContributionsIndex::class)->name('contributions.index');
    Route::livewire('/sumbangan/institusi/baru', SubmitInstitution::class)->name('contributions.submit-institution');
    Route::livewire('/sumbangan/penceramah/baru', SubmitPerson::class)->name('contributions.submit-person');
    Route::livewire('/sumbangan/rujukan/baru', SubmitReference::class)->name('contributions.submit-reference');
    Route::livewire('/sumbangan/{subjectType}/berjaya', 'pages.contributions.submission-success')
        ->whereIn('subjectType', [
            ContributionSubjectType::Institution->publicRouteSegment(),
            ContributionSubjectType::Person->publicRouteSegment(),
            ContributionSubjectType::Reference->publicRouteSegment(),
        ])
        ->name('contributions.submission-success');
    Route::livewire('/sumbangan/{subjectType}/{subjectId}/kemas-kini', SuggestContributionUpdate::class)
        ->whereIn('subjectType', ContributionSubjectType::publicRouteSegments())
        ->name('contributions.suggest-update');
});

Route::middleware('auth')->get('/majlis/{event:slug}/pas/{pass}', EventPassController::class)
    ->middleware(ResolvePublicSlugRedirect::class)
    ->name('events.pass');
Route::livewire('/majlis/{event:slug}/{occurrenceSlug}/{sessionSlug}', 'pages.events.session')
    ->middleware(ResolvePublicSlugRedirect::class)
    ->name('events.session');

// Institutions (with search rate limiting)
Route::livewire('/institusi', 'pages.institutions.index')
    ->middleware('throttle:search')
    ->name('institutions.index');
Route::livewire('/institusi/{institution:slug}', 'pages.institutions.show')
    ->middleware(ResolvePublicSlugRedirect::class)
    ->name('institutions.show');

// Persons (with search rate limiting)
Route::livewire('/penceramah', 'pages.persons.index')
    ->middleware('throttle:search')
    ->name('persons.index');
Route::livewire('/penceramah/{person:slug}', 'pages.persons.show')
    ->middleware(ResolvePublicSlugRedirect::class)
    ->name('persons.show');

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
Route::middleware('throttle:search')->group(function (): void {
    Route::get('/peta-laman.xml', [SitemapController::class, 'index'])->name('sitemap.index');
    Route::get('/peta-laman-majlis.xml', [SitemapController::class, 'events'])->name('sitemap.events');
    Route::get('/peta-laman-institusi.xml', [SitemapController::class, 'institutions'])->name('sitemap.institutions');
    Route::get('/peta-laman-penceramah.xml', [SitemapController::class, 'persons'])->name('sitemap.persons');
});
