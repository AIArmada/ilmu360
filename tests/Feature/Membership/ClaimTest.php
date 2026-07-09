<?php

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Membership\Enums\ApplicationStatus;
use AIArmada\Membership\Models\MembershipApplication;
use App\Models\Institution;
use App\Models\User;

it('writes to membership_applications when applying via package action', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);

    OwnerContext::withOwner(null, function () use ($user, $institution): void {
        MembershipApplication::query()->create([
            'subject_type' => $institution->getMorphClass(),
            'subject_id' => $institution->getKey(),
            'applicant_id' => $user->getKey(),
            'status' => ApplicationStatus::Pending,
            'justification' => 'I want to join this institution.',
        ]);
    });

    expect(MembershipApplication::query()
        ->where('subject_type', $institution->getMorphClass())
        ->where('subject_id', $institution->getKey())
        ->where('applicant_id', $user->getKey())
        ->exists()
    )->toBeTrue();
});

it('can retrieve applicant and subject relations on package model', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);

    OwnerContext::withOwner(null, function () use ($user, $institution): void {
        MembershipApplication::query()->create([
            'subject_type' => $institution->getMorphClass(),
            'subject_id' => $institution->getKey(),
            'applicant_id' => $user->getKey(),
            'status' => ApplicationStatus::Pending,
            'justification' => 'I want to join this institution.',
        ]);
    });

    $application = MembershipApplication::query()
        ->where('subject_type', $institution->getMorphClass())
        ->where('subject_id', $institution->getKey())
        ->where('applicant_id', $user->getKey())
        ->first();

    expect($application)->not->toBeNull();
    expect($application->applicant)->not->toBeNull();
});
