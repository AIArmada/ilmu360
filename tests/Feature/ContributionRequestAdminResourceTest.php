<?php

use App\Enums\ContributionRequestStatus;
use App\Enums\ContributionRequestType;
use App\Enums\ContributionSubjectType;
use App\Filament\Resources\ContributionRequests\ContributionRequestResource;
use App\Filament\Resources\ContributionRequests\Pages\ListContributionRequests;
use App\Filament\Resources\ContributionRequests\Pages\ViewContributionRequest;
use App\Models\ContributionRequest;
use App\Models\Institution;
use App\Models\Speaker;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

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

it('allows moderators to reject pending staged create requests from the admin resource view page', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $speaker = Speaker::factory()->create([
        'name' => 'Pending Speaker',
        'status' => 'pending',
        'is_active' => true,
    ]);
    $request = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Create,
        'subject_type' => ContributionSubjectType::Speaker,
        'entity_type' => $speaker->getMorphClass(),
        'entity_id' => $speaker->id,
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => [
            'name' => $speaker->name,
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
        ->and($speaker->fresh()->status)->toBe('rejected')
        ->and($speaker->fresh()->is_active)->toBeFalse();
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
