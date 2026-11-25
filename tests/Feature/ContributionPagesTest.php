<?php

use AIArmada\Addressing\Models\Address;
use AIArmada\Contacting\Enums\ContactMethodType;
use AIArmada\Contacting\Enums\ContactPurpose;
use AIArmada\Events\Features\Catalog\Models\EventOccurrence;
use AIArmada\Membership\Enums\MemberRole;
use AIArmada\Persons\Enums\AssignmentStatus;
use AIArmada\Persons\Enums\PersonNameType;
use AIArmada\Persons\Models\Title;
use AIArmada\Signals\Models\SignalEvent;
use App\Features\Contributions\Actions\ApproveContributionRequestAction;
use App\Features\Contributions\Actions\RejectContributionRequestAction;
use App\Features\Contributions\Api\Enums\ContributionSubjectType;
use App\Features\Contributions\EntryPoints\Livewire\Pages\Index as ContributionsIndex;
use App\Features\Contributions\EntryPoints\Livewire\Pages\SubmitInstitution;
use App\Features\Contributions\EntryPoints\Livewire\Pages\SubmitPerson;
use App\Features\Contributions\EntryPoints\Livewire\Pages\SubmitReference;
use App\Features\Contributions\EntryPoints\Livewire\Pages\SuggestUpdate;
use App\Features\Contributions\Enums\ContributionRequestStatus;
use App\Features\Contributions\Enums\ContributionRequestType;
use App\Features\Contributions\Models\ContributionRequest;
use App\Features\Contributions\Services\ContributionEntityStateService;
use App\Features\Events\Api\Enums\EventFormat;
use App\Features\Events\Api\Enums\TimingMode;
use App\Features\Events\Models\Event;
use App\Features\Events\Models\EventSubmission;
use App\Features\Institutions\Models\Institution;
use App\Features\Persons\Models\Affiliation;
use App\Features\Persons\Models\Person;
use App\Features\Places\Models\Venue;
use App\Features\Prayer\Api\EventPrayerTime;
use App\Features\References\Models\Reference;
use App\Features\Reports\EntryPoints\Livewire\Pages\Reports\Create as CreateReportPage;
use App\Features\Reports\Models\Report;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\AIArmada\EventRoleSeeder;
use Database\Seeders\PermissionSeeder;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Tabs;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function assignInstitutionOwner(User $user, Institution $institution): void
{
    withGlobalOwnerContext(fn (): null => addTestMember($institution, $user, MemberRole::Owner));
}

function assignPersonOwner(User $user, Person $person): void
{
    withGlobalOwnerContext(fn (): null => addTestMember($person, $user, MemberRole::Owner));
}

function giveContributionPagesInstitutionMapsPlace(Institution $institution, string $placeId = 'place_pages_existing'): void
{
    $address = $institution->primaryAddress();

    if (! $address instanceof Address) {
        return;
    }

    $address->forceFill([
        'google_place_id' => $placeId,
        'google_maps_url' => 'https://www.google.com/maps/search/?api=1&query_place_id='.$placeId,
    ])->save();
}

it('renders the dedicated institution contribution page', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $this->get(route('contributions.submit-institution'))
        ->assertOk()
        ->assertSee(__('Add a New Institution'))
        ->assertSee(__('Address'))
        ->assertSee(__('Donation Channels'))
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
        ->assertSee(__('Find the institution on Google Maps'))
        ->assertDontSee(__('Search like a ride-hailing destination, pick the correct place, then confirm it on the map before submitting.'))
        ->assertSee('rekod baru')
        ->assertDontSee('rekod pendua')
        ->assertSee('Semak Institusi Sedia Ada')
        ->assertDontSee('Apa yang berlaku seterusnya?')
        ->assertDontSee('Lihat Sumbangan Saya');
});

it('renders the dedicated reference contribution page', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $this->get(route('contributions.submit-reference'))
        ->assertOk()
        ->assertSee(__('Add a New Reference'))
        ->assertSee(__('Check the existing references directory first'))
        ->assertSee(__('Check Existing References'))
        ->assertSee(__('Submit Reference'));

    $this->get(route('contributions.submission-success', ['subjectType' => 'rujukan']))
        ->assertOk()
        ->assertSee(__('Thank you for suggesting a reference.'));
});

it('stores a new reference submission from the dedicated reference page', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(SubmitReference::class)
        ->fillForm([
            'title' => 'Kitab Ujian Rujukan',
            'type' => 'book',
            'record_kind' => 'work',
            'publication_year' => 2024,
            'publisher' => 'Penerbit Ujian',
            'description' => 'Rujukan ujian untuk aliran sumbangan rujukan.',
        ])
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('contributions.submission-success', [
            'subjectType' => ContributionSubjectType::Reference->publicRouteSegment(),
        ]));

    $reference = Reference::query()
        ->where('title', 'Kitab Ujian Rujukan')
        ->firstOrFail();

    $request = ContributionRequest::query()
        ->where('subject_type', ContributionSubjectType::Reference->value)
        ->where('entity_id', $reference->id)
        ->firstOrFail();

    expect($reference->status)->toBe('pending')
        ->and($reference->year)->toBe(2024)
        ->and($reference->publisher)->toBe('Penerbit Ujian')
        ->and($request->type)->toBe(ContributionRequestType::Create)
        ->and($request->status)->toBe(ContributionRequestStatus::Pending)
        ->and($request->proposer_id)->toBe($user->id);
});

it('approves a pending reference creation request', function () {
    $proposer = User::factory()->create();
    $reviewer = User::factory()->create();
    $reference = Reference::factory()->pending()->create([
        'title' => 'Rujukan Menunggu Kelulusan',
    ]);
    $request = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Create,
        'subject_type' => ContributionSubjectType::Reference,
        'entity_type' => $reference->getMorphClass(),
        'entity_id' => $reference->id,
        'proposer_id' => $proposer->id,
        'status' => ContributionRequestStatus::Pending,
    ]);

    app(ApproveContributionRequestAction::class)->handle($request, $reviewer);

    expect($reference->fresh()->status)->toBe('verified')
        ->and($request->fresh()->status)->toBe(ContributionRequestStatus::Approved);
});

it('rejects a pending reference creation request', function () {
    $proposer = User::factory()->create();
    $reviewer = User::factory()->create();
    $reference = Reference::factory()->pending()->create([
        'title' => 'Rujukan Ditolak',
    ]);
    $request = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Create,
        'subject_type' => ContributionSubjectType::Reference,
        'entity_type' => $reference->getMorphClass(),
        'entity_id' => $reference->id,
        'proposer_id' => $proposer->id,
        'status' => ContributionRequestStatus::Pending,
    ]);

    app(RejectContributionRequestAction::class)->handle($request, $reviewer, 'incorrect_information');

    expect($reference->fresh()->status)->toBe('rejected')
        ->and($request->fresh()->status)->toBe(ContributionRequestStatus::Rejected);
});

it('renders the dedicated person contribution page', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $this->get(route('contributions.submit-person'))
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

it('tracks submission progress as speaker fields are completed', function () {
    $component = Livewire::actingAs(User::factory()->create())
        ->test(SubmitPerson::class);

    expect($component->instance()->formProgress())->toBe(33);

    $component
        ->set('data.name', 'Ahmad Ali')
        ->set('data.family_name', 'Ali')
        ->set('data.gender', 'male')
        ->set('data.title_ids', ['00000000-0000-0000-0000-0000000000a1'])
        ->set('data.institutions', [['id' => null, 'institution_id' => '00000000-0000-0000-0000-0000000000b1', 'position' => 'Imam', 'is_primary' => true]])
        ->set('data.address.state_id', '00000000-0000-0000-0000-0000000000c1')
        ->set('data.address.city_id', '00000000-0000-0000-0000-0000000000d1')
        ->set('data.address.area_assignments', [
            'administrative_district' => '00000000-0000-0000-0000-0000000000e1',
            'administrative_subdivision' => null,
            'postal_locality' => null,
        ]);

    expect($component->instance()->formProgress())->toBe(100);
});

it('renders the person contribution page with translated copy when the locale changes', function () {
    $user = User::factory()->create();

    app()->setLocale('ms');
    $this->actingAs($user);

    $this->get(route('contributions.submit-person'))
        ->assertOk()
        ->assertSee('Sumbangan Komuniti')
        ->assertSee('Tambah Penceramah Baru')
        ->assertSee('Hantar rekod penceramah baru untuk direktori ilmu360°.')
        ->assertDontSee('Tambah Penceramah Baharu')
        ->assertDontSee('Penyemak akan menilainya sebelum diterbitkan.')
        ->assertSee('Maklumat Utama')
        ->assertSee('Hubungi')
        ->assertSee('Semak direktori sedia ada dahulu')
        ->assertSee('Semak Penceramah Sedia Ada')
        ->assertSee('rekod baru')
        ->assertDontSee('Lihat Sumbangan Saya')
        ->assertDontSee('Add a New Person')
        ->assertDontSee('Education')
        ->assertDontSee('Contact Details');
});

it('uses Malay labels and contextual guidance throughout the speaker form', function () {
    app()->setLocale('ms');

    $component = Livewire::actingAs(User::factory()->create())
        ->test(SubmitPerson::class)
        ->assertFormFieldExists('name', function (Field $field): bool {
            expect($field->getLabel())->toBe('Nama Penceramah');

            return true;
        })
        ->assertFormFieldExists('family_name', function (Field $field): bool {
            expect($field->getLabel())->toBe('Nama Bapa / Keluarga');

            return true;
        })
        ->assertFormFieldExists('middle_name', function (Field $field): bool {
            expect($field->getLabel())->toBe('Nama tengah');

            return true;
        })
        ->assertFormFieldExists('title_ids', function (Field $field): bool {
            expect($field->getLabel())->toBe('Gelaran');

            return true;
        })
        ->assertFormFieldExists('names', function (Repeater $field): bool {
            expect($field->getLabel())->toBe('Nama alternatif')
                ->and($field->getAddActionLabel())->toBe('Tambah nama');

            return true;
        })
        ->assertFormFieldExists('institutions', function (Repeater $field): bool {
            expect($field->getLabel())->toBe('Institusi')
                ->and($field->getAddActionLabel())->toBe('Tambah institusi');

            return true;
        })
        ->assertFormFieldExists('address.country_id', function (Field $field): bool {
            expect($field->getLabel())->toBe('Negara');

            return true;
        })
        ->assertFormFieldExists('contactMethods', function (Repeater $field): bool {
            expect($field->getLabel())->toBe('Maklumat Perhubungan')
                ->and($field->getAddActionLabel())->toBe('Tambah maklumat perhubungan');

            return true;
        })
        ->assertFormFieldExists('social_media', function (Repeater $field): bool {
            expect($field->getLabel())->toBe('Media Sosial')
                ->and($field->getAddActionLabel())->toBe('Tambah Media Sosial');

            return true;
        })
        ->assertDontSee('Nama keluarga yang digunakan untuk susunan abjad.');
});

it('uses the corrected Malay labels and guidance on speaker and institution update forms', function () {
    $user = User::factory()->create();
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);

    assignPersonOwner($user, $person);

    app()->setLocale('ms');
    $this->actingAs($user);

    $this->get(route('contributions.suggest-update', [
        'subjectType' => ContributionSubjectType::Person->publicRouteSegment(),
        'subjectId' => $person->slug,
    ]))
        ->assertOk()
        ->assertSee('Nama Penceramah')
        ->assertSee('Gunakan nama sebenar')
        ->assertSee('Nama Bapa / Keluarga')
        ->assertDontSee('Nama keluarga yang digunakan untuk susunan abjad.')
        ->assertSee('Foto avatar')
        ->assertSee('Gunakan foto profil segi empat yang jelas untuk avatar utama, sekurang-kurangnya 400x400 piksel.');

    $this->get(route('contributions.suggest-update', [
        'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
        'subjectId' => $institution->slug,
    ]))
        ->assertOk()
        ->assertSee('Nama Institusi')
        ->assertSee('Gunakan nama rasmi institusi.')
        ->assertSee('Nama alternatif')
        ->assertSee('Tambah nama')
        ->assertSee('Kongsi penerangan ringkas tentang institusi ini.');
});

it('requires a country for a new speaker contribution', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(SubmitPerson::class)
        ->assertFormFieldExists('address.country_id', function (Select $field): bool {
            expect($field->isRequired())->toBeTrue();

            return true;
        })
        ->set('data.name', 'Speaker Country Required')
        ->set('data.gender', 'male')
        ->set('data.address.country_id', null)
        ->call('submit')
        ->assertHasErrors(['data.address.country_id']);
});

it('requires a country when updating a speaker who already has one', function () {
    $user = User::factory()->create();
    $person = Person::factory()->create(['status' => 'verified']);
    $country = ensureTestMalaysiaCountry();

    syncPrimaryAddressForTest($person, ['country_id' => (string) $country->getKey()]);

    Livewire::actingAs($user)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Person->publicRouteSegment(),
            'subjectId' => $person->slug,
        ])
        ->assertSet('data.address.country_id', (string) $country->getKey())
        ->set('data.address.country_id')
        ->call('submit')
        ->assertHasErrors(['data.address.country_id' => 'required']);
});

it('includes the selected country in the speaker progress calculation', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('contributions.submit-person'))
        ->assertOk()
        ->assertSee("'address',", false)
        ->assertSee('this.isFilled(currentAddress.country_id)', false);
});

it('shows person affiliation fields on the dedicated create and update forms', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);

    $this->actingAs($user);

    Livewire::test(SubmitPerson::class)
        ->assertFormFieldVisible('institutions')
        ->set('data.institutions', [[
            'institution_id' => $institution->id,
            'position' => 'Mudir',
            'is_primary' => true,
        ]]);

    Livewire::test(SuggestUpdate::class, [
        'subjectType' => ContributionSubjectType::Person->publicRouteSegment(),
        'subjectId' => $person->slug,
    ])
        ->assertFormFieldVisible('institutions')
        ->set('data.institutions', [[
            'institution_id' => $institution->id,
            'position' => 'Mudir',
            'is_primary' => true,
        ]]);
});

it('stores person affiliations from the dedicated person contribution page', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);

    $this->actingAs($user);

    Livewire::test(SubmitPerson::class)
        ->fillForm([
            'name' => 'Ustaz Pautan Institusi',
            'gender' => 'male',
            'institutions' => [[
                'institution_id' => $institution->id,
                'position' => 'Mudir',
                'is_primary' => true,
            ]],
        ])
        ->call('submit')
        ->assertHasNoErrors();

    $person = Person::query()
        ->with('institutions')
        ->where('name', 'Ustaz Pautan Institusi')
        ->firstOrFail();

    $affiliatedInstitution = $person->institutions->firstWhere('id', $institution->id);

    expect($person->status)->toBe('pending')
        ->and($affiliatedInstitution)->not->toBeNull()
        ->and($affiliatedInstitution?->pivot?->position)->toBe('Mudir')
        ->and($affiliatedInstitution?->pivot?->is_primary)->toBeTruthy();
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

it('renders the person contribution submission success page', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $this->withSession(['contribution_submission_name' => 'Ustaz Cadangan Baru'])
        ->get(route('contributions.submission-success', ['subjectType' => ContributionSubjectType::Person->publicRouteSegment()]))
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

it('renders the person contribution submission success page with translated copy when the locale changes', function () {
    $user = User::factory()->create();

    app()->setLocale('ms');
    $this->actingAs($user);

    $this->get(route('contributions.submission-success', ['subjectType' => ContributionSubjectType::Person->publicRouteSegment()]))
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

it('uses organized person form tabs on the public person update page', function () {
    $user = User::factory()->create();
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);

    $this->actingAs($user);

    $this->get(route('contributions.suggest-update', [
        'subjectType' => ContributionSubjectType::Person->publicRouteSegment(),
        'subjectId' => $person->slug,
    ]))
        ->assertOk()
        ->assertSee(__('Maklumat Utama'))
        ->assertSee(__('Maklumat Tambahan'))
        ->assertSee(__('Afiliasi'))
        ->assertSee(__('Media'))
        ->assertSee(__('Lokasi'))
        ->assertSee(__('Hubungan'))
        ->assertDontSee(__('Pendidikan'));
});

it('keeps person update tab content aligned with its labels', function () {
    $user = User::factory()->create();
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);

    $component = Livewire::actingAs($user)->test(SuggestUpdate::class, [
        'subjectType' => ContributionSubjectType::Person->publicRouteSegment(),
        'subjectId' => $person->slug,
    ]);

    $tabs = $component->instance()->getForm('form')->getComponents()[0];

    expect($tabs)->toBeInstanceOf(Tabs::class);

    $tabsByLabel = collect($tabs->getChildComponents())
        ->keyBy(fn (object $tab): string => (string) $tab->getLabel());

    $sectionLabels = static fn (object $tab): array => collect($tab->getChildComponents())
        ->map(fn (object $section): string => (string) $section->getHeading())
        ->all();

    expect($sectionLabels($tabsByLabel->get(__('Afiliasi'))))
        ->toContain(__('Institusi Berafiliasi'))
        ->and($sectionLabels($tabsByLabel->get(__('Lokasi'))))
        ->toContain(__('Address'));
});

it('uses the update form tab structure for new person contributions', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test(SubmitPerson::class);

    $tabs = $component->instance()->getForm('form')->getComponents()[0];

    expect($tabs)->toBeInstanceOf(Tabs::class);

    $tabsByLabel = collect($tabs->getChildComponents())
        ->keyBy(fn (object $tab): string => (string) $tab->getLabel());

    $sectionLabels = static fn (object $tab): array => collect($tab->getChildComponents())
        ->map(fn (object $section): string => (string) $section->getHeading())
        ->all();

    expect($sectionLabels($tabsByLabel->get(__('Maklumat Utama'))))
        ->toContain(__('Maklumat Utama'))
        ->and($sectionLabels($tabsByLabel->get(__('Maklumat Tambahan'))))
        ->toContain(__('Maklumat Tambahan'))
        ->and($sectionLabels($tabsByLabel->get(__('Afiliasi'))))
        ->toContain(__('Institusi Berafiliasi'))
        ->and($sectionLabels($tabsByLabel->get(__('Lokasi'))))
        ->toContain(__('Address'))
        ->and($sectionLabels($tabsByLabel->get(__('Hubungan'))))
        ->toContain(__('Contact'))
        ->toContain(__('Social Media'))
        ->and($sectionLabels($tabsByLabel->get(__('Media'))))
        ->toContain(__('Profile Photo & Media'));
});

it('lets administrators send an institution edit with a reviewer note', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);

    $this->actingAs($user);

    $this->get(route('contributions.suggest-update', [
        'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
        'subjectId' => $institution->slug,
    ]))
        ->assertOk()
        ->assertSee('Kemas Kini')
        ->assertDontSee('Cadangan Kemas Kini')
        ->assertSee(__('Explain the change'));

    giveContributionPagesInstitutionMapsPlace($institution);

    Livewire::test(SuggestUpdate::class, [
        'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
        'subjectId' => $institution->slug,
    ])
        ->set('data.name', 'Nama Dicadangkan')
        ->set('data.proposer_note', 'Please verify the new name.')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('contributions.index'));

    expect($institution->fresh()->name)->not->toBe('Nama Dicadangkan')
        ->and(ContributionRequest::query()->sole()->proposer_note)->toBe('Please verify the new name.');
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
        'event_category_ids' => [eventCategoryId('komuniti_kebajikan')],
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
        'event_category_ids' => [eventCategoryId('komuniti_kebajikan')],
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
        ->assertSeeInOrder([
            __('Majlis & Topik'),
            __('Tarikh, Masa & Kehadiran'),
            __('Format, Penganjur & Lokasi'),
            __('Penceramah & Media'),
        ])
        ->assertSee('Terangkan perubahan')
        ->assertSee(__('Kehadiran'))
        ->assertSee(__('Format & Pautan'))
        ->assertSee(__('Penganjur'))
        ->assertSee(__('Lokasi'))
        ->assertSee(__('Penceramah'))
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
    $user->assignRole('admin');
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);

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
        ->assertSee('Terangkan perubahan')
        ->assertDontSee('Laksanakan Kemas Kini')
        ->assertDontSee(__('View My Contributions'));
});

it('renders the person suggest update page with the compact shared shell', function () {
    $user = User::factory()->create();
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);

    app()->setLocale('ms');
    $this->actingAs($user);

    $this->get(route('contributions.suggest-update', [
        'subjectType' => ContributionSubjectType::Person->publicRouteSegment(),
        'subjectId' => $person->slug,
    ]))
        ->assertOk()
        ->assertSee('Cadangan Kemas Kini')
        ->assertSee('bg-white pb-0', false)
        ->assertSee('max-w-5xl flex-col gap-2 px-4 py-2', false)
        ->assertDontSee('Cadangan Komuniti')
        ->assertDontSee('Cadangkan Kemas Kini');
});

it('shows the institution media uploads on the suggest update page for visitors and maintainers', function () {
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
        ->assertSee(__('Logo'))
        ->assertSee(__('Cover Image'))
        ->assertSee(__('Gallery'));

    assignInstitutionOwner($owner, $institution);
    $this->actingAs($owner);

    $this->get(route('contributions.suggest-update', [
        'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
        'subjectId' => $institution->slug,
    ]))
        ->assertOk()
        ->assertDontSee(__('View My Contributions'))
        ->assertSee(__('Logo'))
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
        ->assertDontSee(__('Find the institution on Google Maps'))
        ->assertSee(__('Update the location on Google Maps'))
        ->assertSee(__('Only search again if the institution moved or the pinned location is wrong. Leave this untouched to keep the current address.'))
        ->assertSee(__('Search for an institution or address'));
});

it('exposes person media upload fields to public contributors for moderated submission', function () {
    $visitor = User::factory()->create();
    $person = Person::factory()->create([
        'status' => 'verified',
        'bio' => null,
    ]);

    $this->actingAs($visitor);

    $this->get(route('contributions.suggest-update', [
        'subjectType' => ContributionSubjectType::Person->publicRouteSegment(),
        'subjectId' => $person->slug,
    ]))
        ->assertOk()
        ->assertDontSee(__('View My Contributions'))
        ->assertSee(__('Profile avatar'))
        ->assertSee(__('Square Photo'))
        ->assertSee(__('Photo gallery'));
});

it('stages visitor person media on a pending contribution request and applies it on approval', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $reviewer = User::factory()->create();
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);

    // The upload endpoint attaches the visitor's file to the entity, then submit
    // stages it for moderation (mirrors SuggestUpdate::stageDirectEditMediaChanges).
    $person->addMedia(fakeGeneratedImageUpload('visitor-square.jpg'))
        ->toMediaCollection('square');
    $square = $person->getFirstMedia('square');

    $request = ContributionRequest::factory()->create([
        'status' => ContributionRequestStatus::Pending,
        'subject_type' => ContributionSubjectType::Person,
        'entity_type' => $person->getMorphClass(),
        'entity_id' => $person->getKey(),
        'proposed_data' => ['biography' => 'Updated biography'],
    ]);

    $request->addMediaFromDisk($square->getPathRelativeToRoot(), $square->disk)
        ->usingFileName($square->file_name)
        ->withCustomProperties([
            'contribution_field' => 'square',
            'is_single' => true,
        ])
        ->toMediaCollection('pending_media');
    $square->delete();

    expect($request->fresh()->getMedia('pending_media'))->toHaveCount(1)
        ->and($person->fresh()->getFirstMedia('square'))->toBeNull();

    app(ApproveContributionRequestAction::class)->handle($request->fresh(), $reviewer);

    expect($person->fresh()->getFirstMedia('square'))
        ->not->toBeNull()
        ->and($person->fresh()->getFirstMedia('square')->file_name)->toBe('visitor-square.jpg')
        ->and($request->fresh()->getMedia('pending_media'))->toBeEmpty();
});

it('preserves the original person avatar when a visitor replaces it and the request is rejected', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $visitor = User::factory()->create();
    $person = Person::factory()->create(['status' => 'verified']);

    $original = $person->addMedia(fakeGeneratedImageUpload('original-avatar.jpg'))
        ->toMediaCollection('avatar');
    $originalId = $original->getKey();

    Livewire::actingAs($visitor)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Person->publicRouteSegment(),
            'subjectId' => $person->slug,
        ])
        ->set('data.avatar', [TemporaryUploadedFile::fake()->image('replacement-avatar.jpg', 300, 300)])
        ->set('data.proposer_note', 'Sila tukar gambar')
        ->call('submit')
        ->assertHasNoFormErrors()
        ->assertRedirect(route('contributions.index'));

    $request = ContributionRequest::query()->where('entity_id', $person->getKey())->latest()->first();

    expect($request)->not->toBeNull()
        ->and($request->status)->toBe(ContributionRequestStatus::Pending)
        ->and($request->getMedia('pending_media'))->toHaveCount(1)
        // Original avatar must remain on the entity: a reject must not lose it.
        ->and($person->fresh()->getFirstMedia('avatar')->getKey())->toBe($originalId);

    $request->update(['status' => ContributionRequestStatus::Rejected]);

    expect($person->fresh()->getFirstMedia('avatar')->getKey())->toBe($originalId)
        ->and($person->fresh()->getMedia('avatar'))->toHaveCount(1);
});

it('replaces the original person avatar when a visitor replacement is approved', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $reviewer = User::factory()->create();
    $visitor = User::factory()->create();
    $person = Person::factory()->create(['status' => 'verified']);

    $original = $person->addMedia(fakeGeneratedImageUpload('original-avatar.jpg'))
        ->toMediaCollection('avatar');
    $originalUuid = $original->uuid;

    Livewire::actingAs($visitor)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Person->publicRouteSegment(),
            'subjectId' => $person->slug,
        ])
        ->set('data.avatar', [TemporaryUploadedFile::fake()->image('replacement-avatar.jpg', 300, 300)])
        ->set('data.proposer_note', 'Sila tukar gambar')
        ->call('submit')
        ->assertHasNoFormErrors()
        ->assertRedirect(route('contributions.index'));

    $request = ContributionRequest::query()->where('entity_id', $person->getKey())->latest()->first();

    app(ApproveContributionRequestAction::class)->handle($request->fresh(), $reviewer);

    expect($person->fresh()->getMedia('avatar'))->toHaveCount(1)
        ->and($person->fresh()->getFirstMedia('avatar')->uuid)->not->toBe($originalUuid)
        ->and(Media::where('uuid', $originalUuid)->exists())->toBeFalse()
        ->and($request->fresh()->getMedia('pending_media'))->toBeEmpty();
});

it('preserves the original reference front cover when a visitor replaces it and the request is rejected', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $visitor = User::factory()->create();
    $reference = Reference::factory()->create(['status' => 'verified']);

    $original = $reference->addMedia(fakeGeneratedImageUpload('original-front.jpg'))
        ->toMediaCollection('front_cover');
    $originalId = $original->getKey();

    Livewire::actingAs($visitor)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Reference->publicRouteSegment(),
            'subjectId' => $reference->slug,
        ])
        ->set('data.front_cover', [TemporaryUploadedFile::fake()->image('replacement-front.jpg', 300, 400)])
        ->set('data.proposer_note', 'Sila tukar cover')
        ->call('submit')
        ->assertHasNoFormErrors()
        ->assertRedirect(route('contributions.index'));

    $request = ContributionRequest::query()->where('entity_id', $reference->getKey())->latest()->first();

    expect($request)->not->toBeNull()
        ->and($request->status)->toBe(ContributionRequestStatus::Pending)
        ->and($request->getMedia('pending_media'))->toHaveCount(1)
        ->and($reference->fresh()->getFirstMedia('front_cover')->getKey())->toBe($originalId);

    $request->update(['status' => ContributionRequestStatus::Rejected]);

    expect($reference->fresh()->getFirstMedia('front_cover')->getKey())->toBe($originalId)
        ->and($reference->fresh()->getMedia('front_cover'))->toHaveCount(1);
});

it('replaces the original reference front cover when a visitor replacement is approved', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $reviewer = User::factory()->create();
    $visitor = User::factory()->create();
    $reference = Reference::factory()->create(['status' => 'verified']);

    $original = $reference->addMedia(fakeGeneratedImageUpload('original-front.jpg'))
        ->toMediaCollection('front_cover');
    $originalUuid = $original->uuid;

    Livewire::actingAs($visitor)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Reference->publicRouteSegment(),
            'subjectId' => $reference->slug,
        ])
        ->set('data.front_cover', [TemporaryUploadedFile::fake()->image('replacement-front.jpg', 300, 400)])
        ->set('data.proposer_note', 'Sila tukar cover')
        ->call('submit')
        ->assertHasNoFormErrors()
        ->assertRedirect(route('contributions.index'));

    $request = ContributionRequest::query()->where('entity_id', $reference->getKey())->latest()->first();

    app(ApproveContributionRequestAction::class)->handle($request->fresh(), $reviewer);

    expect($reference->fresh()->getMedia('front_cover'))->toHaveCount(1)
        ->and($reference->fresh()->getFirstMedia('front_cover')->uuid)->not->toBe($originalUuid)
        ->and(Media::where('uuid', $originalUuid)->exists())->toBeFalse()
        ->and($request->fresh()->getMedia('pending_media'))->toBeEmpty();
});

it('requires the creation form language and topic on event updates', function () {
    $visitor = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $event = Event::factory()->for($institution)->create([
        'title' => 'Majlis Kelonggaran Borang',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subMinute(),
        'event_category_ids' => [eventCategoryId('komuniti_kebajikan')],
        'institution_id' => $institution->id,
        'description' => 'Original description',
        'starts_at' => now()->addDays(3)->setTime(20, 0),
        // Pin absolute timing: a prayer-relative lottery draw can prefill a
        // date-gated option (Jumaat/Tarawih) that is invalid for this date.
        'ends_at' => now()->addDays(3)->setTime(22, 0),
        'timing_mode' => 'absolute',
    ]);
    $event->setPrimaryOrganizer($institution);

    Livewire::actingAs($visitor)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Event->publicRouteSegment(),
            'subjectId' => $event->slug,
        ])
        ->set('data.description', 'Updated event description')
        ->set('data.proposer_note', 'Saya mahu kemaskini penerangan')
        ->call('submit')
        ->assertHasFormErrors(['language_ids' => 'required', 'domain_tags' => 'required']);

    expect(ContributionRequest::query()->where('entity_id', $event->getKey())->count())->toBe(0);
});

it('accepts an untouched multi-day event schedule when a visitor updates other fields', function () {
    $visitor = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $startsAt = now()->addDays(3)->setTime(20, 0);
    $event = Event::factory()->for($institution)->create([
        'title' => 'Majlis Rentas Hari',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subMinute(),
        'event_category_ids' => [eventCategoryId('komuniti_kebajikan')],
        'institution_id' => $institution->id,
        'description' => 'Original description',
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addDays(3)->subMinutes(10),
        'timing_mode' => 'absolute',
    ]);
    $event->setPrimaryOrganizer($institution);

    Livewire::actingAs($visitor)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Event->publicRouteSegment(),
            'subjectId' => $event->slug,
        ])
        ->set('data.description', 'Updated event description')
        ->set('data.proposer_note', 'Saya mahu kemaskini penerangan')
        ->set('data.language_ids', [languageId('ms')])
        ->set('data.domain_tags', [submitEventTerm('domain')->getKey()])
        ->call('submit')
        ->assertHasNoFormErrors()
        ->assertRedirect(route('contributions.index'));

    $request = ContributionRequest::query()->where('entity_id', $event->getKey())->first();

    expect($request)->not->toBeNull()
        ->and($request->proposed_data)->toHaveKey('description')
        ->and($request->proposed_data)->not->toHaveKeys(['starts_at', 'ends_at']);
});

it('keeps a prayer-relative event schedule unchanged when a visitor updates other fields', function () {
    $reviewer = User::factory()->create();
    $visitor = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $startsAt = CarbonImmutable::parse('2027-03-20 20:00:00', 'Asia/Kuala_Lumpur')->utc();
    $event = Event::factory()->for($institution)->kuliahMaghrib()->create([
        'title' => 'Kuliah Maghrib Penerangan',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subMinute(),
        'event_category_ids' => [eventCategoryId('komuniti_kebajikan')],
        'institution_id' => $institution->id,
        'description' => 'Original description',
        'starts_at' => $startsAt,
        // Explicit: factory ends_at is random and can predate the overridden
        // starts_at, which the schedule validation correctly rejects.
        'ends_at' => $startsAt->addHours(2),
    ]);
    $event->setPrimaryOrganizer($institution);

    Livewire::actingAs($visitor)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Event->publicRouteSegment(),
            'subjectId' => $event->slug,
        ])
        ->set('data.description', 'Updated event description')
        ->set('data.proposer_note', 'Saya mahu kemaskini penerangan')
        ->set('data.language_ids', [languageId('ms')])
        ->set('data.domain_tags', [submitEventTerm('domain')->getKey()])
        ->call('submit')
        ->assertHasNoFormErrors()
        ->assertRedirect(route('contributions.index'));

    $request = ContributionRequest::query()->where('entity_id', $event->getKey())->latest()->first();

    expect($request)->not->toBeNull()
        ->and($request->proposed_data)->toHaveKey('description')
        ->and($request->proposed_data)->not->toHaveKeys(['starts_at', 'ends_at', 'timezone']);

    app(ApproveContributionRequestAction::class)->handle($request->fresh(), $reviewer);

    expect($event->fresh()->starts_at?->utc()->toDateTimeString())->toBe($startsAt->toDateTimeString())
        ->and($event->fresh()->timezone)->toBe('Asia/Kuala_Lumpur');
});

it('exposes event media upload fields to public contributors for moderated submission', function () {
    $visitor = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $event = Event::factory()->for($institution)->create([
        'title' => 'Majlis Muat Naik Media',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subMinute(),
        'event_category_ids' => [eventCategoryId('komuniti_kebajikan')],
        'institution_id' => $institution->id,
        'starts_at' => now()->addDays(3)->setTime(20, 0),
    ]);
    $event->setPrimaryOrganizer($institution);

    $this->actingAs($visitor);

    $this->get(route('contributions.suggest-update', [
        'subjectType' => ContributionSubjectType::Event->publicRouteSegment(),
        'subjectId' => $event->slug,
    ]))
        ->assertOk()
        ->assertSee(__('Gambar Cover Majlis'))
        ->assertSee(__('Poster Hebahan'))
        ->assertSee(__('Galeri'));
});

it('stages visitor event media on a pending contribution request and applies it on approval', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $reviewer = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $event = Event::factory()->for($institution)->create([
        'title' => 'Majlis Media Diluluskan',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subMinute(),
        'event_category_ids' => [eventCategoryId('komuniti_kebajikan')],
        'institution_id' => $institution->id,
        'starts_at' => now()->addDays(3)->setTime(20, 0),
    ]);
    $event->setPrimaryOrganizer($institution);

    $event->addMedia(fakeGeneratedImageUpload('visitor-event-cover.jpg'))
        ->toMediaCollection('cover');
    $cover = $event->getFirstMedia('cover');

    $request = ContributionRequest::factory()->create([
        'status' => ContributionRequestStatus::Pending,
        'subject_type' => ContributionSubjectType::Event,
        'entity_type' => $event->getMorphClass(),
        'entity_id' => $event->getKey(),
        'proposed_data' => ['description' => 'Updated'],
    ]);

    $request->addMediaFromDisk($cover->getPathRelativeToRoot(), $cover->disk)
        ->usingFileName($cover->file_name)
        ->withCustomProperties([
            'contribution_field' => 'cover',
            'is_single' => true,
        ])
        ->toMediaCollection('pending_media');
    $cover->delete();

    expect($request->fresh()->getMedia('pending_media'))->toHaveCount(1)
        ->and($event->fresh()->getFirstMedia('cover'))->toBeNull();

    app(ApproveContributionRequestAction::class)->handle($request->fresh(), $reviewer);

    expect($event->fresh()->getFirstMedia('cover'))
        ->not->toBeNull()
        ->and($event->fresh()->getFirstMedia('cover')->file_name)->toBe('visitor-event-cover.jpg')
        ->and($request->fresh()->getMedia('pending_media'))->toBeEmpty();
});

it('hydrates the selected person institution label even when the institution is not public', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'pending',
    ]);
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);

    Affiliation::create([
        'affiliatable_type' => $person->getMorphClass(),
        'affiliatable_id' => $person->getKey(),
        'institution_id' => $institution->getKey(),
        'position' => 'Professor',
        'is_primary' => true,
    ]);

    Livewire::actingAs($user)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Person->publicRouteSegment(),
            'subjectId' => $person->slug,
        ])
        ->assertSet('data.institutions', fn (array $institutions): bool => collect($institutions)->contains(fn (array $row): bool => ($row['institution_id'] ?? null) === $institution->getKey()
            && ($row['position'] ?? null) === 'Professor'))
        ->assertSee($institution->display_name);
});

it('searches affiliated institutions without case-sensitive matching', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'name' => 'Masjid Al-Hikmah',
        'status' => 'verified',
    ]);
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);

    $component = Livewire::actingAs($user)->test(SuggestUpdate::class, [
        'subjectType' => ContributionSubjectType::Person->publicRouteSegment(),
        'subjectId' => $person->slug,
    ]);

    $component->set('data.institutions', [[
        'institution_id' => null,
        'position' => null,
        'is_primary' => false,
    ]]);

    $fields = $component->instance()->getForm('form')->getFlatFields();
    $institutionField = collect($fields)->first(fn (mixed $field): bool => method_exists($field, 'getName') && $field->getName() === 'institution_id');

    expect($institutionField)->not->toBeNull()
        ->and($institutionField->getSearchResults('masjid'))->toHaveKey($institution->getKey());
});

it('applies direct person affiliation edits for administrators from the suggest update page', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');
    $currentInstitution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $secondaryInstitution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $newInstitution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);

    $person->institutions()->detach();

    Affiliation::create([
        'affiliatable_type' => $person->getMorphClass(),
        'affiliatable_id' => $person->getKey(),
        'institution_id' => $currentInstitution->id,
        'position' => 'Imam',
        'is_primary' => true,
    ]);
    Affiliation::create([
        'affiliatable_type' => $person->getMorphClass(),
        'affiliatable_id' => $person->getKey(),
        'institution_id' => $secondaryInstitution->id,
        'position' => 'Advisor',
        'is_primary' => false,
    ]);

    assignPersonOwner($user, $person);
    $this->actingAs($user);

    Livewire::test(SuggestUpdate::class, [
        'subjectType' => ContributionSubjectType::Person->publicRouteSegment(),
        'subjectId' => $person->slug,
    ])
        ->set('data.institutions', [[
            'institution_id' => $newInstitution->id,
            'position' => 'Mudir',
            'is_primary' => true,
        ], [
            'institution_id' => $secondaryInstitution->id,
            'position' => 'Advisor',
            'is_primary' => false,
        ]])
        ->call('submit')
        ->assertHasNoErrors();

    $person = $person->fresh('institutions');
    $newAffiliation = $person?->institutions->firstWhere('id', $newInstitution->id);
    $secondaryAffiliation = $person?->institutions->firstWhere('id', $secondaryInstitution->id);

    expect($person?->institutions->pluck('id')->all())->toContain($newInstitution->id, $secondaryInstitution->id)
        ->not->toContain($currentInstitution->id)
        ->and($newAffiliation)->not->toBeNull()
        ->and($newAffiliation?->pivot?->position)->toBe('Mudir')
        ->and($newAffiliation?->pivot?->is_primary)->toBeTruthy()
        ->and($secondaryAffiliation?->pivot?->is_primary)->toBeFalsy()
        ->and(ContributionRequest::query()->count())->toBe(0);
});

it('syncs person titles from the public update form without exposing alternate names', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);
    $person->names()->delete();
    $person->titleAssignments()->delete();

    $person->names()->create([
        'name_type' => PersonNameType::Display,
        'full_name' => 'Nama Lama',
        'language_code' => 'ms',
        'is_primary' => true,
    ]);
    $firstTitle = Title::query()->where('short_form', 'Syeikhul Maqari')->firstOrFail();
    $secondTitle = Title::query()->where('id', '!=', $firstTitle->getKey())->firstOrFail();
    $oldAssignment = $person->titleAssignments()->create([
        'title_id' => $firstTitle->getKey(),
        'status' => AssignmentStatus::Active,
    ]);

    assignPersonOwner($user, $person);

    Livewire::actingAs($user)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Person->publicRouteSegment(),
            'subjectId' => $person->slug,
        ])
        ->assertSee(__('Alternative Names'))
        ->assertDontSee(__('Date Awarded'))
        ->assertDontSee(__('Date Expired'))
        ->assertSee(__('Titles'))
        ->set('data.title_ids', [$firstTitle->getKey(), $secondTitle->getKey()])
        ->call('submit')
        ->assertHasNoErrors();

    $person->refresh();

    expect($person->names()->pluck('full_name')->all())
        ->toBe(['Nama Lama'])
        ->and($person->titleAssignments()->count())->toBe(2)
        ->and($person->titleAssignments()->pluck('title_id')->all())
        ->toContain($firstTitle->getKey(), $secondTitle->getKey())
        ->and($person->titleAssignments()->findOrFail($oldAssignment->getKey())->status)
        ->toBe(AssignmentStatus::Active)
        ->and(ContributionRequest::query()->count())->toBe(0);
});

it('syncs person media collections when an administrator updates them publicly', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $user = User::factory()->create();
    $user->assignRole('admin');
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);
    $person->addMedia(fakeGeneratedImageUpload('old-square.jpg'))
        ->toMediaCollection('square');
    $person->addMedia(fakeGeneratedImageUpload('old-gallery.jpg'))
        ->toMediaCollection('gallery');
    $oldSquareUuid = $person->getFirstMedia('square')?->uuid;
    $oldGalleryUuid = $person->getFirstMedia('gallery')?->uuid;

    assignPersonOwner($user, $person);

    Livewire::actingAs($user)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Person->publicRouteSegment(),
            'subjectId' => $person->slug,
        ])
        ->fillForm([
            'square' => UploadedFile::fake()->image('new-square.jpg', 1000, 1000),
            'gallery' => [UploadedFile::fake()->image('new-gallery.jpg')],
        ])
        ->call('submit')
        ->assertHasNoErrors();

    $person = $person->fresh();

    expect($person->getMedia('square'))->toHaveCount(1)
        ->and($person->getFirstMedia('square')?->uuid)->not->toBe($oldSquareUuid)
        ->and($person->getMedia('gallery'))->toHaveCount(1)
        ->and($person->getFirstMedia('gallery')?->uuid)->not->toBe($oldGalleryUuid)
        ->and(ContributionRequest::query()->count())->toBe(0);
});

it('stages person media from owner contributors for review', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $user = User::factory()->create();
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);

    assignPersonOwner($user, $person);

    Livewire::actingAs($user)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Person->publicRouteSegment(),
            'subjectId' => $person->slug,
        ])
        ->fillForm([
            'square' => UploadedFile::fake()->image('public-square.jpg', 1000, 1000),
        ])
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('contributions.index'));

    $request = ContributionRequest::query()->where('entity_id', $person->getKey())->latest()->first();

    expect($request)->not->toBeNull()
        ->and($request->getMedia('pending_media'))->toHaveCount(1)
        ->and($person->fresh()->getMedia('square'))->toBeEmpty();
});

it('exposes Filament action handlers required by public contribution media uploads', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    assignInstitutionOwner($user, $institution);

    $this->actingAs($user);

    expect(method_exists(Livewire::test(SubmitInstitution::class)->instance(), 'mountAction'))->toBeTrue()
        ->and(method_exists(Livewire::test(SubmitPerson::class)->instance(), 'mountAction'))->toBeTrue()
        ->and(method_exists(Livewire::test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
            'subjectId' => $institution->slug,
        ])->instance(), 'mountAction'))->toBeTrue();
});

it('applies direct institution edits for administrators from the suggest update page', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');
    $institution = Institution::factory()->create([
        'name' => 'Masjid Lama',
        'status' => 'verified',
    ]);
    giveContributionPagesInstitutionMapsPlace($institution);
    withGlobalOwnerContext(fn () => $institution->contactMethods()->delete());

    assignInstitutionOwner($user, $institution);
    $this->actingAs($user);

    Livewire::test(SuggestUpdate::class, [
        'subjectType' => 'institution',
        'subjectId' => $institution->slug,
    ])
        ->set('data.name', 'Masjid Baru')
        ->call('submit')
        ->assertHasNoErrors();

    expect($institution->fresh()->name)->toBe('Masjid Baru')
        ->and(ContributionRequest::query()->count())->toBe(0);
});

it('applies direct institution edits when an existing phone contact is present on the suggest update page', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');
    $institution = Institution::factory()->create([
        'name' => 'Masjid Lama',
        'status' => 'verified',
    ]);
    giveContributionPagesInstitutionMapsPlace($institution);
    withGlobalOwnerContext(function () use ($institution): void {
        $institution->contactMethods()->delete();
        $institution->contactMethods()->create([
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
        ->set('data.name', 'Masjid Baru')
        ->call('submit')
        ->assertHasNoErrors();

    expect($institution->fresh()->name)->toBe('Masjid Baru')
        ->and(withGlobalOwnerContext(fn () => $institution->fresh()->contactMethods()->where('type', ContactMethodType::Phone->value)->value('value')))
        ->toBe('+60112223344')
        ->and(ContributionRequest::query()->count())->toBe(0);
});

it('applies direct institution address edits for administrators from the suggest update page', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    giveContributionPagesInstitutionMapsPlace($institution);
    withGlobalOwnerContext(fn () => $institution->contactMethods()->delete());

    assignInstitutionOwner($user, $institution);
    $this->actingAs($user);

    Livewire::test(SuggestUpdate::class, [
        'subjectType' => 'institution',
        'subjectId' => $institution->slug,
    ])
        ->set('data.address.line1', 'No. 21, Jalan Disemak')
        ->call('submit')
        ->assertHasNoErrors();

    expect($institution->fresh()->primaryAddress()?->line1)->toBe('No. 21, Jalan Disemak')
        ->and(ContributionRequest::query()->count())->toBe(0);
});

it('applies direct institution cover edits for administrators from the suggest update page', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $user = User::factory()->create();
    $user->assignRole('admin');
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    giveContributionPagesInstitutionMapsPlace($institution);

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

it('applies direct institution gallery edits for administrators from the suggest update page', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $user = User::factory()->create();
    $user->assignRole('admin');
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    giveContributionPagesInstitutionMapsPlace($institution);

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

it('keeps approved events approved when administrators apply sensitive ordinary edits from the suggest update page', function () {
    config(['events.features.owner.enabled' => false]);
    $user = User::factory()->create();
    $user->assignRole('admin');
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $event = Event::factory()->for($institution)->create([
        'title' => 'Majlis Sensitif',
        'status' => 'approved',
        'delivery_mode' => EventFormat::Physical,
        'event_category_ids' => [eventCategoryId('komuniti_kebajikan')],
        'starts_at' => now()->addDays(4)->setTime(20, 0),
        'ends_at' => now()->addDays(4)->setTime(21, 0),
    ]);
    $event->setPrimaryOrganizer($institution);
    $languageId = languageId('ms');
    $event->syncLanguages([$languageId]);

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
        ->assertSet('data.language_ids', [$languageId])
        ->set('data.domain_tags', [submitEventTerm('domain')->getKey()])
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
        'event_category_ids' => [eventCategoryId('komuniti_kebajikan')],
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

it('enforces the public submission aspect ratios on the event update page', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $event = Event::factory()->for($institution)->create([
        'title' => 'Majlis Dengan Nisbah Media',
        'status' => 'approved',
        'event_category_ids' => [eventCategoryId('komuniti_kebajikan')],
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
                ->toBe('3:4')
                ->and($aspectRatioOptions)
                ->toContain('3:4')
                ->toHaveCount(2);

            return true;
        })
        ->fillForm([
            'cover' => UploadedFile::fake()->image('square-cover.jpg', 400, 400),
            'poster' => UploadedFile::fake()->image('square-poster.jpg', 400, 400),
        ])
        ->call('submit')
        ->assertHasErrors(['data.cover', 'data.poster']);
});

it('uses the canonical 1:1 square ratio on the public speaker form', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(SubmitPerson::class)
        ->assertFormFieldExists('square', function (FileUpload $upload): bool {
            expect($upload->getImageAspectRatio())->toBe('1:1');

            return true;
        })
        ->assertFormFieldDoesNotExist('cover');
});

it('renders the submit-style waktu field on the event update page', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $event = Event::factory()->for($institution)->create([
        'title' => 'Majlis Ada Waktu',
        'status' => 'approved',
        'event_category_ids' => [eventCategoryId('komuniti_kebajikan')],
        'institution_id' => $institution->id,
        'starts_at' => now()->addDays(3)->setTime(20, 0),
        'timing_mode' => TimingMode::Absolute->value,
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
        'delivery_mode' => EventFormat::Physical,
        'institution_id' => null,
        'default_venue_id' => $venue->id,
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
    $this->seed(EventRoleSeeder::class);

    config(['events.features.owner.enabled' => false]);
    $user = User::factory()->create();
    $user->assignRole('admin');
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);
    $venue = Venue::factory()->create([
        'status' => 'verified',
    ]);
    $event = Event::factory()->for($institution)->create([
        'title' => 'Majlis Tukar Penganjur',
        'status' => 'approved',
        'event_category_ids' => [eventCategoryId('komuniti_kebajikan')],
        'delivery_mode' => EventFormat::Physical,
        'institution_id' => $institution->id,
        'default_venue_id' => null,
        'timing_mode' => TimingMode::Absolute,
        'prayer_reference' => null,
        'prayer_offset' => null,
        'prayer_display_text' => null,
        'starts_at' => CarbonImmutable::parse('2027-03-20 20:00:00', 'Asia/Kuala_Lumpur')->utc(),
        'ends_at' => CarbonImmutable::parse('2027-03-20 21:00:00', 'Asia/Kuala_Lumpur')->utc(),
    ]);
    $event->setPrimaryOrganizer($institution);

    assignInstitutionOwner($user, $institution);
    $this->actingAs($user);

    Livewire::test(SuggestUpdate::class, [
        'subjectType' => ContributionSubjectType::Event->publicRouteSegment(),
        'subjectId' => $event->slug,
    ])
        ->set('data.primary_organizer_kind', 'person')
        ->set('data.primary_organizer_id', $person->id)
        ->set('data.primary_organizer_person_id', $person->id)
        ->set('data.location_same_as_institution', false)
        ->set('data.location_type', 'venue')
        ->set('data.location_venue_id', $venue->id)
        ->set('data.language_ids', [languageId('ms')])
        ->set('data.domain_tags', [submitEventTerm('domain')->getKey()])
        ->call('submit')
        ->assertHasNoErrors();

    $involvement = $event->fresh()->primaryOrganizerInvolvement;
    $updatedEvent = $event->fresh();

    expect($involvement?->involveable_type)->toBe((new Person)->getMorphClass())
        ->and($involvement?->involveable_id)->toBe((string) $person->getKey())
        ->and($updatedEvent?->institution_id)->toBeNull()
        ->and($updatedEvent?->default_venue_id)->toBe($venue->id)
        ->and($updatedEvent?->primaryLocation?->venue_id)->toBe($venue->id)
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
    giveContributionPagesInstitutionMapsPlace($institution);
    withGlobalOwnerContext(fn () => $institution->contactMethods()->delete());

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

it('shows the pending request notice below the heading and hides update controls', function () {
    app()->setLocale('ms');

    $user = User::factory()->create();
    $proposer = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    ContributionRequest::factory()->create([
        'proposer_id' => $proposer->id,
        'entity_type' => $institution->getMorphClass(),
        'entity_id' => $institution->id,
        'status' => ContributionRequestStatus::Pending,
    ]);

    $this->actingAs($user);

    $component = Livewire::test(SuggestUpdate::class, [
        'subjectType' => 'institution',
        'subjectId' => $institution->slug,
    ]);

    $component
        ->assertSeeInOrder([
            __('Suggest an Update'),
            __('Pending Request'),
            __('A contribution request for this record is already pending. Please wait for it to be reviewed before submitting another.'),
        ])
        ->assertDontSee(__('Submit Update Request'))
        ->call('submit')
        ->assertHasErrors(['data']);

    expect(ContributionRequest::query()
        ->where('entity_type', $institution->getMorphClass())
        ->where('entity_id', $institution->getKey())
        ->count())->toBe(1);
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
    $person = Person::factory()->create([
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

    $event->persons()->syncWithoutDetaching([$person->id]);
    $event->references()->syncWithoutDetaching([$reference->id]);

    $occurrence = withGlobalOwnerContext(fn (): EventOccurrence => EventOccurrence::query()->create([
        'event_id' => $event->getKey(),
        'title' => 'Siri 1: Majlis Ilmu Tracker',
        'slug' => 'siri-1-majlis-ilmu-tracker',
        'starts_at' => now()->addWeek()->setTime(20, 0),
        'ends_at' => now()->addWeek()->setTime(22, 0),
        'timezone' => 'Asia/Kuala_Lumpur',
        'status' => 'published',
        'visibility' => 'public',
        'delivery_mode' => 'physical',
    ]));

    EventSubmission::factory()->for($event)->for($user, 'submitter')->create([
        'event_occurrence_id' => $occurrence->getKey(),
    ]);
    EventSubmission::factory()->for($ownedEvent)->for($user, 'submitter')->create([
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

    $component = Livewire::test(ContributionsIndex::class)
        ->assertSee('data-active-tab="overview"', false)
        ->assertSee(__('Event'))
        ->assertSee(__('Institution'))
        ->assertSee(__('Speaker'))
        ->assertSee(__('Claim Management'))
        ->assertSee(__('App Feedback'))
        ->assertDontSee(__('Pending Approvals'))
        ->assertDontSee(__('Approve'))
        ->assertDontSee(__('Reject'));

    $component->call('selectTab', 'directory')
        ->assertSee(__('New Directory Records'))
        ->assertSee($createRequest->proposed_data['name'])
        ->assertSee($createRequest->proposer_note);

    $component->call('selectTab', 'updates')
        ->assertSee(__('Update Suggestions'))
        ->assertSee($updateRequest->entity->name)
        ->assertSee($updateRequest->proposed_data['name'])
        ->assertSee($updateRequest->original_data['name']);

    $component->call('selectTab', 'events')
        ->assertSee(__('Event Submissions'))
        ->assertSee(__('Occurrence'))
        ->assertSee($occurrence->title)
        ->assertSeeInOrder([$occurrence->title, $event->title])
        ->assertSee('Published')
        ->assertSee(route('events.occurrence', [$event, $occurrence->slug]), false)
        ->assertDontSee('href="'.route('events.show', $event).'"', false)
        ->assertDontSee($ownedEvent->title)
        ->assertDontSee(route('events.show', $ownedEvent), false)
        ->assertSee(__('Institution: :name', ['name' => $eventInstitution->display_name]))
        ->assertSee(__('Speakers: :names', ['names' => $person->formatted_name]))
        ->assertSee(__('References: :names', ['names' => $reference->title]));

    $component->call('selectTab', 'reports')
        ->assertSee(__('Report Submissions'))
        ->assertSeeInOrder([__('Institution'), $reportInstitution->name, __('Wrong information')]);
});

it('falls back to the parent event when a submission has no occurrence', function () {
    app()->setLocale('ms');

    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $event = Event::factory()->for($institution)->create([
        'title' => 'Majlis Tanpa Tarikh',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
    ]);

    EventSubmission::factory()->for($event)->for($user, 'submitter')->create();

    $this->actingAs($user);

    Livewire::withQueryParams(['tab' => 'events'])
        ->test(ContributionsIndex::class)
        ->assertSee('Majlis Tanpa Tarikh')
        ->assertSee(route('events.show', $event), false);
});

it('restores the active contributions tab from the query string', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::withQueryParams(['tab' => 'reports'])
        ->test(ContributionsIndex::class)
        ->assertSet('activeTab', 'reports');

    Livewire::withQueryParams(['tab' => 'unknown'])
        ->test(ContributionsIndex::class)
        ->assertSet('activeTab', 'overview');

    $this->get(route('contributions.index', ['tab' => 'reports']))
        ->assertOk()
        ->assertSee('data-active-tab="reports"', false);

    $this->get(route('contributions.index', ['tab' => 'unknown']))
        ->assertOk()
        ->assertSee('data-active-tab="overview"', false);
});

it('formats institution membership claim options with the location hierarchy', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'name' => 'Masjid Payung',
        'status' => 'verified',
    ]);
    $geo = createTestPackageGeography('Selangor', 'Petaling', 'Shah Alam');

    syncPrimaryAddressForTest($institution, $geo['address']);

    $this->actingAs($user);

    $component = Livewire::actingAs($user)->test(ContributionsIndex::class);
    $component->call('selectTab', 'membership');
    $component->set('claimSubjectType', 'institution');
    $component->set('claimSearch', 'Payung');

    $results = $component->instance()->claimSearchResults;
    $match = collect($results)->firstWhere('identifier', (string) $institution->getKey());

    expect($match)->not->toBeNull()
        ->and($match['label'])->toBe('Masjid Payung')
        ->and($match['subtitle'])->toBe('Shah Alam, Petaling, Selangor');

    $component->call('selectClaimRecord', (string) $institution->getKey(), 'Masjid Payung')
        ->assertSee('Masjid Payung')
        ->assertSet('selectedClaimIdentifier', (string) $institution->getKey());
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

    ContributionRequest::factory()->count(9)->create([
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

    ContributionRequest::factory()->count(9)->create([
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

    EventSubmission::factory()->count(9)->for($event)->for($user, 'submitter')->create();

    Report::factory()->count(9)->create([
        'reporter_id' => $user->id,
        'entity_type' => $reportInstitution->getMorphClass(),
        'entity_id' => $reportInstitution->id,
        'status' => 'open',
        'category' => 'wrong_info',
    ]);

    $this->actingAs($user);

    $component = Livewire::test(ContributionsIndex::class)->instance();

    expect($component->submittedEvents->hasPages())->toBeTrue()
        ->and($component->directorySubmissions->hasPages())->toBeTrue()
        ->and($component->updateRequests->hasPages())->toBeTrue()
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
        ->call('cancelContributionRequest', $request->id);

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

it('redirects guests to login on canonical report and suggest update pages', function () {
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);

    $institutionRouteSegment = ContributionSubjectType::Institution->publicRouteSegment();
    $personRouteSegment = ContributionSubjectType::Person->publicRouteSegment();

    $this->get(route('contributions.suggest-update', ['subjectType' => $institutionRouteSegment, 'subjectId' => $institution->slug]))
        ->assertRedirect(route('login'));

    $this->get(route('reports.create', ['subjectType' => $institutionRouteSegment, 'subjectId' => $institution->slug]))
        ->assertRedirect(route('login'));

    $this->get(route('contributions.suggest-update', ['subjectType' => $personRouteSegment, 'subjectId' => $person->slug]))
        ->assertRedirect(route('login'));

    $this->get(route('reports.create', ['subjectType' => $personRouteSegment, 'subjectId' => $person->slug]))
        ->assertRedirect(route('login'));
});

it('forbids users banned from directory feedback from opening update and report pages', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('feedback.blocked');
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);

    $this->actingAs($user);

    $this->get(route('contributions.suggest-update', ['subjectType' => ContributionSubjectType::Person->publicRouteSegment(), 'subjectId' => $person->slug]))
        ->assertForbidden();

    $this->get(route('reports.create', ['subjectType' => ContributionSubjectType::Person->publicRouteSegment(), 'subjectId' => $person->slug]))
        ->assertForbidden();
});

it('forbids users banned from directory feedback from opening contribution create pages', function (string $pageClass, string $routeName) {
    $user = User::factory()->create();
    $user->givePermissionTo('feedback.blocked');

    $this->actingAs($user);

    $this->get(route($routeName))->assertForbidden();

    Livewire::actingAs($user)->test($pageClass)->assertForbidden();
})->with([
    'institution' => [SubmitInstitution::class, 'contributions.submit-institution'],
    'speaker' => [SubmitPerson::class, 'contributions.submit-person'],
    'reference' => [SubmitReference::class, 'contributions.submit-reference'],
]);

it('blocks contribution create submissions when the contributor is banned after opening the form', function (string $pageClass, string $routeName) {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test($pageClass);

    $user->givePermissionTo('feedback.blocked');

    $this->actingAs($user->fresh());

    $component->call('submit')->assertForbidden();

    expect(ContributionRequest::query()->count())->toBe(0);
})->with([
    'institution' => [SubmitInstitution::class, 'contributions.submit-institution'],
    'speaker' => [SubmitPerson::class, 'contributions.submit-person'],
    'reference' => [SubmitReference::class, 'contributions.submit-reference'],
]);

it('resolves person slugs on the update suggestion page without uuid casting errors', function () {
    $user = User::factory()->create();
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);

    $this->actingAs($user);

    Livewire::test(SuggestUpdate::class, [
        'subjectType' => ContributionSubjectType::Person->publicRouteSegment(),
        'subjectId' => $person->slug,
    ])->assertSet('subjectType', 'person');
});

it('hydrates the family name on the person update form', function () {
    $user = User::factory()->create();
    $person = Person::factory()->create([
        'name' => 'Maszlee',
        'family_name' => 'Malik',
        'status' => 'verified',
    ]);

    Livewire::actingAs($user)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Person->publicRouteSegment(),
            'subjectId' => $person->slug,
        ])
        ->assertSet('data.family_name', 'Malik');
});

it('hydrates primary and public flags for person contacts and social media', function () {
    $person = Person::factory()->create(['status' => 'verified']);

    withGlobalOwnerContext(function () use ($person): void {
        $person->contactMethods()->create([
            'type' => ContactMethodType::Email->value,
            'purpose' => ContactPurpose::General->value,
            'value' => 'speaker@example.test',
            'is_primary' => true,
            'is_public' => false,
        ]);
        $person->socialProfiles()->create([
            'platform' => 'facebook',
            'handle' => 'speaker',
            'is_primary' => true,
            'is_public' => false,
        ]);
    });

    $state = app(ContributionEntityStateService::class)->stateForEntity($person->fresh());

    $contact = collect($state['contactMethods'])->firstWhere('value', 'speaker@example.test');
    $social = collect($state['social_media'])->firstWhere('handle', 'speaker');

    expect($contact)->toMatchArray([
        'is_primary' => true,
        'is_public' => false,
    ])->and($social)->toMatchArray([
        'is_primary' => true,
        'is_public' => false,
    ]);
});

it('keeps person update suggestions on a region-only address form', function () {
    $user = User::factory()->create();
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);

    $this->actingAs($user);

    $this->get(route('contributions.suggest-update', [
        'subjectType' => ContributionSubjectType::Person->publicRouteSegment(),
        'subjectId' => $person->slug,
    ]))
        ->assertOk()
        ->assertSee(__('Address'))
        ->assertDontSee(__('Address Line 1'))
        ->assertDontSee(__('Address Line 2'))
        ->assertDontSee(__('Postcode'))
        ->assertDontSee(__('Google Maps URL'))
        ->assertDontSee(__('Waze URL'));
});

it('does not treat unchanged person update forms as changes when current address data exists', function () {
    $owner = User::factory()->create();
    $owner->assignRole('admin');
    $country = ensureTestMalaysiaCountry();
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);

    withGlobalOwnerContext(function () use ($person): void {
        $person->contactMethods()->delete();
        $person->contactMethods()->create([
            'type' => ContactMethodType::Email->value,
            'purpose' => ContactPurpose::General->value,
            'value' => 'person@example.test',
            'is_public' => true,
        ]);
        $person->contactMethods()->create([
            'type' => ContactMethodType::Phone->value,
            'purpose' => ContactPurpose::General->value,
            'value' => '+1-878-669-9223',
            'is_public' => true,
        ]);
    });

    syncPrimaryAddressForTest($person, [
        'country_id' => (string) $country->getKey(),
        'line1' => 'Alamat Warisan',
        'google_maps_url' => 'https://maps.google.com/?q=3.1390,101.6869',
    ]);

    assignPersonOwner($owner, $person);

    Livewire::actingAs($owner)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Person->publicRouteSegment(),
            'subjectId' => $person->slug,
        ])
        ->call('submit')
        ->assertHasErrors('data');

    expect($person->fresh('addresses')?->primaryAddress()?->line1)->toBe('Alamat Warisan')
        ->and($person->fresh('addresses')?->primaryAddress()?->google_maps_url)->toBe('https://maps.google.com/?q=3.1390,101.6869');
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

it('shows the reported person clearly on the public report page', function () {
    $user = User::factory()->create();
    $person = Person::factory()->create([
        'name' => 'Amina binti Rashid',
        'status' => 'verified',
    ]);
    $selectedPersonLabel = __('Selected :subject', ['subject' => strtolower(__('Speaker'))]);
    $viewPersonLabel = __('View this :subject', ['subject' => strtolower(__('Speaker'))]);

    $this->actingAs($user);

    $this->get(route('reports.create', [
        'subjectType' => ContributionSubjectType::Person->publicRouteSegment(),
        'subjectId' => $person->slug,
    ]))
        ->assertOk()
        ->assertSeeText($selectedPersonLabel)
        ->assertSeeText($person->formatted_name)
        ->assertSeeText($viewPersonLabel);
});

it('shows the reported person profile image when available', function () {
    $user = User::factory()->create();
    $person = Person::factory()->create([
        'name' => 'Zaharuddin Abdul Rahman',
        'status' => 'verified',
    ]);
    $person->addMedia(fakeGeneratedImageUpload('zaharuddin.jpg', 1200, 1200))
        ->toMediaCollection('profile');

    $this->actingAs($user);

    $this->get(route('reports.create', [
        'subjectType' => ContributionSubjectType::Person->publicRouteSegment(),
        'subjectId' => $person->slug,
    ]))
        ->assertOk()
        ->assertSee('src="'.$person->fresh()->public_main_url.'"', false)
        ->assertSee('alt="Gambar profil '.$person->formatted_name.'"', false);
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

it('captures only changed fields in a non-owner person update request', function (bool $hasState, bool $changeState) {
    $user = User::factory()->create();
    $person = Person::factory()->create([
        'status' => 'verified',
        'middle_name' => null,
    ]);
    $geography = createTestPackageGeography();
    $stateId = (string) $geography['state']->getKey();
    $address = Address::query()->create([
        'country_id' => $geography['country']->getKey(),
        'state_id' => $hasState ? $stateId : null,
    ]);
    $person->attachAddress($address, type: 'primary', isPrimary: true);

    $this->actingAs($user);

    $component = Livewire::test(SuggestUpdate::class, [
        'subjectType' => ContributionSubjectType::Person->publicRouteSegment(),
        'subjectId' => $person->refresh()->slug,
    ])
        ->assertSet('data.address.country_id', $address->country_id)
        ->assertSet('data.address.state_id', $hasState ? $stateId : null)
        ->set('data.middle_name', 'Hasan')
        ->set('data.proposer_note', 'Betulkan nama tengah');

    if ($changeState) {
        $component->set('data.address.state_id', $stateId);
    }

    $component
        ->call('submit')
        ->assertHasNoErrors();

    $request = ContributionRequest::query()
        ->where('subject_type', ContributionSubjectType::Person->value)
        ->where('entity_id', $person->getKey())
        ->sole();

    expect($request->type)->toBe(ContributionRequestType::Update)
        ->and($request->status)->toBe(ContributionRequestStatus::Pending)
        ->and($request->proposer_note)->toBe('Betulkan nama tengah')
        ->and(array_keys($request->proposed_data))->toEqualCanonicalizing($changeState ? ['middle_name', 'address'] : ['middle_name'])
        ->and($request->proposed_data['middle_name'])->toBe('Hasan')
        ->and(data_get($request->proposed_data, 'address.state_id'))->toBe($changeState ? $stateId : null);
})->with([
    'unchanged country-only address' => [false, false],
    'unchanged state' => [true, false],
    'state added' => [false, true],
]);

it('keeps unchanged social links out of reference suggestions and saves label edits', function (string $platform, string $field, string $value, bool $editLabel): void {
    $proposer = User::factory()->create();
    $reviewer = User::factory()->create();
    $reviewer->assignRole('admin');
    $reference = Reference::factory()->create(['status' => 'verified']);

    withGlobalOwnerContext(fn () => $reference->socialProfiles()->create([
        'platform' => $platform,
        'label' => 'Official link',
        $field => $value,
        'is_primary' => true,
        'is_public' => false,
    ]));

    $form = Livewire::actingAs($proposer)->test(SuggestUpdate::class, [
        'subjectType' => ContributionSubjectType::Reference->publicRouteSegment(),
        'subjectId' => $reference->slug,
    ]);
    $row = array_key_first($form->get('data.social_media'));
    $form->assertSet("data.social_media.{$row}.label", 'Official link');

    if ($editLabel) {
        $form->set("data.social_media.{$row}.label", 'Updated label');
    } else {
        $form->set('data.description', 'Corrected description.');
    }

    $form->call('submit')->assertHasNoErrors();

    $request = ContributionRequest::query()->where('entity_id', $reference->getKey())->sole();
    expect(array_keys($request->proposed_data))->toBe([$editLabel ? 'social_media' : 'description']);

    app(ApproveContributionRequestAction::class)->handle($request, $reviewer);

    $profile = withGlobalOwnerContext(fn () => $reference->socialProfiles()->sole());
    expect($profile->label)->toBe($editLabel ? 'Updated label' : 'Official link')
        ->and($profile->getAttribute($field))->toBe($value);
})->with([
    'unchanged handle' => ['facebook', 'handle', 'reference-page', false],
    'unchanged URL' => ['website', 'url', 'https://example.org/reference', false],
    'edited label' => ['facebook', 'handle', 'reference-page', true],
]);
