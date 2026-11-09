<?php

use App\Models\MembershipApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('restores the membership application applicant and reviewer attribution', function (): void {
    $user = User::factory()->create();
    $application = MembershipApplication::factory()->create([
        'applicant_id' => $user->id,
        'reviewer_id' => $user->id,
    ]);

    $user->delete();

    expect($application->fresh()->applicant_id)->toBeNull()
        ->and($application->fresh()->reviewer_id)->toBeNull();

    User::restoreDeletedUser($user->id);

    expect($application->fresh()->applicant_id)->toBe($user->id)
        ->and($application->fresh()->reviewer_id)->toBe($user->id);
});

it('does not overwrite applicant attribution reassigned during deletion', function (): void {
    $user = User::factory()->create();
    $replacement = User::factory()->create();
    $application = MembershipApplication::factory()->create([
        'applicant_id' => $user->id,
        'reviewer_id' => $user->id,
    ]);

    $user->delete();

    $application->forceFill([
        'applicant_id' => $replacement->id,
        'reviewer_id' => $replacement->id,
    ])->saveQuietly();

    User::restoreDeletedUser($user->id);

    expect($application->fresh()->applicant_id)->toBe($replacement->id)
        ->and($application->fresh()->reviewer_id)->toBe($replacement->id);
});
