<?php

use AIArmada\Events\Features\Catalog\Models\EventOccurrence;
use AIArmada\Membership\Enums\ApplicationStatus;
use AIArmada\Membership\Enums\MemberRole;
use App\Features\Contributions\Api\Enums\ContributionSubjectType;
use App\Features\Contributions\EntryPoints\Livewire\Pages\Index as ContributionsIndex;
use App\Features\Contributions\EntryPoints\Ui\ContributionsHubPresenter;
use App\Features\Contributions\Enums\ContributionRequestStatus;
use App\Features\Contributions\Enums\ContributionRequestType;
use App\Features\Contributions\Models\ContributionRequest;
use App\Features\Events\Models\Event;
use App\Features\Events\Models\EventSubmission;
use App\Features\Feedback\Api\AppFeedbackPage;
use App\Features\Feedback\Api\AppFeedbackReader;
use App\Features\Feedback\Api\AppFeedbackSnapshot;
use App\Features\Feedback\Enums\AppFeedbackStatus;
use App\Features\Feedback\Models\AppFeedback;
use App\Features\Feedback\Models\AppFeedbackMessage;
use App\Features\Institutions\Models\Institution;
use App\Features\Membership\Models\MembershipApplication;
use App\Features\Persons\Models\Person;
use App\Features\References\Models\Reference;
use App\Features\Reports\Models\Report;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('requires authentication before opening the contributions hub', function (): void {
    $this->get(route('contributions.index'))->assertRedirect(route('login'));
});

it('renders the hub view without page-level filament form assets', function (): void {
    $view = (string) file_get_contents(resource_path('views/livewire/pages/contributions/index.blade.php'));

    expect($view)->not->toContain('filament-assets')
        ->and($view)->not->toContain('partials.filament-assets');
});

it('offers every contribution launcher with live pulse counts on the overview tab', function (): void {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    $person = Person::factory()->create(['name' => 'Ustaz Hub', 'status' => 'verified']);
    $event = Event::factory()->for($institution)->create([
        'title' => 'Kuliah Hub',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
    ]);

    ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Create,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => null,
        'entity_id' => null,
        'proposer_id' => $user->id,
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => ['name' => 'Masjid Hub Baharu'],
    ]);

    ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Update,
        'subject_type' => ContributionSubjectType::Person,
        'entity_type' => $person->getMorphClass(),
        'entity_id' => $person->id,
        'proposer_id' => $user->id,
        'status' => ContributionRequestStatus::Approved,
        'proposed_data' => ['name' => 'Ustaz Hub Baharu'],
        'original_data' => ['name' => 'Ustaz Hub'],
    ]);

    EventSubmission::factory()->for($event)->for($user, 'submitter')->create();

    Report::factory()->create([
        'reporter_id' => $user->id,
        'entity_type' => $institution->getMorphClass(),
        'entity_id' => $institution->id,
        'status' => 'open',
        'category' => 'wrong_info',
        'description' => 'Butiran institusi tidak tepat.',
    ]);

    MembershipApplication::factory()->for($person, 'subject')->create([
        'applicant_id' => $user->id,
        'status' => ApplicationStatus::Pending,
    ]);

    AppFeedback::factory()->create([
        'reporter_id' => $user->id,
        'status' => AppFeedbackStatus::New,
        'title' => 'Cadangan penambahbaikan',
    ]);

    $component = Livewire::actingAs($user)->test(ContributionsIndex::class);

    $component->assertOk()
        ->assertSee('data-active-tab="overview"', false)
        ->assertSee(__('Mula Menyumbang'))
        ->assertSee(__('Majlis Ilmu (Events)'))
        ->assertSee(__('New Directory Record'))
        ->assertSee(__('Suggest an Update'))
        ->assertSee(__('Report Inaccurate Information'))
        ->assertSee(__('Claim Management'))
        ->assertSee(__('App Feedback'))
        ->assertSee(__('Total Contributions'))
        ->assertSee(route('submit-event.create'), false)
        ->assertSee(route('contributions.submit-institution'), false)
        ->assertSee(route('contributions.submit-person'), false)
        ->assertSee(route('contributions.submit-reference'), false)
        ->assertSee(route('app-feedback.index', ['dari' => '/sumbangan']), false);

    $metrics = $component->instance()->summaryMetrics;

    expect($metrics['tabs'])->toMatchArray([
        'events' => 1,
        'directory' => 1,
        'updates' => 1,
        'reports' => 1,
        'membership' => 1,
        'feedback' => 1,
    ])->and($metrics['totals']['total'])->toBe(6)
        ->and($metrics['totals']['review'])->toBe(4)
        ->and($metrics['totals']['approved'])->toBe(2)
        ->and($metrics['totals']['attention'])->toBe(0);
});

it('orders the unified recent activity timeline newest first', function (): void {
    $user = User::factory()->create();

    $older = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Create,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => null,
        'entity_id' => null,
        'proposer_id' => $user->id,
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => ['name' => 'Rekod Lama'],
        'created_at' => now()->subDays(3),
    ]);

    $newer = AppFeedback::factory()->create([
        'reporter_id' => $user->id,
        'title' => 'Maklum balas terbaharu',
        'created_at' => now()->subHour(),
    ]);

    $activity = Livewire::actingAs($user)->test(ContributionsIndex::class)->instance()->recentActivity;
    $keys = array_column($activity, 'key');

    expect($activity)->not->toBeEmpty()
        ->and($keys[0])->toBe('feedback-'.$newer->id)
        ->and($activity[0]['tab'])->toBe('feedback')
        ->and($activity[0]['family'])->not->toBeEmpty()
        ->and(array_search('request-'.$older->id, $keys, true))->toBeGreaterThan(0);
});

it('reflects the hub filters in the url query state', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::withQueryParams([
        'tab' => 'directory',
        'status' => 'pending',
        'type' => 'institution',
        'q' => 'Masjid',
    ])->test(ContributionsIndex::class)
        ->assertSet('activeTab', 'directory')
        ->assertSet('statusFilter', 'pending')
        ->assertSet('subjectFilter', 'institution')
        ->assertSet('search', 'Masjid');

    Livewire::withQueryParams(['tab' => 'unknown'])->test(ContributionsIndex::class)
        ->assertSet('activeTab', 'overview');

    $this->get(route('contributions.index', ['tab' => 'updates', 'status' => 'approved']))
        ->assertOk()
        ->assertSee('data-active-tab="updates"', false);
});

it('lists institution, speaker and reference requests on the directory tab and cancels a pending request', function (): void {
    $user = User::factory()->create();
    $person = Person::factory()->create(['status' => 'verified']);
    $reference = Reference::factory()->create(['status' => 'verified']);

    $institutionRequest = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Create,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => null,
        'entity_id' => null,
        'proposer_id' => $user->id,
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => ['name' => 'Masjid Direktori Baharu'],
        'proposer_note' => 'Tolong tambah masjid ini.',
    ]);

    ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Create,
        'subject_type' => ContributionSubjectType::Person,
        'entity_type' => null,
        'entity_id' => null,
        'proposer_id' => $user->id,
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => ['name' => 'Penceramah Baharu '.$person->id],
    ]);

    ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Create,
        'subject_type' => ContributionSubjectType::Reference,
        'entity_type' => null,
        'entity_id' => null,
        'proposer_id' => $user->id,
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => ['title' => 'Kitab Rujukan Baharu'],
    ]);

    $component = Livewire::actingAs($user)->test(ContributionsIndex::class);
    $component->call('selectTab', 'directory')
        ->assertSee(__('New Directory Records'))
        ->assertSee('Masjid Direktori Baharu')
        ->assertSee('Kitab Rujukan Baharu')
        ->assertSee(__('Institution'))
        ->assertSee(__('Speaker'))
        ->assertSee(__('Reference'));

    $component->set('subjectFilter', 'reference')
        ->assertSee('Kitab Rujukan Baharu')
        ->assertDontSee('Masjid Direktori Baharu');

    $component->call('resetFilters')
        ->set('search', 'Direktori')
        ->assertSee('Masjid Direktori Baharu')
        ->assertDontSee('Kitab Rujukan Baharu');

    $component->call('resetFilters')
        ->set('search', 'Tolong tambah')
        ->assertSee('Masjid Direktori Baharu');

    $component->call('cancelContributionRequest', (string) $institutionRequest->id);

    expect($institutionRequest->fresh()->status)->toBe(ContributionRequestStatus::Cancelled);
    expect($reference->fresh()->status)->toBe('verified');
});

it('forbids cancelling another proposer contribution request', function (): void {
    $proposer = User::factory()->create();
    $intruder = User::factory()->create();
    $request = ContributionRequest::factory()->create([
        'proposer_id' => $proposer->id,
        'status' => ContributionRequestStatus::Pending,
    ]);

    Livewire::actingAs($intruder)
        ->test(ContributionsIndex::class)
        ->call('cancelContributionRequest', (string) $request->id)
        ->assertStatus(404);

    expect($request->fresh()->status)->toBe(ContributionRequestStatus::Pending);
});

it('summarises update field changes and cancels a pending update request', function (): void {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['name' => 'Masjid Lama', 'status' => 'verified']);

    $update = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Update,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => $institution->getMorphClass(),
        'entity_id' => $institution->id,
        'proposer_id' => $user->id,
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => ['name' => 'Masjid Baharu'],
        'original_data' => ['name' => 'Masjid Lama'],
    ]);

    $component = Livewire::actingAs($user)->test(ContributionsIndex::class);
    $component->call('selectTab', 'updates')
        ->assertSee(__('Update Suggestions'))
        ->assertSee('Masjid Baharu')
        ->assertSee('Masjid Lama')
        ->assertSee(__(':count changed fields', ['count' => 1]));

    $summaries = $component->instance()->updateRequests->items();

    expect($summaries)->toHaveCount(1)
        ->and($summaries[0]->getKey())->toBe($update->getKey());

    $component->set('search', 'Baharu')->assertSee('Masjid Baharu');
    $component->call('resetFilters');
    $component->set('search', 'Masjid Lama')->assertSee('Masjid Baharu');

    $component->call('cancelContributionRequest', (string) $update->id);

    expect($update->fresh()->status)->toBe(ContributionRequestStatus::Cancelled);
});

it('filters and searches report submissions by status, text and record name', function (): void {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['name' => 'Masjid Laporan', 'status' => 'verified']);

    $open = Report::factory()->create([
        'reporter_id' => $user->id,
        'entity_type' => $institution->getMorphClass(),
        'entity_id' => $institution->id,
        'status' => 'open',
        'category' => 'wrong_info',
        'description' => 'Alamat salah.',
    ]);

    Report::factory()->create([
        'reporter_id' => $user->id,
        'entity_type' => $institution->getMorphClass(),
        'entity_id' => $institution->id,
        'status' => 'resolved',
        'category' => 'wrong_info',
        'description' => 'Sudah selesai.',
    ]);

    $component = Livewire::actingAs($user)->test(ContributionsIndex::class);
    $component->call('selectTab', 'reports')
        ->assertSee(__('Report Submissions'))
        ->assertSee('Alamat salah.')
        ->assertSee('Sudah selesai.')
        ->assertSee('Masjid Laporan');

    $component->set('statusFilter', 'open')
        ->assertSee('Alamat salah.')
        ->assertDontSee('Sudah selesai.');

    $component->set('statusFilter', 'all');
    $component->set('search', 'Sudah selesai')
        ->assertSee('Sudah selesai.')
        ->assertDontSee('Alamat salah.');

    $component->set('search', 'Masjid Laporan')
        ->assertSee('Alamat salah.')
        ->assertSee('Sudah selesai.');

    expect($open->fresh()->status)->toBe('open');
});

it('filters event submissions by status group and title search', function (): void {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);

    $pendingEvent = Event::factory()->for($institution)->create([
        'title' => 'Majlis Menunggu',
        'status' => 'pending',
        'visibility' => 'public',
    ]);

    $approvedEvent = Event::factory()->for($institution)->create([
        'title' => 'Majlis Diluluskan',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
    ]);

    EventSubmission::factory()->for($pendingEvent)->for($user, 'submitter')->create();
    EventSubmission::factory()->for($approvedEvent)->for($user, 'submitter')->create();

    $component = Livewire::actingAs($user)->test(ContributionsIndex::class);
    $component->call('selectTab', 'events')
        ->assertSee('Majlis Menunggu')
        ->assertSee('Majlis Diluluskan');

    $component->set('statusFilter', 'pending')->assertSee('Majlis Menunggu')->assertDontSee('Majlis Diluluskan');
    $component->set('statusFilter', 'published')->assertSee('Majlis Diluluskan')->assertDontSee('Majlis Menunggu');
    $component->set('statusFilter', 'all');
    $component->set('search', 'Diluluskan')->assertSee('Majlis Diluluskan')->assertDontSee('Majlis Menunggu');
    $component->set('search', 'tiada-seperti-ini')->assertSee(__('No event submissions yet.'));
});

it('lists membership claims with granted roles and cancels a pending claim', function (): void {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['name' => 'Masjid Keahlian', 'status' => 'verified']);

    $pendingClaim = MembershipApplication::factory()->for($institution, 'subject')->create([
        'applicant_id' => $user->id,
        'status' => ApplicationStatus::Pending,
        'justification' => 'Saya penjaga masjid ini.',
    ]);

    MembershipApplication::factory()->for($institution, 'subject')->approved()->create([
        'applicant_id' => $user->id,
        'granted_role' => MemberRole::Admin->value,
        'reviewer_note' => 'Disahkan oleh moderator.',
    ]);

    $component = Livewire::actingAs($user)->test(ContributionsIndex::class);
    $component->call('selectTab', 'membership')
        ->assertSee(__('Membership Claims'))
        ->assertSee('Masjid Keahlian')
        ->assertSee('Disahkan oleh moderator.')
        ->assertSee(__('Approved'))
        ->assertSee(__('Role: :role', ['role' => __('Admin')]))
        ->assertSee(__('Cancel claim'));

    $component->call('cancelMembershipApplication', (string) $pendingClaim->getKey());

    expect($pendingClaim->fresh()->status)->toBe(ApplicationStatus::Cancelled);
});

it('rejects cancelling a membership claim that is no longer pending', function (): void {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);

    $approvedClaim = MembershipApplication::factory()->for($institution, 'subject')->approved()->create([
        'applicant_id' => $user->id,
        'granted_role' => MemberRole::Editor->value,
    ]);

    Livewire::actingAs($user)
        ->test(ContributionsIndex::class)
        ->call('cancelMembershipApplication', (string) $approvedClaim->getKey())
        ->assertDispatched('app-toast');

    expect($approvedClaim->fresh()->status)->toBe(ApplicationStatus::Approved);
});

it('searches institutions and speakers inline for a claim and redirects to the claim form', function (): void {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['name' => 'Masjid Carian', 'status' => 'verified']);
    $person = Person::factory()->create(['name' => 'Ustaz Carian', 'status' => 'verified']);

    $component = Livewire::actingAs($user)->test(ContributionsIndex::class);
    $component->call('selectTab', 'membership');
    $component->set('claimSubjectType', 'institution');
    $component->set('claimSearch', 'Masjid Carian');

    $institutionResults = $component->instance()->claimSearchResults;

    expect(collect($institutionResults)->firstWhere('identifier', (string) $institution->getKey()))->not->toBeNull();

    $component->call('selectClaimRecord', (string) $institution->getKey(), 'Masjid Carian')
        ->assertSet('selectedClaimIdentifier', (string) $institution->getKey())
        ->call('startMembershipClaim')
        ->assertRedirect(route('membership-applications.create', [
            'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
            'subjectId' => (string) $institution->getKey(),
        ]));

    $component = Livewire::actingAs($user)->test(ContributionsIndex::class);
    $component->call('selectTab', 'membership');
    $component->set('claimSubjectType', 'person');
    $component->set('claimSearch', 'Ustaz Carian');

    $personResults = $component->instance()->claimSearchResults;

    expect(collect($personResults)->firstWhere('identifier', $person->slug))->not->toBeNull();

    $component->call('selectClaimRecord', $person->slug, $person->formatted_name)
        ->call('startMembershipClaim')
        ->assertRedirect(route('membership-applications.create', [
            'subjectType' => ContributionSubjectType::Person->publicRouteSegment(),
            'subjectId' => $person->slug,
        ]));
});

it('shows app feedback tickets with reply counts, resolution notes and thread links', function (): void {
    $user = User::factory()->create();

    $feedback = AppFeedback::factory()->resolved()->create([
        'reporter_id' => $user->id,
        'title' => 'Carian tidak berfungsi',
        'description' => 'Butang carian tidak bertindak balas.',
        'resolution_note' => 'Sudah dibaiki dalam keluaran terkini.',
    ]);

    AppFeedbackMessage::factory()->create([
        'feedback_id' => $feedback->id,
        'visibility' => 'reporter',
    ]);

    AppFeedbackMessage::factory()->create([
        'feedback_id' => $feedback->id,
        'visibility' => 'internal',
    ]);

    AppFeedback::factory()->create([
        'title' => 'Maklum balas pengguna lain',
    ]);

    $component = Livewire::actingAs($user)->test(ContributionsIndex::class);
    $component->call('selectTab', 'feedback')
        ->assertSee(__('App Feedback'))
        ->assertSee('Carian tidak berfungsi')
        ->assertSee('Sudah dibaiki dalam keluaran terkini.')
        ->assertSee(trans_choice(':count reply|:count replies', 1, ['count' => 1]))
        ->assertSee(route('app-feedback.index', ['lihat' => $feedback->id]).'#maklum-balas-'.$feedback->id, false)
        ->assertDontSee('Maklum balas pengguna lain');
});

it('opens the record finder for update, report and claim modes', function (): void {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['name' => 'Masjid Pencari', 'status' => 'verified']);
    $person = Person::factory()->create(['name' => 'Ustaz Pencari', 'status' => 'verified']);
    $reference = Reference::factory()->create(['title' => 'Kitab Pencari', 'status' => 'verified']);
    $event = Event::factory()->for($institution)->create([
        'title' => 'Majlis Pencari',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
    ]);

    $component = Livewire::actingAs($user)->test(ContributionsIndex::class);

    $component->call('openRecordFinder', 'update', 'event')
        ->assertSet('showRecordFinder', true)
        ->assertSet('finderMode', 'update')
        ->assertSet('finderSubjectType', 'event')
        ->assertSee(route('contributions.suggest-update', [
            'subjectType' => ContributionSubjectType::Event->publicRouteSegment(),
            'subjectId' => $event->slug,
        ]), false);

    $component->call('setFinderSubjectType', 'institution')
        ->assertSee(route('contributions.suggest-update', [
            'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
            'subjectId' => $institution->slug,
        ]), false);

    $component->call('setFinderSubjectType', 'person')
        ->assertSee(route('contributions.suggest-update', [
            'subjectType' => ContributionSubjectType::Person->publicRouteSegment(),
            'subjectId' => $person->slug,
        ]), false);

    $component->call('setFinderSubjectType', 'reference')
        ->assertSee(route('contributions.suggest-update', [
            'subjectType' => ContributionSubjectType::Reference->publicRouteSegment(),
            'subjectId' => $reference->slug,
        ]), false);

    $component->call('openRecordFinder', 'report', 'institution')
        ->assertSet('finderMode', 'report')
        ->assertSee(route('reports.create', [
            'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
            'subjectId' => $institution->slug,
        ]), false);

    $component->call('openRecordFinder', 'claim', 'event')
        ->assertSet('finderMode', 'claim')
        ->assertSet('finderSubjectType', 'institution')
        ->assertSee(route('membership-applications.create', [
            'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
            'subjectId' => (string) $institution->getKey(),
        ]), false);

    expect(array_keys($component->instance()->finderSubjectOptions))->toBe(['institution', 'person']);

    $component->call('closeRecordFinder')->assertSet('showRecordFinder', false);
});

it('blocks banned accounts from the record finder and membership claims', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo('feedback.blocked');
    Institution::factory()->create(['name' => 'Masjid Terbuka', 'status' => 'verified']);

    $component = Livewire::actingAs($user)->test(ContributionsIndex::class);

    $component->assertSee(__('Directory feedback is paused for your account'))
        ->assertSee($user->directoryFeedbackBanMessage())
        ->assertSee(route('contact'), false)
        ->assertSee(__('Feedback paused'))
        ->assertDontSee(route('app-feedback.index', ['dari' => '/sumbangan']), false);

    $component->call('openRecordFinder', 'update', 'event')
        ->assertSet('showRecordFinder', false)
        ->assertDispatched('app-toast');

    $component->call('selectTab', 'membership')
        ->assertSee(__('Directory feedback is paused for your account'))
        ->assertDontSee(__('Start a new claim'))
        ->assertDontSee('wire:model.live.debounce.300ms="claimSearch"', false);

    $component->call('selectClaimRecord', 'institusi-terbuka', 'Masjid Terbuka')
        ->call('startMembershipClaim')
        ->assertNoRedirect()
        ->assertDispatched('app-toast');

    expect($user->canSubmitDirectoryFeedback())->toBeFalse();
});

it('shows the managed workspace shortcuts to institution members', function (): void {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);

    addTestMember($institution, $user, MemberRole::Admin);

    Livewire::actingAs($user)->test(ContributionsIndex::class)
        ->assertSee(__('Managed workspace'))
        ->assertSee(route('dashboard.events.create-advanced'), false)
        ->assertSee(route('dashboard.institutions'), false)
        ->assertSee(__('Advanced Event Creator'))
        ->assertSee(__('Manage Institution'));
});

it('renders the hub cleanly in both supported locales', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    app()->setLocale('ms');
    Livewire::test(ContributionsIndex::class)
        ->assertOk()
        ->assertSee('Sumbangan Saya')
        ->assertSee('Ringkasan')
        ->assertSee('data-active-tab="overview"', false);

    app()->setLocale('en');
    Livewire::test(ContributionsIndex::class)
        ->assertOk()
        ->assertSee('My Contributions')
        ->assertSee('Overview');
});

it('returns immutable feedback snapshots through the reader boundary', function (): void {
    $user = User::factory()->create();

    $feedback = AppFeedback::factory()->triaged()->create([
        'reporter_id' => $user->id,
        'title' => 'Semakan pembaca',
    ]);

    AppFeedbackMessage::factory()->create([
        'feedback_id' => $feedback->id,
        'visibility' => 'reporter',
    ]);

    AppFeedbackMessage::factory()->create([
        'feedback_id' => $feedback->id,
        'visibility' => 'internal',
    ]);

    $reader = app(AppFeedbackReader::class);
    $page = $reader->pageForReporter((string) $user->getKey(), 8, 1);

    expect($page)->toBeInstanceOf(AppFeedbackPage::class)
        ->and($page->total)->toBe(1)
        ->and($page->items)->toHaveCount(1)
        ->and($page->items[0])->toBeInstanceOf(AppFeedbackSnapshot::class)
        ->and($page->items[0]->status)->toBe('triaged')
        ->and($page->items[0]->visibleMessagesCount)->toBe(1)
        ->and($page->items[0]->threadUrl)->toBe(route('app-feedback.index', ['lihat' => $feedback->id]).'#maklum-balas-'.$feedback->id);

    expect($reader->countsByStatusForReporter((string) $user->getKey()))->toBe(['triaged' => 1])
        ->and($reader->recentForReporter((string) $user->getKey()))->toHaveCount(1)
        ->and($reader->pageForReporter((string) $user->getKey(), 8, 1, 'resolved')->total)->toBe(0);

    $filtered = $reader->pageForReporter((string) $user->getKey(), 8, 1, null, 'pembaca');

    expect($filtered->total)->toBe(1)
        ->and($filtered->items[0]->id)->toBe((string) $feedback->getKey());

    $missing = $reader->pageForReporter((string) $user->getKey(), 8, 1, null, 'tiada-padanan');

    expect($missing->total)->toBe(0);
});

it('renders the translated page title on the contributions hub', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('contributions.index'))
        ->assertOk()
        ->assertSee('<title>'.__('My Contributions').' - '.config('app.name').'</title>', false);
});

it('keeps unfiltered membership metrics while filtering the membership tab', function (): void {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);

    MembershipApplication::factory()->for($institution, 'subject')->create([
        'applicant_id' => $user->id,
        'status' => ApplicationStatus::Pending,
        'justification' => 'Tuntutan menunggu semakan moderator.',
    ]);

    MembershipApplication::factory()->for($institution, 'subject')->approved()->create([
        'applicant_id' => $user->id,
        'granted_role' => MemberRole::Admin->value,
        'justification' => 'Tuntutan lama yang telah disahkan.',
    ]);

    $component = Livewire::actingAs($user)->test(ContributionsIndex::class);
    $component->call('selectTab', 'membership');

    $before = $component->instance()->summaryMetrics;

    expect($before['tabs']['membership'])->toBe(2)
        ->and($before['totals']['total'])->toBe(2)
        ->and($before['totals']['review'])->toBe(1)
        ->and($before['totals']['approved'])->toBe(1);

    $component->set('statusFilter', 'pending');

    expect($component->instance()->myMembershipClaims)->toHaveCount(1);

    $after = $component->instance()->summaryMetrics;

    expect($after['tabs']['membership'])->toBe(2)
        ->and($after['totals']['total'])->toBe(2)
        ->and($after['totals']['review'])->toBe(1)
        ->and($after['totals']['approved'])->toBe(1);
});

it('counts triaged reports as in review in the summary totals', function (): void {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);

    Report::factory()->create([
        'reporter_id' => $user->id,
        'entity_type' => $institution->getMorphClass(),
        'entity_id' => $institution->id,
        'status' => 'triaged',
        'category' => 'wrong_info',
        'description' => 'Sedang disemak oleh moderator.',
    ]);

    $metrics = Livewire::actingAs($user)->test(ContributionsIndex::class)->instance()->summaryMetrics;

    expect($metrics['tabs']['reports'])->toBe(1)
        ->and($metrics['totals']['total'])->toBe(1)
        ->and($metrics['totals']['review'])->toBe(1)
        ->and($metrics['totals']['approved'])->toBe(0)
        ->and($metrics['totals']['attention'])->toBe(0)
        ->and($metrics['totals']['review'] + $metrics['totals']['approved'] + $metrics['totals']['attention'])
        ->toBe($metrics['totals']['total']);
});

it('counts an occurrence submission once when its parent event is pending', function (): void {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    $event = Event::factory()->for($institution)->create([
        'title' => 'Majlis Induk Menunggu',
        'status' => 'pending',
        'visibility' => 'public',
    ]);
    $occurrence = EventOccurrence::factory()->create([
        'event_id' => $event->getKey(),
        'title' => 'Sesi Diluluskan',
        'status' => 'published',
    ]);

    EventSubmission::factory()->for($event)->for($user, 'submitter')->create([
        'event_occurrence_id' => $occurrence->getKey(),
    ]);

    $component = Livewire::actingAs($user)->test(ContributionsIndex::class);

    $component->call('selectTab', 'events')
        ->set('statusFilter', 'published')
        ->assertSee('Sesi Diluluskan');

    $component->set('statusFilter', 'pending')
        ->assertSee(__('No event submissions yet.'));

    $metrics = $component->instance()->summaryMetrics;

    expect($metrics['tabs']['events'])->toBe(1)
        ->and($metrics['totals']['total'])->toBe(1)
        ->and($metrics['totals']['approved'])->toBe(1)
        ->and($metrics['totals']['review'])->toBe(0);
});

it('scopes the record type filters by tab', function (): void {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test(ContributionsIndex::class);

    $component->call('selectTab', 'directory');

    expect(array_keys($component->instance()->subjectFilterOptions))
        ->toBe(['all', 'institution', 'person', 'reference']);

    $component->call('selectTab', 'updates');

    expect(array_keys($component->instance()->subjectFilterOptions))
        ->toBe(['all', 'event', 'institution', 'person', 'reference']);
});

it('links approved directory records and feedback threads from the hub', function (): void {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['name' => 'Masjid Terpaut', 'status' => 'verified']);

    $request = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Create,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => $institution->getMorphClass(),
        'entity_id' => $institution->id,
        'proposer_id' => $user->id,
        'status' => ContributionRequestStatus::Approved,
        'proposed_data' => ['name' => 'Masjid Terpaut'],
    ]);

    $feedback = AppFeedback::factory()->create([
        'reporter_id' => $user->id,
        'title' => 'Pautan maklum balas',
    ]);

    $publicUrl = route('institutions.show', $institution);

    $component = Livewire::actingAs($user)->test(ContributionsIndex::class);

    $component->call('selectTab', 'directory')
        ->assertSee(__('View public record'))
        ->assertSee($publicUrl, false);

    $activity = $component->instance()->recentActivity;
    $requestItem = collect($activity)->firstWhere('key', 'request-'.$request->id);
    $feedbackItem = collect($activity)->firstWhere('key', 'feedback-'.$feedback->id);

    expect($requestItem)->not->toBeNull()
        ->and($requestItem['url'])->toBe($publicUrl)
        ->and($requestItem['inspect_key'])->toBe('request:'.$request->id)
        ->and($feedbackItem)->not->toBeNull()
        ->and($feedbackItem['inspect_key'])->toBe('feedback:'.$feedback->id)
        ->and($feedbackItem['url'])->toBe(route('app-feedback.index', ['lihat' => $feedback->id]).'#maklum-balas-'.$feedback->id);
});

it('shows the managed institution note and membership claim notes', function (): void {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['name' => 'Masjid Nota', 'status' => 'verified']);

    addTestMember($institution, $user, MemberRole::Admin);

    MembershipApplication::factory()->for($institution, 'subject')->create([
        'applicant_id' => $user->id,
        'status' => ApplicationStatus::Pending,
        'meta' => ['notes' => 'Nota tambahan untuk penyemak.'],
    ]);

    $component = Livewire::actingAs($user)->test(ContributionsIndex::class);

    $component->call('selectTab', 'events')
        ->assertSeeInOrder([
            __('Events submitted for institutions you manage appear in your institution dashboard.'),
            __('Manage Institution'),
        ]);

    $component->call('selectTab', 'membership')
        ->assertSee(__('Catatan'))
        ->assertSee('Nota tambahan untuk penyemak.');
});

it('summarises recent activity cards meaningfully and opens the receipt instead of the public profile', function (): void {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['name' => 'Masjid Ringkasan', 'status' => 'verified']);

    $request = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Update,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => $institution->getMorphClass(),
        'entity_id' => $institution->id,
        'proposer_id' => $user->id,
        'status' => ContributionRequestStatus::Pending,
        'proposer_note' => 'Tolong kemas kini biografi masjid ini.',
        'proposed_data' => [
            'biography' => '<p>Biografi baharu masjid.</p>',
            'social_media' => [['platform' => 'facebook', 'handle' => '@masjidringkasan']],
            'address' => ['line1' => 'Jalan Baharu 1', 'city' => 'Shah Alam'],
        ],
        'original_data' => [
            'biography' => '<p>Biografi lama masjid.</p>',
            'social_media' => [['platform' => 'facebook', 'handle' => '@masjidlama']],
            'address' => ['line1' => 'Jalan Lama 1', 'city' => 'Klang'],
        ],
    ]);

    $component = Livewire::actingAs($user)->test(ContributionsIndex::class);

    $expectedSummary = __('Changed: :fields', ['fields' => implode(', ', [
        __('Biography'),
        __('Social Media'),
        __('Address'),
    ])]);

    $component->assertSee(__('Your latest contributions across all types, newest first.'))
        ->assertSee($expectedSummary)
        ->assertSee('Tolong kemas kini biografi masjid ini.')
        ->assertSee("inspectItem('request:".$request->id."')", false)
        ->assertSee(__('View details'))
        ->assertDontSee('href="'.route('institutions.show', $institution).'"', false);

    $activity = $component->instance()->recentActivity;
    $item = collect($activity)->firstWhere('key', 'request-'.$request->id);

    expect($item)->not->toBeNull()
        ->and($item['inspect_key'])->toBe('request:'.$request->id)
        ->and($item['summary'])->toBe($expectedSummary)
        ->and($item['proposer_note'])->toBe('Tolong kemas kini biografi masjid ini.');
});

it('renders the submission receipt drawer with readable labels, stripped html and staged media', function (): void {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $user = User::factory()->create();
    $institution = Institution::factory()->create(['name' => 'Masjid Resit', 'status' => 'verified']);

    $request = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Update,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => $institution->getMorphClass(),
        'entity_id' => $institution->id,
        'proposer_id' => $user->id,
        'status' => ContributionRequestStatus::Pending,
        'proposer_note' => 'Nota peribadi saya untuk penyemak.',
        'proposed_data' => [
            'biography' => '<p>Biografi   <strong>bersih</strong> tanpa tag.</p>',
            'contactMethods' => [['type' => 'phone', 'value' => '+60123456789']],
        ],
        'original_data' => [
            'biography' => '<p>Biografi lama.</p>',
            'contactMethods' => [['type' => 'phone', 'value' => '+60000000000']],
        ],
    ]);

    $request->addMedia(fakeGeneratedImageUpload('foto-baru.png'))
        ->usingFileName('foto-baru.png')
        ->withCustomProperties([
            'collection' => 'pending_media',
            'original_file_name' => 'foto-baru.png',
            'contribution_field' => 'logo',
            'is_single' => true,
        ])
        ->toMediaCollection('pending_media');

    $media = $request->fresh()->getFirstMedia('pending_media');

    expect($media)->not->toBeNull();

    $component = Livewire::actingAs($user)->test(ContributionsIndex::class);

    $component->call('inspectItem', 'request:'.$request->id)
        ->assertSet('showDetailDrawer', true)
        ->assertSet('inspect', 'request:'.$request->id)
        ->assertSee(__('Timeline'))
        ->assertSee(__('Submitted'))
        ->assertSee(__('Proposed Changes'))
        ->assertSee(__('Original'))
        ->assertSee(__('Proposed'))
        ->assertSee(__('Biography'))
        ->assertSee(__('Contact Details'))
        ->assertSee('Biografi bersih tanpa tag.')
        ->assertSee('Phone: +60123456789')
        ->assertSee(__('Uploaded Media'))
        ->assertSee(__('Logo'))
        ->assertSee('foto-baru.png')
        ->assertSee($media?->getUrl() ?? '', false)
        ->assertSee(__('Your note'))
        ->assertSee('Nota peribadi saya untuk penyemak.')
        ->assertSee(__('View public record'))
        ->assertSee(route('institutions.show', $institution), false)
        ->assertDontSee('<strong>bersih</strong>', false)
        ->assertDontSee('<p>Biografi', false);
});

it('renders submitted information for new directory submissions in the receipt drawer', function (): void {
    $user = User::factory()->create();
    $titleIdA = (string) Str::uuid();
    $titleIdB = (string) Str::uuid();

    $request = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Create,
        'subject_type' => ContributionSubjectType::Person,
        'entity_type' => null,
        'entity_id' => null,
        'proposer_id' => $user->id,
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => [
            'name' => 'Ustaz Resit Baharu',
            'biography' => '<p>Penceramah <em>aktif</em> di Selangor.</p>',
            'contactMethods' => [['type' => 'phone', 'value' => '+60123456789']],
            'title_ids' => [$titleIdA, $titleIdB],
        ],
    ]);

    $component = Livewire::actingAs($user)->test(ContributionsIndex::class);

    $component->call('inspectItem', 'request:'.$request->id)
        ->assertSet('showDetailDrawer', true)
        ->assertSee(__('Submitted Information'))
        ->assertSee('Ustaz Resit Baharu')
        ->assertSee('Penceramah aktif di Selangor.')
        ->assertSee('Phone: +60123456789')
        ->assertSee(trans_choice(':count item selected|:count items selected', 2, ['count' => 2]))
        ->assertSee(__('Titles'))
        ->assertDontSee($titleIdA, false)
        ->assertDontSee($titleIdB, false)
        ->assertDontSee('title_ids', false)
        ->assertDontSee('<em>aktif</em>', false);
});

it('opens the receipt drawer from the lihat deep link and keeps invalid or foreign keys closed', function (): void {
    $user = User::factory()->create();
    $intruder = User::factory()->create();
    $institution = Institution::factory()->create(['name' => 'Masjid Pautan Dalam', 'status' => 'verified']);

    $request = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Update,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => $institution->getMorphClass(),
        'entity_id' => $institution->id,
        'proposer_id' => $user->id,
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => ['name' => 'Masjid Pautan Dalam Baharu'],
        'original_data' => ['name' => 'Masjid Pautan Dalam'],
    ]);

    $foreign = ContributionRequest::factory()->create([
        'proposer_id' => $intruder->id,
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => ['name' => 'Rekod Asing Rahsia'],
    ]);

    Livewire::actingAs($user)
        ->withQueryParams(['lihat' => 'request:'.$request->id])
        ->test(ContributionsIndex::class)
        ->assertSet('showDetailDrawer', true)
        ->assertSet('inspect', 'request:'.$request->id)
        ->assertSee(__('Proposed Changes'))
        ->assertSee('Masjid Pautan Dalam Baharu');

    Livewire::actingAs($user)
        ->withQueryParams(['lihat' => 'request:not-a-uuid'])
        ->test(ContributionsIndex::class)
        ->assertSet('showDetailDrawer', false)
        ->assertSet('inspect', null);

    Livewire::actingAs($user)
        ->withQueryParams(['lihat' => 'request:'.$foreign->id])
        ->test(ContributionsIndex::class)
        ->assertSet('showDetailDrawer', false)
        ->assertSet('inspect', null)
        ->assertDontSee('Rekod Asing Rahsia');

    $this->actingAs($user)
        ->get(route('contributions.index', ['lihat' => 'request:'.$request->id]))
        ->assertOk()
        ->assertSee('Masjid Pautan Dalam Baharu')
        ->assertSee(__('Proposed Changes'));

    $this->actingAs($user)
        ->get(route('contributions.index', ['lihat' => 'request:'.$foreign->id]))
        ->assertOk()
        ->assertDontSee('Rekod Asing Rahsia');
});

it('inspects every contribution family and enforces ownership isolation', function (): void {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $user = User::factory()->create();
    $other = User::factory()->create();

    $institution = Institution::factory()->create(['name' => 'Masjid Keluarga Resit', 'status' => 'verified']);
    $event = Event::factory()->for($institution)->create([
        'title' => 'Majlis Keluarga Resit',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
    ]);

    $submission = EventSubmission::factory()->for($event)->for($user, 'submitter')->create();
    $foreignSubmission = EventSubmission::factory()->for($event)->for($other, 'submitter')->create();

    $report = Report::factory()->create([
        'reporter_id' => $user->id,
        'entity_type' => $institution->getMorphClass(),
        'entity_id' => $institution->id,
        'status' => 'open',
        'category' => 'wrong_info',
        'description' => 'Alamat masjid tidak tepat.',
    ]);
    $foreignReport = Report::factory()->create([
        'reporter_id' => $other->id,
        'entity_type' => $institution->getMorphClass(),
        'entity_id' => $institution->id,
        'status' => 'open',
        'category' => 'wrong_info',
        'description' => 'Laporan orang lain.',
    ]);

    $claim = MembershipApplication::factory()->for($institution, 'subject')->create([
        'applicant_id' => $user->id,
        'status' => ApplicationStatus::Pending,
        'justification' => 'Saya penjaga masjid ini.',
        'meta' => ['notes' => 'Nota tuntutan saya.'],
        'reviewer_note' => 'Sila sahkan bukti.',
    ]);
    $claim->addMedia(fakeGeneratedImageUpload('bukti-pemilikan.png'))
        ->usingFileName('bukti-pemilikan.png')
        ->toMediaCollection('evidence');
    $foreignClaim = MembershipApplication::factory()->for($institution, 'subject')->create([
        'applicant_id' => $other->id,
        'status' => ApplicationStatus::Pending,
    ]);

    $feedback = AppFeedback::factory()->create([
        'reporter_id' => $user->id,
        'title' => 'Resit maklum balas',
        'description' => 'Butang simpan tidak berfungsi.',
        'resolution_note' => 'Sedang disiasat.',
    ]);
    AppFeedbackMessage::factory()->create([
        'feedback_id' => $feedback->id,
        'visibility' => 'reporter',
    ]);
    $foreignFeedback = AppFeedback::factory()->create([
        'reporter_id' => $other->id,
        'title' => 'Maklum balas asing',
    ]);

    $component = Livewire::actingAs($user)->test(ContributionsIndex::class);

    $component->call('inspectItem', 'event:'.$submission->id)
        ->assertSet('showDetailDrawer', true)
        ->assertSee('Majlis Keluarga Resit')
        ->assertSee(route('events.show', $event), false);

    $component->call('inspectItem', 'report:'.$report->id)
        ->assertSet('showDetailDrawer', true)
        ->assertSee('Alamat masjid tidak tepat.')
        ->assertSee(__('Wrong information'));

    $component->call('inspectItem', 'claim:'.$claim->getKey())
        ->assertSet('showDetailDrawer', true)
        ->assertSee('Saya penjaga masjid ini.')
        ->assertSee('Nota tuntutan saya.')
        ->assertSee('Sila sahkan bukti.')
        ->assertSee(__('Catatan'))
        ->assertSee(trans_choice(':count evidence file|:count evidence files', 1, ['count' => 1]))
        ->assertSee('bukti-pemilikan', false)
        ->assertSee(route('institutions.show', $institution), false);

    $component->call('inspectItem', 'feedback:'.$feedback->id)
        ->assertSet('showDetailDrawer', true)
        ->assertSee('Butang simpan tidak berfungsi.')
        ->assertSee('Sedang disiasat.')
        ->assertSee(route('app-feedback.index', ['lihat' => $feedback->id]).'#maklum-balas-'.$feedback->id, false)
        ->assertSee(trans_choice(':count reply|:count replies', 1, ['count' => 1]));

    $component->call('closeDetail')
        ->assertSet('showDetailDrawer', false)
        ->assertSet('inspect', null);

    $component->call('inspectItem', 'event:'.$foreignSubmission->id)
        ->assertSet('showDetailDrawer', false)
        ->assertSet('inspect', null);

    $component->call('inspectItem', 'report:'.$foreignReport->id)
        ->assertSet('showDetailDrawer', false)
        ->assertSet('inspect', null)
        ->assertDontSee('Laporan orang lain.');

    $component->call('inspectItem', 'claim:'.$foreignClaim->getKey())
        ->assertSet('showDetailDrawer', false)
        ->assertSet('inspect', null);

    $component->call('inspectItem', 'feedback:'.$foreignFeedback->id)
        ->assertSet('showDetailDrawer', false)
        ->assertSet('inspect', null)
        ->assertDontSee('Maklum balas asing');

    $component->call('inspectItem', 'unknown:'.$report->id)
        ->assertSet('showDetailDrawer', false)
        ->assertSet('inspect', null);
});

it('cancels a pending request and a pending claim from inside the receipt drawer', function (): void {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['name' => 'Masjid Batal Resit', 'status' => 'verified']);

    $request = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Create,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => null,
        'entity_id' => null,
        'proposer_id' => $user->id,
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => ['name' => 'Masjid Batal Resit Baharu'],
    ]);

    $claim = MembershipApplication::factory()->for($institution, 'subject')->create([
        'applicant_id' => $user->id,
        'status' => ApplicationStatus::Pending,
        'justification' => 'Saya penjaga masjid ini.',
    ]);

    $component = Livewire::actingAs($user)->test(ContributionsIndex::class);

    $component->call('inspectItem', 'request:'.$request->id)
        ->assertSet('showDetailDrawer', true)
        ->assertSee(__('Cancel request'));

    $component->call('cancelContributionRequest', (string) $request->id)
        ->assertSet('showDetailDrawer', true)
        ->assertSee(__('Cancelled'))
        ->assertDontSee(__('Cancel request'));

    expect($request->fresh()->status)->toBe(ContributionRequestStatus::Cancelled);

    $component->call('inspectItem', 'claim:'.$claim->getKey())
        ->assertSet('showDetailDrawer', true)
        ->assertSee(__('Cancel claim'));

    $component->call('cancelMembershipApplication', (string) $claim->getKey())
        ->assertSet('showDetailDrawer', true)
        ->assertSee(__('Cancelled'))
        ->assertDontSee(__('Cancel claim'));

    expect($claim->fresh()->status)->toBe(ApplicationStatus::Cancelled);

    $component->set('showDetailDrawer', false)
        ->assertSet('inspect', null);

    $component->call('inspectItem', 'request:'.$request->id)
        ->assertSet('showDetailDrawer', true)
        ->call('closeDetail')
        ->assertSet('showDetailDrawer', false)
        ->assertSet('inspect', null);
});

it('formats receipt payload values without raw json, html or uuid leaks', function (): void {
    $presenter = app(ContributionsHubPresenter::class);
    $uuidA = (string) Str::uuid();
    $uuidB = (string) Str::uuid();

    expect($presenter->formatPayloadValue('description', '<p>Hello   <b>world</b></p>'))->toBe('Hello world')
        ->and($presenter->formatPayloadValue('biography', 'Plain text bio'))->toBe('Plain text bio')
        ->and($presenter->formatPayloadValue('notes', null))->toBe('—')
        ->and($presenter->formatPayloadValue('notes', ''))->toBe('—')
        ->and($presenter->formatPayloadValue('children_allowed', true))->toBe(__('Yes'))
        ->and($presenter->formatPayloadValue('is_muslim_only', false))->toBe(__('No'))
        ->and($presenter->formatPayloadValue('publication_year', 1999))->toBe('1999')
        ->and($presenter->formatPayloadValue('contactMethods', [['type' => 'phone', 'value' => '+60123456789']]))->toBe('Phone: +60123456789')
        ->and($presenter->formatPayloadValue('social_media', [['platform' => 'facebook', 'handle' => '@masjid']]))->toBe('Facebook: @masjid')
        ->and($presenter->formatPayloadValue('address', [
            'line1' => 'Jalan Baharu 1',
            'postcode' => '40150',
            'city' => 'Shah Alam',
            'country_id' => $uuidA,
            'google_place_id' => 'ChIJ-place-id',
            'area_assignments' => [$uuidB],
        ]))->toBe('Jalan Baharu 1, 40150, Shah Alam, '.__('Google Maps location'))
        ->and($presenter->formatPayloadValue('address', ['country_id' => $uuidA]))->toBe(__('Location updated'))
        ->and($presenter->formatPayloadValue('names', [['full_name' => 'Ahmad bin Ali', 'name_type' => 'full']]))->toBe('Ahmad bin Ali · Full')
        ->and($presenter->formatPayloadValue('donation_channels', [['bank_name' => 'Maybank', 'recipient' => 'Masjid Al-Huda', 'account_number' => '1234567890']]))->toBe('Maybank · Masjid Al-Huda · 1234567890')
        ->and($presenter->formatPayloadValue('institutions', [['institution_id' => $uuidA]]))->toBe(trans_choice(':count institution|:count institutions', 1, ['count' => 1]))
        ->and($presenter->formatPayloadValue('title_ids', [$uuidA, $uuidB]))->toBe(trans_choice(':count item selected|:count items selected', 2, ['count' => 2]))
        ->and($presenter->formatPayloadValue('name', $uuidA))->toBe(__('Selected record'))
        ->and($presenter->formatPayloadValue('language_ids', [$uuidA]))->toBe(trans_choice(':count item selected|:count items selected', 1, ['count' => 1]))
        ->and($presenter->formatPayloadValue('source_tags', ['ceramah', 'kuliah']))->toBe('ceramah, kuliah')
        ->and($presenter->formatPayloadValue('meta', ['submission_key' => $uuidA, 'visible' => 'yes']))->toBe('yes')
        ->and($presenter->formatPayloadValue('website', 'https://example.test'))->toBe('https://example.test');

    expect($presenter->fieldLabel('biography'))->toBe(__('Biography'))
        ->and($presenter->fieldLabel('family_name'))->toBe(__('Family Name'))
        ->and($presenter->fieldLabel('domain_tags'))->toBe(__('Main Topics'))
        ->and($presenter->fieldLabel('age_group'))->toBe(__('Age Group'))
        ->and($presenter->fieldLabel('custom_unknown_key'))->toBe('Custom Unknown Key')
        ->and($presenter->mediaFieldLabel('avatar'))->toBe(__('Profile Photo'))
        ->and($presenter->mediaFieldLabel('donation_qr'))->toBe(__('Donation QR Code'))
        ->and($presenter->mediaFieldLabel('something'))->toBe(__('Media'));
});

it('keeps the malay copy free of the retired family phrasing', function (): void {
    app()->setLocale('ms');

    $user = User::factory()->create();

    expect(__('Your latest contributions across all types, newest first.'))
        ->toBe('Sumbangan terkini anda merentas semua jenis sumbangan, terbaharu dahulu.');

    Livewire::actingAs($user)->test(ContributionsIndex::class)
        ->assertOk()
        ->assertSee('Sumbangan terkini anda merentas semua jenis sumbangan, terbaharu dahulu.')
        ->assertDontSee('merentas semua keluarga');

    app()->setLocale('en');

    Livewire::actingAs($user)->test(ContributionsIndex::class)
        ->assertOk()
        ->assertSee('Your latest contributions across all types, newest first.')
        ->assertDontSee('across every family');
});
