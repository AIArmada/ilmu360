<?php

use AIArmada\Membership\Enums\ApplicationStatus;
use App\Features\Institutions\Models\Institution;
use App\Features\Membership\Api\MembershipApplications;
use App\Features\Membership\Api\MemberSubjectType;
use App\Features\Membership\EntryPoints\Filament\Resources\MembershipApplications\MembershipApplicationResource;
use App\Features\Membership\EntryPoints\Filament\Resources\MembershipApplications\Pages\ListMembershipApplications;
use App\Features\Membership\EntryPoints\Filament\Resources\MembershipApplications\Pages\ViewMembershipApplication;
use App\Features\Membership\EntryPoints\Filament\Resources\MembershipApplications\Support\MembershipApplicationAdminPresenter;
use App\Features\Membership\Models\MembershipApplication;
use App\Features\Membership\Services\MemberRoleCatalog;
use App\Features\Persons\Models\Person;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Forms\Components\Select;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Mockery\MockInterface;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(PermissionSeeder::class);
});

it('allows moderators to approve pending membership applications from the admin index', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $institution = Institution::factory()->create();
    $claimant = User::factory()->create();
    $claim = MembershipApplication::factory()
        ->for($institution, 'subject')
        ->create([
            'applicant_id' => $claimant->getKey(),
            'status' => ApplicationStatus::Pending,
        ]);

    Livewire::actingAs($moderator)
        ->test(ListMembershipApplications::class)
        ->assertCanSeeTableRecords([$claim])
        ->callTableAction('approve', $claim->getKey(), data: [
            'granted_role' => 'admin',
            'reviewer_note' => 'Approved as admin.',
        ])
        ->assertHasNoTableActionErrors();

    expect($claim->fresh()->status)->toBe(ApplicationStatus::Approved)
        ->and($claim->fresh()->granted_role)->toBe('admin')
        ->and($claim->fresh()->reviewer_id)->toBe($moderator->getKey())
        ->and($institution->fresh()->members()->whereKey($claimant->getKey())->exists())->toBeTrue()
        ->and(app(MemberRoleCatalog::class)->roleNamesFor($claimant->fresh(), MemberSubjectType::Institution))->toBe(['admin']);
});

it('allows moderators to approve speaker membership applications as owner from the admin view page', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $person = Person::factory()->create();
    $claimant = User::factory()->create();
    $claim = MembershipApplication::factory()
        ->for($person, 'subject')
        ->create([
            'applicant_id' => $claimant->getKey(),
            'status' => ApplicationStatus::Pending,
        ]);

    Livewire::actingAs($moderator)
        ->test(ViewMembershipApplication::class, ['record' => $claim->getKey()])
        ->callAction('approve', [
            'granted_role' => 'owner',
            'reviewer_note' => 'Approved as owner.',
        ])
        ->assertHasNoErrors();

    expect($claim->fresh()->status)->toBe(ApplicationStatus::Approved)
        ->and($claim->fresh()->granted_role)->toBe('owner')
        ->and($person->fresh()->members()->whereKey($claimant->getKey())->exists())->toBeTrue()
        ->and(app(MemberRoleCatalog::class)->roleNamesFor($claimant->fresh(), MemberSubjectType::Person))->toBe(['owner']);
});

it('offers the correct roles in both membership approval forms', function (string $subjectClass, array $allowedRoles, ?string $defaultRole) {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $claim = MembershipApplication::factory()
        ->for($subjectClass::factory(), 'subject')
        ->create([
            'status' => ApplicationStatus::Pending,
            'meta' => ['applied_role' => 'owner'],
        ]);

    $matchesAllowedRoles = fn (Select $field): bool => array_keys($field->getOptions()) === $allowedRoles;

    Livewire::actingAs($moderator)
        ->test(ListMembershipApplications::class)
        ->mountTableAction('approve', $claim->getKey())
        ->assertSchemaComponentExists('granted_role', checkComponentUsing: $matchesAllowedRoles)
        ->assertTableActionDataSet(['granted_role' => $defaultRole]);

    Livewire::actingAs($moderator)
        ->test(ViewMembershipApplication::class, ['record' => $claim->getKey()])
        ->mountAction('approve')
        ->assertSchemaComponentExists('granted_role', checkComponentUsing: $matchesAllowedRoles)
        ->assertSchemaStateSet(['granted_role' => $defaultRole]);
})->with([
    'institution' => [Institution::class, ['admin', 'editor'], null],
    'speaker' => [Person::class, ['owner', 'admin', 'editor'], 'owner'],
]);

it('allows moderators to reject pending membership applications from the admin view page', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $institution = Institution::factory()->create();
    $claim = MembershipApplication::factory()
        ->for($institution, 'subject')
        ->create([
            'status' => ApplicationStatus::Pending,
        ]);

    Livewire::actingAs($moderator)
        ->test(ViewMembershipApplication::class, ['record' => $claim->getKey()])
        ->callAction('reject', [
            'reviewer_note' => 'Need stronger evidence.',
        ])
        ->assertHasNoErrors();

    expect($claim->fresh()->status)->toBe(ApplicationStatus::Rejected)
        ->and($claim->fresh()->reviewer_id)->toBe($moderator->getKey())
        ->and($claim->fresh()->reviewer_note)->toBe('Need stronger evidence.');
});

it('shows membership application subjects on the admin index and links to the view page', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $institution = Institution::factory()->create([
        'name' => 'Institusi Untuk Tuntutan',
    ]);
    $claim = MembershipApplication::factory()
        ->for($institution, 'subject')
        ->create();

    $this->actingAs($administrator)
        ->get(MembershipApplicationResource::getUrl('index'))
        ->assertSuccessful()
        ->assertSee('Institusi Untuk Tuntutan')
        ->assertSee(MembershipApplicationResource::getUrl('view', ['record' => $claim]), false);
});

it('shows applicant notes on the membership application view page', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $institution = Institution::factory()->create();
    $claim = MembershipApplication::factory()
        ->for($institution, 'subject')
        ->create([
            'meta' => [
                'relationship' => 'committee_member',
                'notes' => 'Saya menguruskan aktiviti institusi ini.',
            ],
        ]);

    $this->actingAs($administrator)
        ->get(MembershipApplicationResource::getUrl('view', ['record' => $claim]))
        ->assertSuccessful()
        ->assertSee('Applicant Note')
        ->assertSee('Ahli Jawatan Kuasa')
        ->assertSee('Saya menguruskan aktiviti institusi ini.');
});

it('discards selected membership applications through the owned feature action', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $institution = Institution::factory()->create();
    $first = MembershipApplication::factory()->for($institution, 'subject')->create();
    $second = MembershipApplication::factory()->for($institution, 'subject')->create();

    $first->addMedia(UploadedFile::fake()->image('proof.png'))->toMediaCollection('evidence');
    $mediaKey = (string) $first->getFirstMedia('evidence')?->getKey();

    expect($mediaKey)->not->toBeEmpty();

    Livewire::actingAs($administrator)
        ->test(ListMembershipApplications::class)
        ->assertCanSeeTableRecords([$first, $second])
        ->callTableBulkAction('discard', [$first->getKey(), $second->getKey()])
        ->assertNotified();

    expect(MembershipApplication::query()->whereKey([$first->getKey(), $second->getKey()])->count())->toBe(0)
        ->and(Media::query()->whereKey($mediaKey)->exists())->toBeFalse();
});

it('denies bulk discard of membership applications to moderators', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $institution = Institution::factory()->create();
    $claim = MembershipApplication::factory()->for($institution, 'subject')->create();

    Livewire::actingAs($moderator)
        ->test(ListMembershipApplications::class)
        ->callTableBulkAction('discard', [$claim->getKey()]);

    expect(MembershipApplication::query()->whereKey($claim->getKey())->exists())->toBeTrue();
});

it('shows a danger notification when approving an application that was already processed', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $institution = Institution::factory()->create();
    $claim = MembershipApplication::factory()
        ->for($institution, 'subject')
        ->create(['status' => ApplicationStatus::Pending]);

    $this->mock(MembershipApplications::class, function (MockInterface $mock): void {
        $mock->shouldReceive('approve')
            ->once()
            ->andThrow(new RuntimeException('Only pending membership applications can be approved.'));
    });

    Livewire::actingAs($moderator)
        ->test(ViewMembershipApplication::class, ['record' => $claim->getKey()])
        ->callAction('approve', [
            'granted_role' => 'admin',
            'reviewer_note' => 'Stale approval attempt.',
        ])
        ->assertNotified('Only pending membership applications can be approved.')
        ->assertRedirect(MembershipApplicationResource::getUrl('view', ['record' => $claim]));

    expect($claim->fresh()->status)->toBe(ApplicationStatus::Pending)
        ->and($claim->fresh()->granted_role)->toBeNull()
        ->and($institution->fresh()->members()->whereKey($claim->fresh()->applicant_id)->exists())->toBeFalse();
});

it('shows a danger notification when rejecting an application that was already processed', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $institution = Institution::factory()->create();
    $claim = MembershipApplication::factory()
        ->for($institution, 'subject')
        ->create(['status' => ApplicationStatus::Pending]);

    $this->mock(MembershipApplications::class, function (MockInterface $mock): void {
        $mock->shouldReceive('reject')
            ->once()
            ->andThrow(new RuntimeException('Only pending membership applications can be rejected.'));
    });

    Livewire::actingAs($moderator)
        ->test(ViewMembershipApplication::class, ['record' => $claim->getKey()])
        ->callAction('reject', [
            'reviewer_note' => 'Stale rejection attempt.',
        ])
        ->assertNotified('Only pending membership applications can be rejected.')
        ->assertRedirect(MembershipApplicationResource::getUrl('view', ['record' => $claim]));

    expect($claim->fresh()->status)->toBe(ApplicationStatus::Pending)
        ->and($claim->fresh()->reviewer_note)->toBeNull();
});

it('defaults the membership application list to the pending tab and switches status tabs', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $institution = Institution::factory()->create();

    $pending = MembershipApplication::factory()
        ->for($institution, 'subject')
        ->create(['status' => ApplicationStatus::Pending]);
    $approved = MembershipApplication::factory()
        ->for($institution, 'subject')
        ->approved()
        ->create();
    $rejected = MembershipApplication::factory()
        ->for($institution, 'subject')
        ->rejected()
        ->create();
    $cancelled = MembershipApplication::factory()
        ->for($institution, 'subject')
        ->create([
            'status' => ApplicationStatus::Cancelled,
            'cancelled_at' => now(),
        ]);

    $component = Livewire::actingAs($moderator)->test(ListMembershipApplications::class);

    expect(array_keys($component->instance()->getTabs()))
        ->toBe(['pending', 'approved', 'rejected', 'cancelled', 'all'])
        ->and($component->instance()->getDefaultActiveTab())->toBe(ApplicationStatus::Pending->value);

    $component
        ->assertSet('activeTab', ApplicationStatus::Pending->value)
        ->assertCanSeeTableRecords([$pending])
        ->assertCanNotSeeTableRecords([$approved, $rejected, $cancelled])
        ->set('activeTab', ApplicationStatus::Approved->value)
        ->assertCanSeeTableRecords([$approved])
        ->assertCanNotSeeTableRecords([$pending, $rejected, $cancelled])
        ->set('activeTab', ApplicationStatus::Rejected->value)
        ->assertCanSeeTableRecords([$rejected])
        ->assertCanNotSeeTableRecords([$pending, $approved, $cancelled])
        ->set('activeTab', ApplicationStatus::Cancelled->value)
        ->assertCanSeeTableRecords([$cancelled])
        ->assertCanNotSeeTableRecords([$pending, $approved, $rejected])
        ->set('activeTab', 'all')
        ->assertCanSeeTableRecords([$pending, $approved, $rejected, $cancelled]);
});

it('links membership application evidence to the original file instead of the cropped thumbnail', function () {
    $institution = Institution::factory()->create();
    $claim = MembershipApplication::factory()->for($institution, 'subject')->create();

    $claim->addMedia(UploadedFile::fake()->image('proof.png'))->toMediaCollection('evidence');

    $media = $claim->getFirstMedia('evidence');

    expect($media)->not->toBeNull();

    $html = (string) MembershipApplicationAdminPresenter::evidenceLinks($claim);

    expect($html)
        ->toContain((string) $media?->getUrl())
        ->not->toContain('-thumb.webp');
});
