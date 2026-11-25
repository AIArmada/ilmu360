<?php

use App\Features\Contributions\Actions\RejectContributionRequestAction;
use App\Features\Contributions\Api\ContributionRequestLifecycle;
use App\Features\Contributions\Api\Enums\ContributionSubjectType;
use App\Features\Contributions\EntryPoints\Filament\Resources\ContributionRequests\ContributionRequestResource;
use App\Features\Contributions\EntryPoints\Filament\Resources\ContributionRequests\Pages\ListContributionRequests;
use App\Features\Contributions\EntryPoints\Filament\Resources\ContributionRequests\Pages\ViewContributionRequest;
use App\Features\Contributions\EntryPoints\Ui\ContributionsHubPresenter;
use App\Features\Contributions\Enums\ContributionRequestStatus;
use App\Features\Contributions\Enums\ContributionRequestType;
use App\Features\Contributions\Exceptions\ContributionRequestNotPendingException;
use App\Features\Contributions\Models\ContributionRequest;
use App\Features\Institutions\Api\Enums\InstitutionStatus;
use App\Features\Institutions\Models\Institution;
use App\Features\Persons\Models\Person;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Mockery\MockInterface;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(PermissionSeeder::class);
});

it('allows moderators to approve pending contribution requests from the admin resource', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $institution = Institution::factory()->create([
        'description' => 'Before review',
        'status' => 'verified',
    ]);
    $request = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Update,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => $institution->getMorphClass(),
        'entity_id' => $institution->id,
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => [
            'description' => 'After review',
        ],
        'original_data' => [
            'description' => 'Before review',
        ],
    ]);

    Livewire::actingAs($moderator)
        ->test(ListContributionRequests::class)
        ->assertCanSeeTableRecords([$request])
        ->callTableAction('approve', $request->getKey(), data: [
            'reviewer_note' => 'Looks accurate.',
        ])
        ->assertHasNoTableActionErrors();

    expect($request->fresh()->status)->toBe(ContributionRequestStatus::Approved)
        ->and($request->fresh()->reviewer_id)->toBe($moderator->id)
        ->and($request->fresh()->reviewer_note)->toBe('Looks accurate.')
        ->and($institution->fresh()->description)->toBe('After review');
});

it('shows a danger notification when approving a conflicting request from the admin table', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $geography = createTestPackageGeography(cityName: 'Shah Alam');
    $locality = [
        'country_id' => (string) $geography['country']->getKey(),
        'state_id' => (string) $geography['state']->getKey(),
        'city_id' => (string) $geography['city']->getKey(),
    ];

    $stage = function (string $name) use ($locality): ContributionRequest {
        $institution = Institution::factory()->create(['name' => $name, 'status' => InstitutionStatus::Pending->value]);
        attachTestInstitutionAddress($institution, $locality + ['google_place_id' => 'admin_contested_place']);

        return ContributionRequest::factory()->create([
            'type' => ContributionRequestType::Create,
            'subject_type' => ContributionSubjectType::Institution,
            'entity_type' => Institution::class,
            'entity_id' => (string) $institution->getKey(),
            'status' => ContributionRequestStatus::Pending,
            'proposed_data' => ['name' => $name, 'address' => $locality + ['google_place_id' => 'admin_contested_place']],
        ]);
    };

    $first = $stage('First Mosque');
    $stage('Second Mosque');

    Livewire::actingAs($moderator)
        ->test(ListContributionRequests::class)
        ->callTableAction('approve', $first->getKey(), data: [
            'reviewer_note' => 'Looks accurate.',
        ])
        ->assertNotified(Notification::make()->danger()->title(
            __('Cannot approve this request because another institution or pending submission already claims the same Google Maps place or name and locality. Reject the conflicting submission first.')
        ));

    expect($first->fresh()->status)->toBe(ContributionRequestStatus::Pending);
});

it('allows moderators to reject pending staged create requests from the admin resource view page', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $person = Person::factory()->create([
        'name' => 'Pending Person',
        'status' => 'pending',
    ]);
    $request = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Create,
        'subject_type' => ContributionSubjectType::Person,
        'entity_type' => $person->getMorphClass(),
        'entity_id' => $person->id,
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => [
            'name' => $person->name,
            'gender' => 'female',
        ],
    ]);

    Livewire::actingAs($moderator)
        ->test(ViewContributionRequest::class, ['record' => $request->getKey()])
        ->callAction('reject', [
            'reason_code' => 'needs_more_evidence',
            'reviewer_note' => 'Need stronger sourcing.',
        ])
        ->assertHasNoErrors();

    expect($request->fresh()->status)->toBe(ContributionRequestStatus::Rejected)
        ->and($request->fresh()->reviewer_id)->toBe($moderator->id)
        ->and($request->fresh()->reason_code)->toBe('needs_more_evidence')
        ->and($person->fresh()->status)->toBe('rejected')
        ->and((string) $person->fresh()->status)->toBeIn(['inactive', 'rejected']);
});

it('opens contribution request records on the admin view page from the index', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $request = ContributionRequest::factory()->create();

    Livewire::actingAs($administrator)
        ->test(ListContributionRequests::class)
        ->assertCanSeeTableRecords([$request])
        ->assertTableActionExists(
            'view',
            fn ($action): bool => $action->getUrl() === ContributionRequestResource::getUrl('view', ['record' => $request]),
            $request,
        );
});

it('renders a record name breadcrumb and a structured payload on the view page', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $institution = Institution::factory()->create(['name' => 'Sekolah Al-Falah']);
    $request = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Update,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => $institution->getMorphClass(),
        'entity_id' => $institution->id,
        'proposed_data' => [
            'description' => 'Updated description',
            'city' => 'Kuala Lumpur',
        ],
        'original_data' => [
            'description' => 'Old description',
            'city' => 'Shah Alam',
        ],
    ]);

    Livewire::actingAs($administrator)
        ->test(ViewContributionRequest::class, ['record' => $request->getKey()])
        ->assertSee('Sekolah Al-Falah')
        ->assertSee(__('Description'))
        ->assertSee('Updated description')
        ->assertSee('Old description');
});

it('shows a busy notice when the Maps identity lock is held during table approval', function () {
    config()->set('moderation.review_lock_block_seconds', 1);
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $geography = createTestPackageGeography(cityName: 'Shah Alam');
    $locality = [
        'country_id' => (string) $geography['country']->getKey(),
        'state_id' => (string) $geography['state']->getKey(),
        'city_id' => (string) $geography['city']->getKey(),
    ];

    $institution = Institution::factory()->create(['name' => 'Busy Lock Mosque', 'status' => InstitutionStatus::Pending->value]);
    attachTestInstitutionAddress($institution, $locality + ['google_place_id' => 'busy_lock_place']);

    $request = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Create,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => Institution::class,
        'entity_id' => (string) $institution->getKey(),
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => ['name' => 'Busy Lock Mosque', 'address' => $locality + ['google_place_id' => 'busy_lock_place']],
    ]);

    $lock = Cache::lock('contribution-create:institution-maps:'.hash('sha256', 'place:busy_lock_place'), 30);
    expect($lock->acquire())->toBeTrue();

    try {
        Livewire::actingAs($moderator)
            ->test(ListContributionRequests::class)
            ->callTableAction('approve', $request->getKey(), data: [
                'reviewer_note' => 'Looks accurate.',
            ])
            ->assertNotified(__('The review service is busy. Please try again in a moment.'));
    } finally {
        $lock->release();
    }

    expect($request->fresh()->status)->toBe(ContributionRequestStatus::Pending);
});

it('shows a danger notification when approving a conflicting request from the admin view page', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $geography = createTestPackageGeography(cityName: 'Shah Alam');
    $locality = [
        'country_id' => (string) $geography['country']->getKey(),
        'state_id' => (string) $geography['state']->getKey(),
        'city_id' => (string) $geography['city']->getKey(),
    ];

    $stage = function (string $name) use ($locality): ContributionRequest {
        $institution = Institution::factory()->create(['name' => $name, 'status' => InstitutionStatus::Pending->value]);
        attachTestInstitutionAddress($institution, $locality + ['google_place_id' => 'admin_view_contested_place']);

        return ContributionRequest::factory()->create([
            'type' => ContributionRequestType::Create,
            'subject_type' => ContributionSubjectType::Institution,
            'entity_type' => Institution::class,
            'entity_id' => (string) $institution->getKey(),
            'status' => ContributionRequestStatus::Pending,
            'proposed_data' => ['name' => $name, 'address' => $locality + ['google_place_id' => 'admin_view_contested_place']],
        ]);
    };

    $first = $stage('First Mosque');
    $stage('Second Mosque');

    Livewire::actingAs($moderator)
        ->test(ViewContributionRequest::class, ['record' => $first->getKey()])
        ->callAction('approve', [
            'reviewer_note' => 'Looks accurate.',
        ])
        ->assertNotified(
            Notification::make()->danger()->title(__('Cannot approve this request because another institution or pending submission already claims the same Google Maps place or name and locality. Reject the conflicting submission first.'))
        );

    expect($first->fresh()->status)->toBe(ContributionRequestStatus::Pending);
});

it('bulk deletes contribution requests through the lifecycle boundary for super admins', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $first = ContributionRequest::factory()->create();
    $second = ContributionRequest::factory()->create();

    Livewire::actingAs($administrator)
        ->test(ListContributionRequests::class)
        ->assertCanSeeTableRecords([$first, $second])
        ->callTableBulkAction('delete', [$first->getKey(), $second->getKey()])
        ->assertNotified();

    $this->assertModelMissing($first);
    $this->assertModelMissing($second);
});

it('defaults the contribution request list to the pending tab and switches status tabs', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $pending = ContributionRequest::factory()->create(['status' => ContributionRequestStatus::Pending]);
    $approved = ContributionRequest::factory()->create(['status' => ContributionRequestStatus::Approved]);

    $component = Livewire::actingAs($moderator)->test(ListContributionRequests::class);

    expect(array_keys($component->instance()->getTabs()))
        ->toBe(['pending', 'approved', 'rejected', 'cancelled', 'all'])
        ->and($component->instance()->getDefaultActiveTab())->toBe('pending');

    $component
        ->assertSet('activeTab', 'pending')
        ->assertCanSeeTableRecords([$pending])
        ->assertCanNotSeeTableRecords([$approved])
        ->set('activeTab', 'approved')
        ->assertCanSeeTableRecords([$approved])
        ->assertCanNotSeeTableRecords([$pending]);
});

it('searches contribution requests by record title, proposer name and proposer email', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $proposer = User::factory()->create([
        'name' => 'Searchable Proposer',
        'email' => 'searchable.proposer@example.test',
    ]);
    $institution = Institution::factory()->create(['name' => 'Masjid Carian Unik']);
    $request = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Update,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => $institution->getMorphClass(),
        'entity_id' => $institution->id,
        'proposer_id' => $proposer->id,
        'status' => ContributionRequestStatus::Pending,
    ]);
    $other = ContributionRequest::factory()->create();

    $component = Livewire::actingAs($moderator)->test(ListContributionRequests::class);

    $component->searchTable('Masjid Carian Unik')
        ->assertCanSeeTableRecords([$request])
        ->assertCanNotSeeTableRecords([$other]);

    $component->searchTable('Searchable Proposer')
        ->assertCanSeeTableRecords([$request])
        ->assertCanNotSeeTableRecords([$other]);

    $component->searchTable('searchable.proposer@example.test')
        ->assertCanSeeTableRecords([$request])
        ->assertCanNotSeeTableRecords([$other]);
});

it('shows a changes summary for update requests in the table', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $institution = Institution::factory()->create(['name' => 'Ringkasan Masjid']);
    $request = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Update,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => $institution->getMorphClass(),
        'entity_id' => $institution->id,
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => ['description' => 'Updated description', 'city' => 'Kuala Lumpur'],
        'original_data' => ['description' => 'Old description', 'city' => 'Shah Alam'],
    ]);

    $summary = app(ContributionsHubPresenter::class)->requestSummaryLine($request);

    expect($summary)->not->toBeNull();

    Livewire::actingAs($moderator)
        ->test(ListContributionRequests::class)
        ->assertTableColumnExists('changes_summary')
        ->assertTableColumnStateSet('changes_summary', $summary, $request);
});

it('hides approve and reject when the proposer reviews their own update request', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $request = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Update,
        'proposer_id' => $moderator->id,
        'status' => ContributionRequestStatus::Pending,
    ]);

    Livewire::actingAs($moderator)
        ->test(ListContributionRequests::class)
        ->assertTableActionHidden('approve', $request)
        ->assertTableActionHidden('reject', $request);
});

it('shows a self review notice on the view page for the proposer own update request', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $request = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Update,
        'proposer_id' => $moderator->id,
        'status' => ContributionRequestStatus::Pending,
    ]);

    Livewire::actingAs($moderator)
        ->test(ViewContributionRequest::class, ['record' => $request->getKey()])
        ->assertSee(__('This request was submitted by you. A different reviewer must review it before it can be approved or rejected.'))
        ->assertActionHidden('approve')
        ->assertActionHidden('reject');
});

it('allows admin role users to review contribution requests', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('admin');

    $institution = Institution::factory()->create([
        'description' => 'Before review',
        'status' => 'verified',
    ]);
    $request = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Update,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => $institution->getMorphClass(),
        'entity_id' => $institution->id,
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => ['description' => 'Admin approved description'],
        'original_data' => ['description' => 'Before review'],
    ]);

    Livewire::actingAs($administrator)
        ->test(ListContributionRequests::class)
        ->assertCanSeeTableRecords([$request])
        ->callTableAction('approve', $request->getKey(), data: [
            'reviewer_note' => 'Approved by admin.',
        ])
        ->assertHasNoTableActionErrors();

    expect($request->fresh()->status)->toBe(ContributionRequestStatus::Approved)
        ->and($institution->fresh()->description)->toBe('Admin approved description');
});

it('throws an authorization exception when the proposer rejects their own update request', function () {
    $proposer = User::factory()->create();
    $request = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Update,
        'proposer_id' => $proposer->id,
        'status' => ContributionRequestStatus::Pending,
    ]);

    expect(fn () => RejectContributionRequestAction::run($request, $proposer, 'other'))
        ->toThrow(AuthorizationException::class, __('A different reviewer must review this update.'));

    expect($request->fresh()->status)->toBe(ContributionRequestStatus::Pending);
});

it('shows a danger notification when approving an update whose target record no longer exists', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $person = Person::factory()->create(['status' => 'verified']);
    $request = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Update,
        'subject_type' => ContributionSubjectType::Person,
        'entity_type' => $person->getMorphClass(),
        'entity_id' => $person->id,
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => ['name' => 'Updated name'],
        'original_data' => ['name' => $person->name],
    ]);

    $person->delete();

    Livewire::actingAs($moderator)
        ->test(ListContributionRequests::class)
        ->callTableAction('approve', $request->getKey(), data: [
            'reviewer_note' => 'Looks accurate.',
        ])
        ->assertNotified(Notification::make()->danger()->title(__('The target record for this update request no longer exists.')));

    expect($request->fresh()->status)->toBe(ContributionRequestStatus::Pending);
});

it('shows a danger notification when rejecting a request that is no longer pending', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $request = ContributionRequest::factory()->create(['status' => ContributionRequestStatus::Pending]);

    $this->mock(ContributionRequestLifecycle::class, function (MockInterface $mock): void {
        $mock->shouldReceive('reject')
            ->once()
            ->andThrow(new ContributionRequestNotPendingException('Request is no longer pending.'));
    });

    Livewire::actingAs($moderator)
        ->test(ViewContributionRequest::class, ['record' => $request->getKey()])
        ->callAction('reject', [
            'reason_code' => 'other',
            'reviewer_note' => 'Stale review attempt.',
        ])
        ->assertNotified(__('This request is no longer pending review. Please refresh to see its current status.'))
        ->assertRedirect(ContributionRequestResource::getUrl('view', ['record' => $request]));

    expect($request->fresh()->status)->toBe(ContributionRequestStatus::Pending);
});
