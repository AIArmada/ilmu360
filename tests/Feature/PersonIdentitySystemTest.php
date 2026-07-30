<?php

use AIArmada\Persons\Enums\AffiliationType;
use AIArmada\Persons\Enums\AssignmentStatus;
use AIArmada\Persons\Enums\CredentialType;
use AIArmada\Persons\Enums\TitleUsagePosition;
use AIArmada\Persons\Models\AffiliationRole;
use AIArmada\Persons\Models\CredentialAssignment;
use AIArmada\Persons\Models\CredentialDefinition;
use AIArmada\Persons\Models\Title;
use AIArmada\Persons\Models\TitleAssignment;
use AIArmada\Persons\Models\TitleCategory;
use App\Models\Affiliation;
use App\Models\Institution;
use App\Models\Person;

it('formatted_name falls back to the bare name when no titles are assigned', function () {
    $person = Person::factory()->create([
        'name' => 'Ahmad Fauzi',
    ]);

    expect($person->formatted_name)->toBe('Ahmad Fauzi');
});

it('formats the family name after the given name and before post-nominal titles', function () {
    $person = Person::factory()->create([
        'name' => 'Ahmad',
        'family_name' => 'Rahman',
    ]);

    expect($person->formatted_name)->toBe('Ahmad Rahman');
});

it('formats the middle name between the given and family names', function () {
    $person = Person::factory()->create([
        'name' => 'Ahmad',
        'middle_name' => 'Fauzi',
        'family_name' => 'Rahman',
    ]);

    expect($person->formatted_name)->toBe('Ahmad Fauzi Rahman');
});

it('assigns a title to a person and returns it through the titleAssignments relationship', function () {
    $person = Person::factory()->create([
        'name' => 'Ahmad Fauzi',
    ]);

    $title = Title::query()->create([
        'category_id' => TitleCategory::query()->where('code', 'academic')->firstOrFail()->id,
        'name' => 'Professor',
        'short_form' => 'Prof.',
        'usage_position' => TitleUsagePosition::BeforeName,
        'sort_order' => 1,
    ]);

    TitleAssignment::query()->create([
        'titleable_type' => $person->getMorphClass(),
        'titleable_id' => $person->getKey(),
        'title_id' => $title->getKey(),
        'status' => AssignmentStatus::Active,
        'date_awarded' => now(),
    ]);

    expect($person->fresh()->titleAssignments)->toHaveCount(1)
        ->and($person->fresh()->titleAssignments->first()->title->name)->toBe('Professor');
});

it('assigns a credential to a person and returns it through the credentialAssignments relationship', function () {
    $person = Person::factory()->create([
        'name' => 'Ahmad Fauzi',
    ]);

    $credential = CredentialDefinition::query()->create([
        'name' => 'Bachelor of Islamic Studies',
        'short_form' => 'B.Isl.St.',
        'field' => 'Islamic Studies',
        'credential_type' => CredentialType::AcademicDegree->value,
    ]);

    CredentialAssignment::query()->create([
        'credentialable_type' => $person->getMorphClass(),
        'credentialable_id' => $person->getKey(),
        'credential_id' => $credential->getKey(),
        'date_obtained' => now(),
    ]);

    expect($person->fresh()->credentialAssignments)->toHaveCount(1)
        ->and($person->fresh()->credentialAssignments->first()->credential->name)->toBe('Bachelor of Islamic Studies');
});

it('creates an affiliation with an institution and assigns multiple roles', function () {
    $person = Person::factory()->create([
        'name' => 'Ahmad Fauzi',
    ]);

    $institution = Institution::factory()->create([
        'name' => 'Darul Hikmah College',
    ]);

    $affiliation = Affiliation::query()->create([
        'affiliatable_type' => $person->getMorphClass(),
        'affiliatable_id' => $person->getKey(),
        'institution_id' => $institution->getKey(),
        'affiliation_type' => AffiliationType::Employee,
        'joined_at' => now(),
        'is_primary' => true,
    ]);

    AffiliationRole::query()->create([
        'affiliation_id' => $affiliation->getKey(),
        'role_name' => 'Lecturer',
    ]);

    AffiliationRole::query()->create([
        'affiliation_id' => $affiliation->getKey(),
        'role_name' => 'Researcher',
    ]);

    $freshPerson = $person->fresh(['affiliations.roles']);

    expect($freshPerson->affiliations)->toHaveCount(1)
        ->and($freshPerson->affiliations->first()->institution_id)->toBe((string) $institution->getKey())
        ->and($freshPerson->affiliations->first()->roles)->toHaveCount(2)
        ->and($freshPerson->affiliations->first()->roles->pluck('role_name')->all())->toContain('Lecturer', 'Researcher')
        ->and($freshPerson->affiliations->first()->is_primary)->toBeTrue();
});

it('tracks the formatted_name on the searchable payload', function () {
    $person = Person::factory()->create([
        'name' => 'Ahmad Fauzi',
    ]);

    expect($person->toSearchableArray()['formatted_name'] ?? null)->toBe('Ahmad Fauzi');
});
