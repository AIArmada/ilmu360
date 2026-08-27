<?php

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Membership\Enums\ApplicationStatus;
use AIArmada\Membership\Enums\MemberRole;
use App\Enums\MemberSubjectType;
use App\Livewire\Pages\Contributions\Index as ContributionsIndex;
use App\Livewire\Pages\MembershipApplications\Create as CreateMembershipApplicationPage;
use App\Livewire\Pages\MembershipApplications\Index as MembershipApplicationsIndex;
use App\Models\Institution;
use App\Models\MembershipApplication;
use App\Models\Person;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Ysfkaya\FilamentPhoneInput\Forms\PhoneInput;
use Ysfkaya\FilamentPhoneInput\PhoneInputNumberType;

beforeEach(function (): void {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');
});

it('redirects guests to login for membership application routes', function () {
    $institution = Institution::factory()->create(['status' => 'verified']);

    $this->get(route('membership-applications.create', [
        'subjectType' => MemberSubjectType::Institution->publicRouteSegment(),
        'subjectId' => $institution->getKey(),
    ]))->assertRedirect(route('login'));

    $this->get(route('membership-applications.index'))
        ->assertRedirect(route('login'));
});

it('lets authenticated users submit a claim with applied role and relationship', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);

    Livewire::actingAs($user)
        ->test(CreateMembershipApplicationPage::class, [
            'subjectType' => MemberSubjectType::Institution->publicRouteSegment(),
            'subjectId' => $institution->getKey(),
        ])
        ->fillForm([
            'applied_role' => MemberRole::Editor->value,
            'relationship' => 'committee_member',
            'notes' => 'Saya membantu urusan pentadbiran institusi ini.',
            'evidence' => [
                UploadedFile::fake()->image('proof.png', 1200, 800),
                UploadedFile::fake()->image('supporting-letter.png', 1200, 800),
            ],
        ])
        ->call('submit')
        ->assertRedirect(route('membership-applications.index'));

    $claim = MembershipApplication::query()->where('applicant_id', $user->getKey())->firstOrFail();

    expect($claim->subject_type)->toBe(MemberSubjectType::Institution)
        ->and($claim->status)->toBe(ApplicationStatus::Pending)
        ->and($claim->meta['applied_role'])->toBe(MemberRole::Editor->value)
        ->and($claim->meta['relationship'])->toBe('committee_member')
        ->and($claim->meta['notes'])->toBe('Saya membantu urusan pentadbiran institusi ini.')
        ->and($claim->getMedia('evidence'))->toHaveCount(2);
});

it('uses institution-specific relationship options on the public claim form', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);

    Livewire::actingAs($user)
        ->test(CreateMembershipApplicationPage::class, [
            'subjectType' => MemberSubjectType::Institution->publicRouteSegment(),
            'subjectId' => $institution->getKey(),
        ])
        ->assertFormFieldExists('relationship', function (Select $field): bool {
            expect($field->getOptions())->toBe([
                'imam' => 'Imam',
                'bilal' => 'Bilal',
                'committee_member' => 'Ahli Jawatan Kuasa',
                'employee' => 'Pekerja',
            ]);

            return true;
        });
});

it('uses the account phone input and exposes the applicant notes field', function () {
    $user = User::factory()->emailOnly()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);

    Livewire::actingAs($user)
        ->test(CreateMembershipApplicationPage::class, [
            'subjectType' => MemberSubjectType::Institution->publicRouteSegment(),
            'subjectId' => $institution->getKey(),
        ])
        ->assertFormFieldExists('phone', function (PhoneInput $field): bool {
            expect($field)->toBeInstanceOf(PhoneInput::class)
                ->and($field->getInitialCountry())->toBe('MY')
                ->and($field->getDisplayNumberFormat())->toBe(PhoneInputNumberType::INTERNATIONAL->value)
                ->and($field->getInputNumberFormat())->toBe(PhoneInputNumberType::E164->value);

            return true;
        })
        ->assertFormFieldExists('notes', function (Textarea $field): bool {
            expect($field)->toBeInstanceOf(Textarea::class);

            return true;
        });
});

it('saves a provided phone number to the applicant profile when missing', function () {
    $user = User::factory()->emailOnly()->create();
    $person = Person::factory()->create(['status' => 'verified']);

    expect($user->phone)->toBeNull();

    Livewire::actingAs($user)
        ->test(CreateMembershipApplicationPage::class, [
            'subjectType' => MemberSubjectType::Person->publicRouteSegment(),
            'subjectId' => $person->slug,
        ])
        ->fillForm([
            'applied_role' => MemberRole::Owner->value,
            'phone' => '0123456789',
        ])
        ->call('submit')
        ->assertRedirect(route('membership-applications.index'));

    expect($user->fresh()->phone)->toBe('0123456789');

    $claim = MembershipApplication::query()->where('applicant_id', $user->getKey())->firstOrFail();

    expect($claim->meta['applied_role'])->toBe(MemberRole::Owner->value)
        ->and($claim->meta['relationship'])->toBe('self');
});

it('requires applied role and relationship on the public claim form', function () {
    $user = User::factory()->create();
    $person = Person::factory()->create(['status' => 'verified']);

    Livewire::actingAs($user)
        ->test(CreateMembershipApplicationPage::class, [
            'subjectType' => MemberSubjectType::Person->publicRouteSegment(),
            'subjectId' => $person->slug,
        ])
        ->call('submit')
        ->assertHasErrors([
            'data.applied_role',
        ]);
});

it('renders the public membership claim page in Malay without a side-by-side layout', function () {
    $user = User::factory()->emailOnly()->create();
    $person = Person::factory()->create([
        'name' => 'Ustaz Kazim Elias',
        'status' => 'verified',
    ]);

    app()->setLocale('ms');
    $this->actingAs($user);

    $this->get(route('membership-applications.create', [
        'subjectType' => MemberSubjectType::Person->publicRouteSegment(),
        'subjectId' => $person->slug,
    ]))
        ->assertOk()
        ->assertSee('Pengurusan')
        ->assertSee('Tuntut Pengurusan')
        ->assertSee('Gunakan borang ini apabila anda benar-benar terlibat dengan rekod ini dan memerlukan akses untuk membantu menguruskannya. Tuntutan ini akan disemak oleh moderator sebelum pengurusan diberikan.')
        ->assertSee('Rekod Dipilih')
        ->assertSee('Menuntut akses untuk penceramah ini')
        ->assertSee('Sila sahkan bahawa ini ialah penceramah yang anda mahu tuntut sebelum menghantar.')
        ->assertSee('Hantar Tuntutan')
        ->assertDontSee('Tuntutan Saya')
        ->assertSee('Nota semakan')
        ->assertSee('Tuntutan tidak memberi akses serta-merta')
        ->assertSee('Penyemak menentukan peranan akhir')
        ->assertDontSee('Mengapa anda patut ditambah?')
        ->assertSee('Fail Bukti')
        ->assertSee('Peranan yang anda mohon')
        ->assertSee('Hubungan anda dengan Penceramah')
        ->assertSee('Kami memerlukan nombor telefon untuk mengesahkan tuntutan anda. Nombor ini disimpan pada profil anda, bukan pada permohonan.')
        ->assertSee('Catatan')
        ->assertDontSee('Use this form when you belong to this record and need access to help maintain it. Claims are reviewed by moderators before membership is granted.')
        ->assertDontSee('Review notes')
        ->assertDontSee('lg:grid-cols-[1.1fr_0.9fr]', false);
});

it('does not show the claims history button on an institution membership claim page', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);

    app()->setLocale('ms');
    $this->actingAs($user);

    $this->get(route('membership-applications.create', [
        'subjectType' => MemberSubjectType::Institution->publicRouteSegment(),
        'subjectId' => $institution->getKey(),
    ]))
        ->assertOk()
        ->assertSee('Hantar Tuntutan')
        ->assertDontSee('Tuntutan Saya');
});

it('shows a speaker profile image on the membership claim page when available', function () {
    $user = User::factory()->create();
    $person = Person::factory()->create([
        'name' => 'Zaharuddin Abdul Rahman',
        'status' => 'verified',
    ]);
    $person->addMedia(fakeGeneratedImageUpload('zaharuddin.jpg', 1200, 1200))
        ->toMediaCollection('profile');

    $this->actingAs($user);

    $this->get(route('membership-applications.create', [
        'subjectType' => MemberSubjectType::Person->publicRouteSegment(),
        'subjectId' => $person->slug,
    ]))
        ->assertOk()
        ->assertSee('src="'.$person->fresh()->public_main_url.'"', false)
        ->assertSee('alt="Gambar profil '.$person->formatted_name.'"', false);
});

it('lets claimants cancel pending claims from the history page', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create();
    $claim = MembershipApplication::factory()
        ->for($institution, 'subject')
        ->create([
            'applicant_id' => $user->getKey(),
            'status' => ApplicationStatus::Pending,
        ]);

    Livewire::actingAs($user)
        ->test(MembershipApplicationsIndex::class)
        ->call('cancel', $claim->getKey())
        ->assertHasNoErrors();

    expect($claim->fresh()->status)->toBe(ApplicationStatus::Cancelled);
});

it('starts a membership claim from the contributions page search form', function () {
    $user = User::factory()->create();
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);

    Livewire::actingAs($user)
        ->test(ContributionsIndex::class)
        ->fillForm([
            'subject_type' => MemberSubjectType::Person->value,
            'subject_slug' => $person->slug,
        ])
        ->call('startMembershipApplication')
        ->assertRedirect(route('membership-applications.create', [
            'subjectType' => MemberSubjectType::Person->publicRouteSegment(),
            'subjectId' => $person->slug,
        ]));
});

it('shows membership claim call to action on unclaimed public institution and person pages', function () {
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);

    $institutionClaimUrl = route('membership-applications.create', [
        'subjectType' => MemberSubjectType::Institution->publicRouteSegment(),
        'subjectId' => $institution->getKey(),
    ]);
    $personClaimUrl = route('membership-applications.create', [
        'subjectType' => MemberSubjectType::Person->publicRouteSegment(),
        'subjectId' => $person->slug,
    ]);

    $this->get(route('institutions.show', $institution))
        ->assertSuccessful()
        ->assertSee($institutionClaimUrl, false)
        ->assertSeeInOrder(['Bantu Semak Institusi Ini', 'Tuntut Pengurusan']);

    $this->get(route('persons.show', $person))
        ->assertSuccessful()
        ->assertSee($personClaimUrl, false)
        ->assertSeeInOrder(['Bantu Semak Penceramah Ini', 'Tuntut Pengurusan']);
});

it('hides membership claim call to action when an institution already has an admin member', function () {
    $member = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);

    OwnerContext::withOwner(null, fn (): mixed => $institution->members()->syncWithoutDetaching([
        $member->getKey() => ['role' => MemberRole::Admin->value],
    ]));

    $institutionClaimUrl = route('membership-applications.create', [
        'subjectType' => MemberSubjectType::Institution->publicRouteSegment(),
        'subjectId' => $institution->getKey(),
    ]);

    $this->get(route('institutions.show', $institution))
        ->assertSuccessful()
        ->assertDontSee($institutionClaimUrl, false)
        ->assertDontSee('Tuntut Pengurusan');
});

it('keeps the person membership claim call to action visible while claims are unresolved', function () {
    foreach ([ApplicationStatus::Pending, ApplicationStatus::Rejected, ApplicationStatus::Cancelled] as $status) {
        $person = Person::factory()->create([
            'status' => 'verified',
        ]);

        OwnerContext::withOwner(null, function () use ($person, $status): void {
            MembershipApplication::factory()
                ->for($person, 'subject')
                ->create(['status' => $status]);
        });

        $personClaimUrl = route('membership-applications.create', [
            'subjectType' => MemberSubjectType::Person->publicRouteSegment(),
            'subjectId' => $person->slug,
        ]);

        $this->get(route('persons.show', $person))
            ->assertSuccessful()
            ->assertSee($personClaimUrl, false)
            ->assertSee('Tuntut Pengurusan');
    }
});

it('hides the person membership claim call to action when a speaker already has an admin member', function () {
    $member = User::factory()->create();
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);

    OwnerContext::withOwner(null, fn (): mixed => $person->members()->syncWithoutDetaching([
        $member->getKey() => ['role' => MemberRole::Admin->value],
    ]));

    $personClaimUrl = route('membership-applications.create', [
        'subjectType' => MemberSubjectType::Person->publicRouteSegment(),
        'subjectId' => $person->slug,
    ]);

    $this->get(route('persons.show', $person))
        ->assertSuccessful()
        ->assertDontSee($personClaimUrl, false)
        ->assertDontSee('Tuntut Pengurusan');
});

it('hides membership claim call to action when an institution or speaker already has an owner member', function () {
    $member = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);

    OwnerContext::withOwner(null, function () use ($institution, $person, $member): void {
        $institution->members()->syncWithoutDetaching([
            $member->getKey() => ['role' => MemberRole::Owner->value],
        ]);
        $person->members()->syncWithoutDetaching([
            $member->getKey() => ['role' => MemberRole::Owner->value],
        ]);
    });

    $institutionClaimUrl = route('membership-applications.create', [
        'subjectType' => MemberSubjectType::Institution->publicRouteSegment(),
        'subjectId' => $institution->getKey(),
    ]);
    $personClaimUrl = route('membership-applications.create', [
        'subjectType' => MemberSubjectType::Person->publicRouteSegment(),
        'subjectId' => $person->slug,
    ]);

    $this->get(route('institutions.show', $institution))
        ->assertSuccessful()
        ->assertDontSee($institutionClaimUrl, false)
        ->assertDontSee('Tuntut Pengurusan');

    $this->get(route('persons.show', $person))
        ->assertSuccessful()
        ->assertDontSee($personClaimUrl, false)
        ->assertDontSee('Tuntut Pengurusan');
});

it('keeps membership claim call to action visible to other visitors when a profile already has a member', function () {
    $member = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);

    OwnerContext::withOwner(null, function () use ($institution, $person, $member): void {
        $institution->members()->syncWithoutDetaching([
            $member->getKey() => ['role' => MemberRole::Viewer->value],
        ]);
        $person->members()->syncWithoutDetaching([
            $member->getKey() => ['role' => MemberRole::Viewer->value],
        ]);
    });

    $institutionClaimUrl = route('membership-applications.create', [
        'subjectType' => MemberSubjectType::Institution->publicRouteSegment(),
        'subjectId' => $institution->getKey(),
    ]);
    $personClaimUrl = route('membership-applications.create', [
        'subjectType' => MemberSubjectType::Person->publicRouteSegment(),
        'subjectId' => $person->slug,
    ]);

    $this->get(route('institutions.show', $institution))
        ->assertSuccessful()
        ->assertSee($institutionClaimUrl, false)
        ->assertSee('Tuntut Pengurusan');

    $this->get(route('persons.show', $person))
        ->assertSuccessful()
        ->assertSee($personClaimUrl, false)
        ->assertSee('Tuntut Pengurusan');
});
