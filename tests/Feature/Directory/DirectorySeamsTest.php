<?php

use AIArmada\Membership\Enums\MemberRole;
use App\Models\Institution;
use App\Models\Person;
use App\Models\User;
use App\Shared\Directory\DirectoryCatalog;
use App\Shared\Directory\SubmissionEligibilitySync;
use App\Support\Directory\EloquentDirectoryCatalog;
use App\Support\Submission\PublicSubmissionEligibilitySync;

it('binds the directory seams to their interim adapters', function (): void {
    expect(app(DirectoryCatalog::class))->toBeInstanceOf(EloquentDirectoryCatalog::class)
        ->and(app(SubmissionEligibilitySync::class))->toBeInstanceOf(PublicSubmissionEligibilitySync::class);
});

it('lists current directory records as scalar options', function (): void {
    $institution = Institution::factory()->create(['name' => 'Masjid Ujian']);
    $person = Person::factory()->create(['name' => 'Ahmad Ujian']);

    $catalog = app(DirectoryCatalog::class);
    $institutionId = (string) $institution->getKey();
    $personId = (string) $person->getKey();

    $institutions = collect($catalog->institutions())->keyBy('id');
    $persons = collect($catalog->persons())->keyBy('id');

    expect($institutions->has($institutionId))->toBeTrue()
        ->and($institutions->get($institutionId)?->label)->toBe('Masjid Ujian')
        ->and($persons->has($personId))->toBeTrue()
        ->and($persons->get($personId)?->label)->toBe('Ahmad Ujian');
});

it('syncs locked directory entities for the account when eligibility fails', function (): void {
    $user = User::factory()->create();
    $institution = Institution::factory()->create();
    $person = Person::factory()->create();

    addTestMember($institution, $user, MemberRole::Viewer);
    addTestMember($person, $user, MemberRole::Viewer);

    $institution->forceFill(['allow_public_event_submission' => false])->save();
    $person->forceFill(['allow_public_event_submission' => false])->save();

    app(SubmissionEligibilitySync::class)->syncForAccount((string) $user->getKey());

    expect($institution->fresh()?->allow_public_event_submission)->toBeTrue()
        ->and($person->fresh()?->allow_public_event_submission)->toBeTrue();
});

it('keeps a deliberately locked institution locked while eligibility holds', function (): void {
    $owner = User::factory()->create([
        'phone' => '+60123456789',
        'phone_verified_at' => now(),
    ]);
    $institution = Institution::factory()->create();

    addTestMember($institution, $owner, MemberRole::Owner);

    $institution->forceFill(['allow_public_event_submission' => false])->save();

    app(SubmissionEligibilitySync::class)->syncInstitution((string) $institution->getKey());

    expect($institution->fresh()?->allow_public_event_submission)->toBeFalse();
});
