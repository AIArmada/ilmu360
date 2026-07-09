<?php

use AIArmada\Contacting\Enums\ContactMethodType;
use AIArmada\Contacting\Enums\ContactPurpose;
use AIArmada\FilamentAuthz\Facades\Authz;
use AIArmada\Signals\Models\SignalEvent;
use App\Enums\ContributionRequestStatus;
use App\Enums\ContributionRequestType;
use App\Enums\ContributionSubjectType;
use App\Enums\EventFormat;
use App\Enums\EventPrayerTime;
use App\Enums\EventType;
use App\Enums\MemberSubjectType;
use App\Livewire\Pages\Contributions\Index as ContributionsIndex;
use App\Livewire\Pages\Contributions\SubmitInstitution;
use App\Livewire\Pages\Contributions\SubmitSpeaker;
use App\Livewire\Pages\Contributions\SuggestUpdate;
use App\Livewire\Pages\Reports\Create as CreateReportPage;
use App\Models\ContributionRequest;
use App\Models\Event;
use App\Models\EventSubmission;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Report;
use App\Models\Speaker;
use App\Models\User;
use App\Models\Venue;
use App\Support\Authz\MemberRoleScopes;
use App\Support\Authz\ScopedMemberRoleSeeder;
use Database\Seeders\PermissionSeeder;
use Filament\Forms\Components\FileUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function assignInstitutionOwner(User $user, Institution $institution): void
{
    app(ScopedMemberRoleSeeder::class)->ensureForInstitution();
    $institution->members()->syncWithoutDetaching([$user->id]);

    Authz::withScope(app(MemberRoleScopes::class)->institution(), function () use ($user): void {
        $user->syncRoles(['owner']);
    }, $user);
}

function assignSpeakerOwner(User $user, Speaker $speaker): void
{
    app(ScopedMemberRoleSeeder::class)->ensureForSpeaker();
    $speaker->members()->syncWithoutDetaching([$user->id]);

    Authz::withScope(app(MemberRoleScopes::class)->speaker(), function () use ($user): void {
        $user->syncRoles(['owner']);
    }, $user);
}

it('renders the dedicated institution contribution page', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $this->get(route('contributions.submit-institution'))
        ->assertOk()
        ->assertSee(__('Add a New Institution'))
        ->assertSee(__('Address'))
        ->assertSee(__('Check the existing directory first'))
        ->assertSee(__('Before you submit, please check the existing institutions directory. If it already exists, submit an update instead of creating a new record.'))
        ->assertSee(__('Check Existing Institutions'))
        ->assertDontSee(__('View My Contributions'))
        ->assertDontSee(__('Need to add a speaker instead?'))
        ->assertDontSee(__('Submit Speaker'))
        ->assertDontSee(__('What happens next?'))
        ->assertDontSee(__('Submission Note'))
        ->assertDontSee('lg:grid-cols-2', false);
});

it('renders the institution contribution page with translated copy when the locale changes', function () {
    $user = User::factory()->create();

    app()->setLocale('ms');
    $this->actingAs($user);

    $this->get(route('contributions.submit-institution'))
        ->assertOk()
        ->assertSee('Sumbangan Komuniti')
        ->assertSee('Tambah Institusi Baru')
        ->assertSee('Hantar rekod institusi baru untuk direktori ilmu360°.')
        ->assertDontSee('Tambah Institusi Baharu')
        ->assertDontSee('Penyemak akan menilainya sebelum diterbitkan.')
        ->assertSee('Profil Institusi')
        ->assertSee('Alamat')
        ->assertDontSee(__('Find the institution location'))
        ->assertDontSee(__('Search like a ride-hailing destination, pick the correct place, then confirm it on the map before submitting.'))
        ->assertDontSee(__('Search for an institution or address'))
        ->assertSee('rekod baru')
        ->assertDontSee('rekod pendua')
        ->assertSee('Semak Institusi Sedia Ada')
        ->assertDontSee('Apa yang berlaku seterusnya?')
        ->assertDontSee('Lihat Sumbangan Saya');
});

it('renders the dedicated speaker contribution page', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $this->get(route('contributions.submit-speaker'))
        ->assertOk()
        ->assertSee(__('Add a New Speaker'))
        ->assertSee(__('Address'))
        ->assertSee(__('Check the existing directory first'))
        ->assertSee(__('Before you submit, please check the existing speakers directory. If it already exists, submit an update instead of creating a new record.'))
        ->assertSee(__('Check Existing Speakers'))
        ->assertDontSee(__('View My Contributions'))
        ->assertDontSee(__('Need to add an institution too?'))
        ->assertDontSee(__('Submit Institution'))
        ->assertDontSee(__('Review flow'))
        ->assertDontSee(__('Submission Note'))
        ->assertDontSee('lg:grid-cols-2', false);
});

it('renders the speaker contribution page with translated copy when the locale changes', function () {
    $user = User::factory()->create();

    app()->setLocale('ms');
    $this->actingAs($user);

    $this->get(route('contributions.submit-speaker'))
        ->assertOk()
        ->assertSee('Sumbangan Komuniti')
        ->assertSee('Tambah Penceramah Baru')
        ->assertSee('Hantar rekod penceramah baru untuk direktori ilmu360°.')
        ->assertDontSee('Tambah Penceramah Baharu')
        ->assertDontSee('Penyemak akan menilainya sebelum diterbitkan.')
        ->assertSee('Pendidikan')
        ->assertSee('Maklumat Perhubungan')
        ->assertSee('Semak direktori sedia ada dahulu')
        ->assertSee('Semak Penceramah Sedia Ada')
        ->assertSee('rekod baru')
        ->assertDontSee('Lihat Sumbangan Saya')
        ->assertDontSee('Add a New Speaker')
        ->assertDontSee('Education')
        ->assertDontSee('Contact Details');
});

it('shows speaker affiliation fields on the dedicated create and update forms', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
    ]);

    $this->actingAs($user);

    Livewire::test(SubmitSpeaker::class)
        ->assertFormFieldVisible('institution_id')
        ->set('data.institution_id', $institution->id)
        ->assertFormFieldVisible('institution_position');

    Livewire::test(SuggestUpdate::class, [
        'subjectType' => ContributionSubjectType::Speaker->publicRouteSegment(),
        'subjectId' => $speaker->slug,
    ])
        ->assertFormFieldVisible('institution_id')
        ->set('data.institution_id', $institution->id)
        ->assertFormFieldVisible('institution_position');
});

it('stores speaker affiliations from the dedicated speaker contribution page', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);

    $this->actingAs($user);

    Livewire::test(SubmitSpeaker::class)
        ->fillForm([
            'name' => 'Ustaz Pautan Institusi',
            'gender' => 'male',
            'institution_id' => $institution->id,
            'institution_position' => 'Mudir',
        ])
        ->call('submit')
        ->assertHasNoErrors();

    $speaker = Speaker::query()
        ->with('institutions')
        ->where('name', 'Ustaz Pautan Institusi')
        ->firstOrFail();

    $affiliatedInstitution = $speaker->institutions->firstWhere('id', $institution->id);

    expect($speaker->status)->toBe('pending')
        ->and($affiliatedInstitution)->not->toBeNull()
        ->and($affiliatedInstitution?->pivot?->position)->toBe('Mudir')
        ->and((bool) $affiliatedInstitution?->pivot?->is_primary)->toBeTrue();
});

it('renders the institution contribution submission success page', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $this->withSession(['contribution_submission_name' => 'Masjid Al-Huda'])
        ->get(route('contributions.submission-success', ['subjectType' => ContributionSubjectType::Institution->publicRouteSegment()]))
        ->assertOk()
        ->assertSee('Masjid Al-Huda')
        ->assertSee(__('Thank you for submitting a new institution.'))
        ->assertSee(__('Jejaki sumbangan anda dan statusnya.'))
        ->assertDontSee('We appreciate you taking the time to grow the ilmu360° directory. Our team will review your submission carefully.')
        ->assertDontSee(__('We will notify you once your submission has been approved or rejected.'))
        ->assertDontSee(__('What happens next?'))
        ->assertSee(__('Explore Institutions'))
        ->assertSee(__('Browse Events'))
        ->assertSee(__('My Contributions'));
});

it('renders the speaker contribution submission success page', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $this->withSession(['contribution_submission_name' => 'Ustaz Cadangan Baru'])
        ->get(route('contributions.submission-success', ['subjectType' => ContributionSubjectType::Speaker->publicRouteSegment()]))
        ->assertOk()
        ->assertSee('Ustaz Cadangan Baru')
        ->assertSee(__('Thank you for submitting a new speaker.'))
        ->assertSee(__('Jejaki sumbangan anda dan statusnya.'))
        ->assertDontSee('We appreciate you taking the time to grow the ilmu360° directory. Our team will review your submission carefully.')
        ->assertDontSee(__('We will notify you once your submission has been approved or rejected.'))
        ->assertDontSee(__('What happens next?'))
        ->assertDontSee('Apa yang berlaku seterusnya?')
        ->assertSee(__('Explore Speakers'))
        ->assertSee(__('Browse Events'))
        ->assertSee(__('My Contributions'));
});

it('renders the institution contribution submission success page with translated copy when the locale changes', function () {
    $user = User::factory()->create();

    app()->setLocale('ms');
    $this->actingAs($user);

    $this->get(route('contributions.submission-success', ['subjectType' => ContributionSubjectType::Institution->publicRouteSegment()]))
        ->assertOk()
        ->assertSee(__('Thank you for submitting a new institution.'))
        ->assertSee('Jejaki sumbangan anda dan statusnya.')
        ->assertDontSee('Kami menghargai masa anda untuk membantu mengembangkan direktori ilmu360°. Pasukan kami akan menyemak sumbangan anda dengan teliti.')
        ->assertDontSee('Kami akan memaklumkan anda sebaik sahaja sumbangan anda diluluskan atau ditolak.')
        ->assertDontSee('Apa yang berlaku seterusnya?')
        ->assertSee('Terokai Institusi')
        ->assertSee('Lihat Majlis')
        ->assertSee('Sumbangan Saya');
});

it('renders the speaker contribution submission success page with translated copy when the locale changes', function () {
    $user = User::factory()->create();

    app()->setLocale('ms');
    $this->actingAs($user);

    $this->get(route('contributions.submission-success', ['subjectType' => ContributionSubjectType::Speaker->publicRouteSegment()]))
        ->assertOk()
        ->assertSee('Terima kasih kerana menghantar penceramah baru.')
        ->assertSee('Jejaki sumbangan anda dan statusnya.')
        ->assertDontSee('Apa yang berlaku seterusnya?')
        ->assertDontSee('What happens next?')
        ->assertSee('Terokai Penceramah')
        ->assertSee('Lihat Majlis')
        ->assertSee('Sumbangan Saya');
});

it('keeps reviewer context fields on update suggestion pages', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);

    $this->actingAs($user);

    $this->get(route('contributions.suggest-update', ['subjectType' => 'institusi', 'subjectId' => $institution->slug]))
        ->assertOk()
        ->assertSee(__('Explain the change'))
        ->assertSee(__('Optional: add context that helps maintainers review your update faster.'));
});

it('hides reviewer context fields for direct institution edits', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);

    assignInstitutionOwner($user, $institution);

    $this->actingAs($user);

    $this->get(route('contributions.suggest-update', [
        'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
        'subjectId' => $institution->slug,
    ]))
        ->assertOk()
        ->assertDontSee(__('Explain the change'))
        ->assertDontSee(__('Optional: add context that helps maintainers review your update faster.'));
});

it('renders the action modal stack on event update pages for create-option fields', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $event = Event::factory()->for($institution)->create([
        'title' => 'Majlis Dengan Modal Tindakan',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subMinute(),
        'event_type' => [EventType::Iftar->value],
        'institution_id' => $institution->id,
        'starts_at' => now()->addDays(3)->setTime(20, 0),
    ]);
    $event->setPrimaryOrganizer($institution);

    $this->actingAs($user);

    $this->get(route('contributions.suggest-update', [
        'subjectType' => ContributionSubjectType::Event->publicRouteSegment(),
        'subjectId' => $event->slug,
    ]))
        ->assertOk()
        ->assertSee('wire:partial="action-modals"', false)
        ->assertSee('filamentActionModals({', false);
});

it('renders the suggest update page with translated event form copy when the locale changes', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $event = Event::factory()->for($institution)->create([
        'title' => 'Majlis Terjemahan',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subMinute(),
        'event_type' => [EventType::Iftar->value],
        'institution_id' => $institution->id,
        'starts_at' => now()->addDays(3)->setTime(20, 0),
    ]);
    $event->setPrimaryOrganizer($institution);

    app()->setLocale('ms');
    $this->actingAs($user);

    $this->get(route('contributions.suggest-update', [
        'subjectType' => ContributionSubjectType::Event->publicRouteSegment(),
        'subjectId' => $event->slug,
    ]))
        ->assertOk()
        ->assertSee('Cadangan Kemas Kini')
        ->assertSee('Terangkan perubahan')
        ->assertSee('Audiens & Bahasa')
        ->assertSee('Penganjur & Lokasi')
        ->assertSee('bg-white pb-0', false)
        ->assertSee('max-w-5xl flex-col gap-2 px-4 py-2', false)
        ->assertDontSee('rounded-3xl border border-slate-200 bg-white px-4 py-5 shadow-sm', false)
        ->assertDontSee('Cadangan Komuniti')
        ->assertDontSee('Community Suggestion')
        ->assertDontSee('Suggest an Update')
        ->assertDontSee('Cadangkan Kemas Kini')
        ->assertDontSee('Explain the change')
        ->assertDontSee('Hantar permintaan perubahan berstruktur supaya pemilik atau pasukan pentadbir boleh menyemaknya tanpa menjejaskan sejarah rekod semasa.')
        ->assertDontSee('Audience & Language')
        ->assertDontSee('Organizer & Location');
});

it('renders the institution suggest update page with translated direct-edit copy when the locale changes', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);

    assignInstitutionOwner($user, $institution);

    app()->setLocale('ms');
    $this->actingAs($user);

    $this->get(route('contributions.suggest-update', [
        'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
        'subjectId' => $institution->slug,
    ]))
        ->assertOk()
        ->assertSee('Kemas Kini')
        ->assertDontSee('Cadangan Kemas Kini')
        ->assertDontSee('Cadangan Komuniti')
        ->assertDontSee('Terangkan perubahan')
        ->assertDontSee('Laksanakan Kemas Kini')
        ->assertDontSee(__('View My Contributions'));
});

it('renders the speaker suggest update page with the compact shared shell', function () {
    $user = User::factory()->create();
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
    ]);

    app()->setLocale('ms');
    $this->actingAs($user);

    $this->get(route('contributions.suggest-update', [
        'subjectType' => ContributionSubjectType::Speaker->publicRouteSegment(),
        'subjectId' => $speaker->slug,
    ]))
        ->assertOk()
        ->assertSee('Cadangan Kemas Kini')
        ->assertSee('bg-white pb-0', false)
        ->assertSee('max-w-5xl flex-col gap-2 px-4 py-2', false)
        ->assertDontSee('Cadangan Komuniti')
        ->assertDontSee('Cadangkan Kemas Kini');
});

it('shows the institution media uploads on the suggest update page only for maintainers', function () {
    $owner = User::factory()->create();
    $visitor = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);

    $this->actingAs($visitor);

    $this->get(route('contributions.suggest-update', [
        'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
        'subjectId' => $institution->slug,
    ]))
        ->assertOk()
        ->assertDontSee(__('View My Contributions'))
        ->assertDontSee(__('Cover Image'))
        ->assertDontSee(__('Gallery'));

    assignInstitutionOwner($owner, $institution);
    $this->actingAs($owner);

    $this->get(route('contributions.suggest-update', [
        'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
        'subjectId' => $institution->slug,
    ]))
        ->assertOk()
        ->assertDontSee(__('View My Contributions'))
        ->assertSee(__('Cover Image'))
        ->assertSee(__('Gallery'));
});

it('uses the institution location picker on the suggest update page when google places is enabled', function () {
    config()->set('services.google.maps_api_key', 'test-maps-key');
    config()->set('services.google.places_enabled', true);

    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);

    assignInstitutionOwner($user, $institution);
    $this->actingAs($user);

    $this->get(route('contributions.suggest-update', [
        'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
        'subjectId' => $institution->slug,
    ]))
        ->assertOk()
        ->assertDontSee(__('Find the institution location'))
        ->assertSee(__('Search for an institution or address'));
});

it('shows the speaker media uploads on the suggest update page only for maintainers', function () {
    $owner = User::factory()->create();
    $visitor = User::factory()->create();
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
        'bio' => null,
    ]);

    $this->actingAs($visitor);

    $this->get(route('contributions.suggest-update', [
        'subjectType' => ContributionSubjectType::Speaker->publicRouteSegment(),
        'subjectId' => $speaker->slug,
    ]))
        ->assertOk()
        ->assertDontSee(__('View My Contributions'))
        ->assertDontSee(__('Avatar'))
        ->assertDontSee(__('Cover Image'))
        ->assertDontSee(__('Gallery'));

    assignSpeakerOwner($owner, $speaker);
    $this->actingAs($owner);

    $this->get(route('contributions.suggest-update', [
        'subjectType' => ContributionSubjectType::Speaker->publicRouteSegment(),
        'subjectId' => $speaker->slug,
    ]))
        ->assertOk()
        ->assertDontSee(__('View My Contributions'))
        ->assertSee(__('Avatar'))
        ->assertSee(__('Cover Image'))
        ->assertSee(__('Gallery'));
});

it('applies direct speaker affiliation edits for owner maintainers from the suggest update page', function () {
    $user = User::factory()->create();
    $currentInstitution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $secondaryInstitution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $newInstitution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
    ]);

    $speaker->institutions()->detach();

    $speaker->institutions()->attach($currentInstitution->id, [
        'position' => 'Imam',
        'is_primary' => true,
    ]);
    $speaker->institutions()->attach($secondaryInstitution->id, [
        'position' => 'Advisor',
        'is_primary' => false,
    ]);

    assignSpeakerOwner($user, $speaker);
    $this->actingAs($user);

    Livewire::test(SuggestUpdate::class, [
        'subjectType' => ContributionSubjectType::Speaker->publicRouteSegment(),
        'subjectId' => $speaker->slug,
    ])
        ->set('data.institution_id', $newInstitution->id)
        ->set('data.institution_position', 'Mudir')
        ->call('submit')
        ->assertHasNoErrors();

    $speaker = $speaker->fresh('institutions');
    $newAffiliation = $speaker?->institutions->firstWhere('id', $newInstitution->id);
    $secondaryAffiliation = $speaker?->institutions->firstWhere('id', $secondaryInstitution->id);

    expect($speaker?->institutions->pluck('id')->all())->toContain($newInstitution->id, $secondaryInstitution->id)
        ->not->toContain($currentInstitution->id)
        ->and($newAffiliation)->not->toBeNull()
        ->and($newAffiliation?->pivot?->position)->toBe('Mudir')
        ->and((bool) $newAffiliation?->pivot?->is_primary)->toBeTrue()
        ->and((bool) $secondaryAffiliation?->pivot?->is_primary)->toBeFalse()
        ->and(ContributionRequest::query()->count())->toBe(0);
});

it('exposes Filament action handlers required by public contribution media uploads', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    assignInstitutionOwner($user, $institution);

    $this->actingAs($user);

    expect(method_exists(Livewire::test(SubmitInstitution::class)->instance(), 'mountAction'))->toBeTrue()
        ->and(method_exists(Livewire::test(SubmitSpeaker::class)->instance(), 'mountAction'))->toBeTrue()
        ->and(method_exists(Livewire::test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
            'subjectId' => $institution->slug,
        ])->instance(), 'mountAction'))->toBeTrue();
});

it('applies direct institution edits for owner maintainers from the suggest update page', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'name' => 'Masjid Lama',
        'nickname' => null,
        'status' => 'verified',
    ]);
    withGlobalOwnerContext(fn () => $institution->contacts()->delete());

    assignInstitutionOwner($user, $institution);
    $this->actingAs($user);

    Livewire::test(SuggestUpdate::class, [
        'subjectType' => 'institution',
        'subjectId' => $institution->slug,
    ])
        ->set('data.name', 'Masjid Baru')
        ->set('data.nickname', 'Masjid Segar')
        ->call('submit')
        ->assertHasNoErrors();

    expect($institution->fresh()->name)->toBe('Masjid Baru')
        ->and($institution->fresh()->nickname)->toBe('Masjid Segar')
        ->and(ContributionRequest::query()->count())->toBe(0);
});

it('applies direct institution edits when an existing phone contact is present on the suggest update page', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'name' => 'Masjid Lama',
        'nickname' => null,
        'status' => 'verified',
    ]);
    withGlobalOwnerContext(function () use ($institution): void {
        $institution->contacts()->delete();
        $institution->contacts()->create([
            'type' => ContactMethodType::Phone->value,
            'purpose' => ContactPurpose::General->value,
            'value' => '+60112223344',
            'is_public' => true,
        ]);
    });

    assignInstitutionOwner($user, $institution);
    $this->actingAs($user);

    Livewire::test(SuggestUpdate::class, [
        'subjectType' => 'institution',
        'subjectId' => $institution->slug,
    ])
        ->set('data.nickname', 'Masjid Telefon')
        ->call('submit')
        ->assertHasNoErrors();

    expect($institution->fresh()->nickname)->toBe('Masjid Telefon')
        ->and(withGlobalOwnerContext(fn () => $institution->fresh()->contacts()->where('type', ContactMethodType::Phone->value)->value('value')))
        ->not->toBeNull()
        ->not->toBeEmpty()
        ->and(ContributionRequest::query()->count())->toBe(0);
});

it('applies direct institution address edits for owner maintainers from the suggest update page', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    withGlobalOwnerContext(fn () => $institution->contacts()->delete());

    assignInstitutionOwner($user, $institution);
    $this->actingAs($user);

    Livewire::test(SuggestUpdate::class, [
        'subjectType' => 'institution',
        'subjectId' => $institution->slug,
    ])
        ->set('data.address.line1', 'No. 21, Jalan Disemak')
        ->call('submit')
        ->assertHasNoErrors();

    expect($institution->fresh()->addressModel?->line1)->toBe('No. 21, Jalan Disemak')
        ->and(ContributionRequest::query()->count())->toBe(0);
});

it('applies direct institution cover edits for owner maintainers from the suggest update page', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);

    assignInstitutionOwner($user, $institution);
    $this->actingAs($user);

    Livewire::test(SuggestUpdate::class, [
        'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
        'subjectId' => $institution->slug,
    ])
        ->fillForm([
            'cover' => UploadedFile::fake()->image('institution-cover.jpg', 1600, 900),
        ])
        ->call('submit')
        ->assertHasNoErrors();

    expect($institution->fresh()->getMedia('cover'))->toHaveCount(1)
        ->and($institution->fresh()->getFirstMedia('cover'))->not->toBeNull()
        ->and(ContributionRequest::query()->count())->toBe(0);
});

it('applies direct institution gallery edits for owner maintainers from the suggest update page', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);

    assignInstitutionOwner($user, $institution);
    $this->actingAs($user);

    Livewire::test(SuggestUpdate::class, [
        'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
        'subjectId' => $institution->slug,
    ])
        ->fillForm([
            'gallery' => [UploadedFile::fake()->image('institution-gallery.jpg', 1600, 900)],
        ])
        ->call('submit')
        ->assertHasNoErrors();

    expect($institution->fresh()->getMedia('gallery'))->toHaveCount(1)
        ->and(ContributionRequest::query()->count())->toBe(0);
});

it('keeps approved events approved when maintainers apply sensitive ordinary edits from the suggest update page', function () {
    config(['events.features.owner.enabled' => false]);
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $event = Event::factory()->for($institution)->create([
        'title' => 'Majlis Sensitif',
        'status' => 'approved',
        'event_type' => [EventType::Iftar->value],
        'starts_at' => now()->addDays(4)->setTime(20, 0),
        'ends_at' => now()->addDays(4)->setTime(21, 0),
    ]);
    $event->setPrimaryOrganizer($institution);

    assignInstitutionOwner($user, $institution);
    $this->actingAs($user);

    Livewire::test(SuggestUpdate::class, [
        'subjectType' => ContributionSubjectType::Event->publicRouteSegment(),
        'subjectId' => $event->slug,
    ])
        ->set('data.event_date', now()->addDays(8)->toDateString())
        ->set('data.prayer_time', EventPrayerTime::LainWaktu->value)
        ->set('data.custom_time', '20:00')
        ->set('data.end_time', '21:00')
        ->call('submit')
        ->assertHasNoErrors();

    expect((string) $event->fresh()->status)->toBe('approved')
        ->and(ContributionRequest::query()->count())->toBe(0);
});

it('shows the richer event update controls for maintainers', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $event = Event::factory()->for($institution)->create([
        'title' => 'Majlis Dengan Media',
        'status' => 'approved',
        'event_type' => [EventType::Iftar->value],
        'institution_id' => $institution->id,
        'starts_at' => now()->addDays(4)->setTime(20, 0),
    ]);
    $event->setPrimaryOrganizer($institution);

    assignInstitutionOwner($user, $institution);

    $this->actingAs($user);

    Livewire::test(SuggestUpdate::class, [
        'subjectType' => ContributionSubjectType::Event->publicRouteSegment(),
        'subjectId' => $event->slug,
    ])
        ->assertFormFieldVisible('event_date')
        ->assertFormFieldVisible('prayer_time')
        ->assertFormFieldVisible('primary_organizer_institution_id')
        ->assertFormFieldVisible('cover')
        ->assertFormFieldVisible('poster')
        ->assertFormFieldVisible('gallery');
});

it('shows visible aspect ratio options for direct event media edits on the kemas kini page', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $event = Event::factory()->for($institution)->create([
        'title' => 'Majlis Dengan Nisbah Media',
        'status' => 'approved',
        'event_type' => [EventType::Iftar->value],
        'institution_id' => $institution->id,
        'starts_at' => now()->addDays(4)->setTime(20, 0),
    ]);
    $event->setPrimaryOrganizer($institution);

    assignInstitutionOwner($user, $institution);

    $this->actingAs($user);

    Livewire::test(SuggestUpdate::class, [
        'subjectType' => ContributionSubjectType::Event->publicRouteSegment(),
        'subjectId' => $event->slug,
    ])
        ->assertFormFieldExists('cover', function (FileUpload $upload): bool {
            $aspectRatioOptions = array_keys($upload->getImageEditorAspectRatioOptionsForJs());

            expect($upload->getImageAspectRatio())
                ->toBe('16:9')
                ->and($aspectRatioOptions)
                ->toContain('16:9')
                ->toHaveCount(2);

            return true;
        })
        ->assertFormFieldExists('poster', function (FileUpload $upload): bool {
            $aspectRatioOptions = array_keys($upload->getImageEditorAspectRatioOptionsForJs());

            expect($upload->getImageAspectRatio())
                ->toBe('4:5')
                ->and($aspectRatioOptions)
                ->toContain('4:5')
                ->toHaveCount(2);

            return true;
        });
});

it('renders the submit-style waktu field on the event update page', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $event = Event::factory()->for($institution)->create([
        'title' => 'Majlis Ada Waktu',
        'status' => 'approved',
        'event_type' => [EventType::Iftar->value],
        'institution_id' => $institution->id,
        'starts_at' => now()->addDays(3)->setTime(20, 0),
    ]);
    $event->setPrimaryOrganizer($institution);

    $this->actingAs($user);

    $this->get(route('contributions.suggest-update', [
        'subjectType' => ContributionSubjectType::Event->publicRouteSegment(),
        'subjectId' => $event->slug,
    ]))
        ->assertOk()
        ->assertSee(__('Waktu'))
        ->assertSee(__('Masa Mula'))
        ->assertSee(__('Jenis Penganjur'));
});

it('prefills submit-style organizer and location fields on the event update page', function () {
    $user = User::factory()->create();
    $organizerInstitution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $venue = Venue::factory()->create([
        'status' => 'verified',
    ]);
    $event = Event::factory()->create([
        'title' => 'Majlis Dengan Lokasi Venue',
        'status' => 'approved',
        'event_format' => EventFormat::Physical,
        'institution_id' => null,
        'venue_id' => $venue->id,
        'starts_at' => now()->addDays(4)->setTime(20, 0),
    ]);
    $event->setPrimaryOrganizer($organizerInstitution);

    assignInstitutionOwner($user, $organizerInstitution);

    $this->actingAs($user);

    Livewire::test(SuggestUpdate::class, [
        'subjectType' => ContributionSubjectType::Event->publicRouteSegment(),
        'subjectId' => $event->slug,
    ])
        ->assertSet('data.primary_organizer_id', $organizerInstitution->id)
        ->assertSet('data.primary_organizer_kind', 'institution')
        ->assertSet('data.primary_organizer_institution_id', $organizerInstitution->id)
        ->assertSet('data.location_same_as_institution', false)
        ->assertSet('data.location_type', 'venue')
        ->assertSet('data.location_venue_id', $venue->id);
});

it('normalizes submit-style organizer and location changes on the event update page', function () {
    config(['events.features.owner.enabled' => false]);
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
    ]);
    $venue = Venue::factory()->create([
        'status' => 'verified',
    ]);
    $event = Event::factory()->for($institution)->create([
        'title' => 'Majlis Tukar Penganjur',
        'status' => 'approved',
        'event_type' => [EventType::Iftar->value],
        'event_format' => EventFormat::Physical,
        'institution_id' => $institution->id,
        'venue_id' => null,
        'starts_at' => now()->addDays(5)->setTime(20, 0),
        'ends_at' => now()->addDays(5)->setTime(21, 0),
    ]);
    $event->setPrimaryOrganizer($institution);

    assignInstitutionOwner($user, $institution);
    $this->actingAs($user);

    Livewire::test(SuggestUpdate::class, [
        'subjectType' => ContributionSubjectType::Event->publicRouteSegment(),
        'subjectId' => $event->slug,
    ])
        ->set('data.primary_organizer_kind', 'speaker')
        ->set('data.primary_organizer_id', $speaker->id)
        ->set('data.primary_organizer_speaker_id', $speaker->id)
        ->set('data.location_same_as_institution', false)
        ->set('data.location_type', 'venue')
        ->set('data.location_venue_id', $venue->id)
        ->call('submit')
        ->assertHasNoErrors();

    $involvement = $event->fresh()->primaryOrganizerInvolvement;
    expect($involvement?->involveable_type)->toBe(Speaker::class)
        ->and($involvement?->involveable_id)->toBe((string) $speaker->getKey())
        ->and($event->fresh()->institution_id)->toBeNull()
        ->and($event->fresh()->venue_id)->toBe($venue->id)
        ->and($event->fresh()->space_id)->toBeNull()
        ->and(ContributionRequest::query()->count())->toBe(0);
});

it('removes the workflow explainer from the event update page', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $event = Event::factory()->for($institution)->create([
        'title' => 'Majlis Komuniti',
        'status' => 'approved',
        'starts_at' => now()->addDays(3),
    ]);

    $this->actingAs($user);

    $this->get(route('contributions.suggest-update', [
        'subjectType' => ContributionSubjectType::Event->publicRouteSegment(),
        'subjectId' => $event->slug,
    ]))
        ->assertOk()
        ->assertDontSee(__('Structured change set'))
        ->assertDontSee(__('Owner or admin review'))
        ->assertDontSee(__('History is preserved'));
});

it('creates pending update requests for non-maintainer suggestions', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'description' => 'Old description',
        'status' => 'verified',
    ]);
    withGlobalOwnerContext(fn () => $institution->contacts()->delete());

    $this->actingAs($user);

    Livewire::test(SuggestUpdate::class, [
        'subjectType' => 'institution',
        'subjectId' => $institution->slug,
    ])
        ->set('data.description', 'Updated community description')
        ->set('data.address.line1', 'No. 8, Jalan Baru')
        ->set('data.proposer_note', 'This institution recently expanded its programs.')
        ->call('submit')
        ->assertHasNoErrors();

    $request = ContributionRequest::query()->latest('created_at')->first();

    expect($request)->not->toBeNull()
        ->and($request?->type)->toBe(ContributionRequestType::Update)
        ->and($request?->entity_id)->toBe($institution->id)
        ->and($request?->status)->toBe(ContributionRequestStatus::Pending)
        ->and(trim(strip_tags((string) ($request?->proposed_data['description'] ?? ''))))->toBe('Updated community description')
        ->and(data_get($request?->proposed_data, 'address.line1'))->toBe('No. 8, Jalan Baru');
});

it('shows the latest pending request notice on the suggest update page', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    ContributionRequest::factory()->create([
        'proposer_id' => $user->id,
        'entity_type' => $institution->getMorphClass(),
        'entity_id' => $institution->id,
        'status' => ContributionRequestStatus::Pending,
    ]);

    $this->actingAs($user);

    Livewire::test(SuggestUpdate::class, [
        'subjectType' => 'institution',
        'subjectId' => $institution->slug,
    ])
        ->assertSee(__('Pending Request'))
        ->assertSee('Anda sudah mempunyai permintaan kemas kini yang masih menunggu untuk rekod ini sejak');
});

it('renders contribution requests and event submissions without approval controls', function () {
    app()->setLocale('ms');

    $user = User::factory()->create();
    $requestInstitution = Institution::factory()->create([
        'name' => 'Masjid Al-Huda',
        'status' => 'verified',
    ]);
    $eventInstitution = Institution::factory()->create([
        'name' => 'Masjid Al-Ihsan',
        'status' => 'verified',
    ]);
    $ownedEventInstitution = Institution::factory()->create([
        'name' => 'Masjid Ahli',
        'status' => 'verified',
    ]);
    $speaker = Speaker::factory()->create([
        'name' => 'Ustaz Ahmad',
        'status' => 'verified',
    ]);
    $reference = Reference::factory()->create([
        'title' => 'Rujukan Majlis Ilmu',
        'status' => 'verified',
    ]);
    $createRequest = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Create,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => null,
        'entity_id' => null,
        'proposer_id' => $user->id,
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => [
            'name' => 'Masjid Al-Huda Baharu',
        ],
        'proposer_note' => 'Please add this institution.',
    ]);
    $updateRequest = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Update,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => $requestInstitution->getMorphClass(),
        'entity_id' => $requestInstitution->id,
        'proposer_id' => $user->id,
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => [
            'name' => 'Masjid Al-Huda Baharu',
        ],
        'original_data' => [
            'name' => 'Masjid Al-Huda',
        ],
        'proposer_note' => 'Please update the institution name.',
    ]);
    $event = Event::factory()->for($eventInstitution)->create([
        'title' => 'Majlis Ilmu Tracker',
        'status' => 'pending',
        'visibility' => 'public',
    ]);
    $ownedEvent = Event::factory()->for($ownedEventInstitution)->create([
        'title' => 'Majlis Institusi Sendiri',
        'status' => 'pending',
        'visibility' => 'public',
    ]);

    $event->speakers()->syncWithoutDetaching([$speaker->id]);
    $event->references()->syncWithoutDetaching([$reference->id]);

    EventSubmission::factory()->for($event)->for($user, 'submitter')->create([
        'notes' => 'Submitted through the public event flow.',
    ]);
    EventSubmission::factory()->for($ownedEvent)->for($user, 'submitter')->create([
        'notes' => 'This one should move to the institution dashboard.',
    ]);

    assignInstitutionOwner($user, $ownedEventInstitution);

    $reportInstitution = Institution::factory()->create([
        'name' => 'Masjid Lapor',
        'status' => 'verified',
    ]);

    Report::factory()->create([
        'reporter_id' => $user->id,
        'entity_type' => $reportInstitution->getMorphClass(),
        'entity_id' => $reportInstitution->id,
        'status' => 'open',
        'category' => 'wrong_info',
        'description' => 'The contact details are outdated.',
    ]);

    $this->actingAs($user);

    Livewire::test(ContributionsIndex::class)
        ->assertSee(__('Submit Event'))
        ->assertSee(__('Submit Institution'))
        ->assertSee(__('Submit Speaker'))
        ->assertDontSee('xl:grid-cols-[1.15fr_0.85fr]', false)
        ->assertSee(__('Event Submissions'))
        ->assertSee(__('New Submissions'))
        ->assertSee(__('Update Submissions'))
        ->assertSee(__('Report Submissions'))
        ->assertSee(__('Requests for new institutions and speakers.'))
        ->assertSee(__('Updates you submit here will appear with their status and review notes.'))
        ->assertSee(__('Reports you submit here will appear with their status and review notes.'))
        ->assertSee(__('Membership Claims'))
        ->assertDontSee('Jika anda benar-benar mengurus institusi atau penceramah tertentu, cari rekodnya di sini dan teruskan ke borang tuntutan. Laluan ini diletakkan di halaman sumbangan kerana ia hanya relevan kepada sebilangan kecil pengguna.')
        ->assertSee($createRequest->proposed_data['name'])
        ->assertSee($updateRequest->entity->name)
        ->assertSee(__('Event Submission'))
        ->assertSee($event->title)
        ->assertDontSee($ownedEvent->title)
        ->assertSee(route('events.show', $event), false)
        ->assertDontSee(route('events.show', $ownedEvent), false)
        ->assertSee(__('Institution: :name', ['name' => $eventInstitution->display_name]))
        ->assertSee(__('Speakers: :names', ['names' => $speaker->formatted_name]))
        ->assertSee(__('References: :names', ['names' => $reference->title]))
        ->assertSee(__('Report Submission'))
        ->assertSee($reportInstitution->name)
        ->assertDontSee(__('Pending Approvals'))
        ->assertDontSee(__('Approve'))
        ->assertDontSee(__('Reject'));
});

it('restores the active contributions section from the query string', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::withQueryParams(['section' => 'reports'])
        ->test(ContributionsIndex::class)
        ->assertSet('activeTab', 'reports');

    $this->get(route('contributions.index', ['section' => 'reports']))
        ->assertOk()
        ->assertSee('data-active-tab="reports"', false);

    $this->get(route('contributions.index', ['section' => 'unknown']))
        ->assertOk()
        ->assertSee('data-active-tab="events"', false);
});

it('formats institution membership claim options with the location hierarchy', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'name' => 'Masjid Payung',
        'nickname' => null,
        'status' => 'verified',
    ]);
    $country = ensureTestMalaysiaCountry();
    $state = createTestAddressArea('Selangor', 1, null, $country);
    $district = createTestAddressArea('Petaling', 2, $state, $country);
    $subdistrict = createTestAddressArea('Shah Alam', 3, $district, $country);

    syncPrimaryAddressForTest($institution, [
        'country_id' => (string) $country->getKey(),
        'admin_area_1_id' => (string) $state->getKey(),
        'admin_area_2_id' => (string) $district->getKey(),
        'admin_area_3_id' => (string) $subdistrict->getKey(),
    ]);

    $component = Livewire::actingAs($user)->test(ContributionsIndex::class);

    $searchOptions = Closure::bind(fn () => $this->membershipClaimSearchOptions(MemberSubjectType::Institution->value, 'Payung'), $component->instance(), ContributionsIndex::class)();

    $selectedLabel = Closure::bind(fn () => $this->membershipClaimOptionLabel(MemberSubjectType::Institution->value, $institution->slug), $component->instance(), ContributionsIndex::class)();

    expect($searchOptions)->toHaveKey($institution->slug)
        ->and($searchOptions[$institution->slug])->toBe('Masjid Payung - Shah Alam, Petaling, Selangor')
        ->and($selectedLabel)->toBe('Masjid Payung - Shah Alam, Petaling, Selangor');
});

it('paginates contribution status sections when the lists grow', function () {
    $user = User::factory()->create();
    $requestInstitution = Institution::factory()->create([
        'name' => 'Masjid Al-Huda',
        'status' => 'verified',
    ]);
    $eventInstitution = Institution::factory()->create([
        'name' => 'Masjid Al-Ihsan',
        'status' => 'verified',
    ]);
    $event = Event::factory()->for($eventInstitution)->create([
        'title' => 'Majlis Ilmu Paginate',
        'status' => 'pending',
        'visibility' => 'public',
    ]);
    $reportInstitution = Institution::factory()->create([
        'name' => 'Masjid Lapor Paginate',
        'status' => 'verified',
    ]);

    ContributionRequest::factory()->count(6)->create([
        'type' => ContributionRequestType::Create,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => null,
        'entity_id' => null,
        'proposer_id' => $user->id,
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => [
            'name' => 'Masjid Al-Huda Baharu',
        ],
    ]);

    ContributionRequest::factory()->count(6)->create([
        'type' => ContributionRequestType::Update,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => $requestInstitution->getMorphClass(),
        'entity_id' => $requestInstitution->id,
        'proposer_id' => $user->id,
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => [
            'name' => 'Masjid Al-Huda Baharu',
        ],
        'original_data' => [
            'name' => 'Masjid Al-Huda',
        ],
    ]);

    EventSubmission::factory()->count(6)->for($event)->for($user, 'submitter')->create();

    Report::factory()->count(6)->create([
        'reporter_id' => $user->id,
        'entity_type' => $reportInstitution->getMorphClass(),
        'entity_id' => $reportInstitution->id,
        'status' => 'open',
        'category' => 'wrong_info',
    ]);

    $this->actingAs($user);

    $component = Livewire::test(ContributionsIndex::class)->instance();

    expect($component->submittedEvents->hasPages())->toBeTrue()
        ->and($component->myRequests->hasPages())->toBeTrue()
        ->and($component->myUpdateRequests->hasPages())->toBeTrue()
        ->and($component->myReports->hasPages())->toBeTrue();
});

it('lets proposers cancel their own pending requests from the contributions inbox', function () {
    $proposer = User::factory()->create();
    $request = ContributionRequest::factory()->create([
        'proposer_id' => $proposer->id,
        'status' => ContributionRequestStatus::Pending,
    ]);

    $this->actingAs($proposer);

    Livewire::test(ContributionsIndex::class)
        ->call('cancel', $request->id);

    expect($request->fresh()->status)->toBe(ContributionRequestStatus::Cancelled)
        ->and($request->fresh()->cancelled_at)->not->toBeNull();
});

it('stores reference reports from the public report page', function () {
    $user = User::factory()->create();
    $reference = Reference::factory()->create([
        'status' => 'verified',
    ]);
    $referenceRouteSegment = ContributionSubjectType::Reference->publicRouteSegment();

    $this->actingAs($user);

    Livewire::test(CreateReportPage::class, [
        'subjectType' => $referenceRouteSegment,
        'subjectId' => $reference->slug,
    ])
        ->set('data.category', 'fake_reference')
        ->set('data.description', 'This listing points to a fabricated title.')
        ->call('submit')
        ->assertRedirect(route('references.show', $reference));

    $this->assertDatabaseHas('reports', [
        'entity_type' => 'reference',
        'entity_id' => $reference->id,
        'report_type' => 'fake_reference',
    ]);

    expect(SignalEvent::query()->where('event_name', 'report.submitted')->exists())->toBeTrue();
});

it('redirects guests to login on canonical report and suggest update pages while rejecting removed legacy aliases', function () {
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
    ]);
    $reference = Reference::factory()->create([
        'status' => 'verified',
    ]);
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
        'starts_at' => now()->addDay(),
    ]);

    $eventRouteSegment = ContributionSubjectType::Event->publicRouteSegment();
    $institutionRouteSegment = ContributionSubjectType::Institution->publicRouteSegment();
    $speakerRouteSegment = ContributionSubjectType::Speaker->publicRouteSegment();
    $referenceRouteSegment = ContributionSubjectType::Reference->publicRouteSegment();

    $this->get(route('contributions.suggest-update', ['subjectType' => $institutionRouteSegment, 'subjectId' => $institution->slug]))
        ->assertRedirect(route('login'));

    $this->get(route('reports.create', ['subjectType' => $institutionRouteSegment, 'subjectId' => $institution->slug]))
        ->assertRedirect(route('login'));

    $this->get(route('contributions.suggest-update', ['subjectType' => $speakerRouteSegment, 'subjectId' => $speaker->slug]))
        ->assertRedirect(route('login'));

    $this->get(route('reports.create', ['subjectType' => $speakerRouteSegment, 'subjectId' => $speaker->slug]))
        ->assertRedirect(route('login'));

    $this->get("/sumbangan/speaker/{$speaker->slug}/kemas-kini")
        ->assertNotFound();

    $this->get("/lapor/speaker/{$speaker->slug}")
        ->assertNotFound();

    $this->get("/sumbangan/institution/{$institution->slug}/kemas-kini")
        ->assertNotFound();

    $this->get("/lapor/institution/{$institution->slug}")
        ->assertNotFound();

    $this->get("/sumbangan/event/{$event->slug}/kemas-kini")
        ->assertNotFound();

    $this->get("/lapor/event/{$event->slug}")
        ->assertNotFound();

    $this->get("/sumbangan/reference/{$reference->slug}/kemas-kini")
        ->assertNotFound();

    $this->get("/lapor/reference/{$reference->slug}")
        ->assertNotFound();
});

it('forbids users banned from directory feedback from opening update and report pages', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('feedback.blocked');
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
    ]);

    $this->actingAs($user);

    $this->get(route('contributions.suggest-update', ['subjectType' => ContributionSubjectType::Speaker->publicRouteSegment(), 'subjectId' => $speaker->slug]))
        ->assertForbidden();

    $this->get(route('reports.create', ['subjectType' => ContributionSubjectType::Speaker->publicRouteSegment(), 'subjectId' => $speaker->slug]))
        ->assertForbidden();
});

it('resolves speaker slugs on the update suggestion page without uuid casting errors', function () {
    $user = User::factory()->create();
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
    ]);

    $this->actingAs($user);

    Livewire::test(SuggestUpdate::class, [
        'subjectType' => ContributionSubjectType::Speaker->publicRouteSegment(),
        'subjectId' => $speaker->slug,
    ])->assertSet('subjectType', 'speaker');
});

it('keeps speaker update suggestions on a region-only address form', function () {
    $user = User::factory()->create();
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
    ]);

    $this->actingAs($user);

    $this->get(route('contributions.suggest-update', [
        'subjectType' => ContributionSubjectType::Speaker->publicRouteSegment(),
        'subjectId' => $speaker->slug,
    ]))
        ->assertOk()
        ->assertSee(__('Address'))
        ->assertDontSee(__('Address Line 1'))
        ->assertDontSee(__('Address Line 2'))
        ->assertDontSee(__('Postcode'))
        ->assertDontSee(__('Google Maps URL'))
        ->assertDontSee(__('Waze URL'));
});

it('does not treat unchanged speaker update forms as changes when legacy address fields exist', function () {
    $owner = User::factory()->create();
    $country = ensureTestMalaysiaCountry();
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
    ]);

    withGlobalOwnerContext(function () use ($speaker): void {
        $speaker->contacts()->delete();
        $speaker->contacts()->create([
            'type' => ContactMethodType::Email->value,
            'purpose' => ContactPurpose::General->value,
            'value' => 'speaker@example.test',
            'is_public' => true,
        ]);
        $speaker->contacts()->create([
            'type' => ContactMethodType::Phone->value,
            'purpose' => ContactPurpose::General->value,
            'value' => '+1-878-669-9223',
            'is_public' => true,
        ]);
    });

    syncPrimaryAddressForTest($speaker, [
        'country_id' => (string) $country->getKey(),
        'line1' => 'Alamat Warisan',
        'google_maps_url' => 'https://maps.google.com/?q=3.1390,101.6869',
    ]);

    assignSpeakerOwner($owner, $speaker);

    Livewire::actingAs($owner)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Speaker->publicRouteSegment(),
            'subjectId' => $speaker->slug,
        ])
        ->call('submit')
        ->assertHasErrors(['data']);

    expect($speaker->fresh('address')?->addressModel?->line1)->toBe('Alamat Warisan')
        ->and($speaker->fresh('address')?->addressModel?->google_maps_url)->toBe('https://maps.google.com/?q=3.1390,101.6869');
});

it('resolves institution slugs on the report page without uuid casting errors', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);

    $this->actingAs($user);

    Livewire::test(CreateReportPage::class, [
        'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
        'subjectId' => $institution->slug,
    ])->assertSet('subjectType', 'institution');
});

it('shows the reported institution clearly on the public report page', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'name' => 'Kompleks Islam Senawang',
        'status' => 'verified',
    ]);
    $selectedInstitutionLabel = __('Selected :subject', ['subject' => strtolower(__('Institution'))]);
    $viewInstitutionLabel = __('View this :subject', ['subject' => strtolower(__('Institution'))]);

    $this->actingAs($user);

    $this->get(route('reports.create', [
        'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
        'subjectId' => $institution->slug,
    ]))
        ->assertOk()
        ->assertSeeText($selectedInstitutionLabel)
        ->assertSeeText($institution->name)
        ->assertSeeText($viewInstitutionLabel);
});

it('shows the reported speaker clearly on the public report page', function () {
    $user = User::factory()->create();
    $speaker = Speaker::factory()->create([
        'name' => 'Amina binti Rashid',
        'status' => 'verified',
    ]);
    $selectedSpeakerLabel = __('Selected :subject', ['subject' => strtolower(__('Speaker'))]);
    $viewSpeakerLabel = __('View this :subject', ['subject' => strtolower(__('Speaker'))]);

    $this->actingAs($user);

    $this->get(route('reports.create', [
        'subjectType' => ContributionSubjectType::Speaker->publicRouteSegment(),
        'subjectId' => $speaker->slug,
    ]))
        ->assertOk()
        ->assertSeeText($selectedSpeakerLabel)
        ->assertSeeText($speaker->formatted_name)
        ->assertSeeText($viewSpeakerLabel);
});

it('shows the reported event clearly on the public report page', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create([
        'title' => 'Kelas Daurah Tafsir Ibnu Kathir',
        'status' => 'approved',
    ]);
    $selectedEventLabel = __('Selected :subject', ['subject' => strtolower(__('Event'))]);
    $viewEventLabel = __('View this :subject', ['subject' => strtolower(__('Event'))]);

    $this->actingAs($user);

    $this->get(route('reports.create', [
        'subjectType' => ContributionSubjectType::Event->publicRouteSegment(),
        'subjectId' => $event->slug,
    ]))
        ->assertOk()
        ->assertSeeText($selectedEventLabel)
        ->assertSeeText($event->title)
        ->assertSeeText($viewEventLabel);
});

it('renders translated report copy on mobile without the moderation notes block', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'name' => 'Kompleks Islam Senawang',
        'status' => 'verified',
    ]);

    app()->setLocale('ms');
    $this->actingAs($user);

    $this->get(route('reports.create', [
        'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
        'subjectId' => $institution->slug,
    ]))
        ->assertOk()
        ->assertSeeText('Keselamatan & Kepercayaan')
        ->assertSeeText('Lapor Rekod')
        ->assertSeeText('Rekod dipilih: institusi')
        ->assertSeeText('Laporkan institusi ini')
        ->assertSeeText('Jenis Isu')
        ->assertSeeText('Tambah konteks jika isu tidak jelas.')
        ->assertSeeText('Lihat institusi ini')
        ->assertSeeText('Hantar Laporan')
        ->assertDontSee('Moderation notes')
        ->assertDontSee('Reports are reviewed, not auto-hidden')
        ->assertDontSee('Duplicate reports are limited')
        ->assertDontSee('lg:grid-cols-[1.1fr_0.9fr]');
});

it('redirects uuid-based reference contribution and report pages to the canonical slug url', function () {
    $user = User::factory()->create();
    $reference = Reference::factory()->create([
        'status' => 'verified',
    ]);

    $this->actingAs($user);

    $this->get("/sumbangan/rujukan/{$reference->id}/kemas-kini")
        ->assertRedirect(route('contributions.suggest-update', [
            'subjectType' => ContributionSubjectType::Reference->publicRouteSegment(),
            'subjectId' => $reference->slug,
        ]));

    $this->get("/lapor/rujukan/{$reference->id}")
        ->assertRedirect(route('reports.create', [
            'subjectType' => ContributionSubjectType::Reference->publicRouteSegment(),
            'subjectId' => $reference->slug,
        ]));
});
