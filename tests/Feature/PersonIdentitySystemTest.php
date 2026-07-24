<?php

use App\Enums\AffiliationType;
use App\Enums\AssignmentStatus;
use App\Enums\TitleUsagePosition;
use App\Models\Affiliation;
use App\Models\AffiliationRole;
use App\Models\CredentialAssignment;
use App\Models\CredentialDefinition;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Title;
use App\Models\TitleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('sets formatted_name from name when no titles are assigned', function () {
    $person = Person::factory()->create([
        'name' => 'Ahmad Fauzi',
    ]);

    $expected = Person::formatDisplayedName('Ahmad Fauzi', [], [], []);

    expect($expected)->toBe('Ahmad Fauzi');
});

it('includes honorific and pre-nominal and post-nominal in formatted_name', function () {
    $expected = Person::formatDisplayedName('Azhar Sulaiman', ['dato'], ['dr', 'ustaz'], ['PhD', 'BA']);

    expect($expected)->toBe("Dato' Ustaz Dr Azhar Sulaiman, PhD, BA");
});

it('assigns a title to a person and returns it through the titleAssignments relationship', function () {
    $person = Person::factory()->create([
        'name' => 'Ahmad Fauzi',
    ]);

    $title = Title::query()->create([
        'name' => 'Professor',
        'short_form' => 'Prof.',
        'usage_position' => TitleUsagePosition::Prefix,
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
        'role' => 'Lecturer',
    ]);

    AffiliationRole::query()->create([
        'affiliation_id' => $affiliation->getKey(),
        'role' => 'Researcher',
    ]);

    $freshPerson = $person->fresh(['affiliations.roles']);

    expect($freshPerson->affiliations)->toHaveCount(1)
        ->and($freshPerson->affiliations->first()->institution_id)->toBe((string) $institution->getKey())
        ->and($freshPerson->affiliations->first()->roles)->toHaveCount(2)
        ->and($freshPerson->affiliations->first()->roles->pluck('role')->all())->toContain('Lecturer', 'Researcher')
        ->and($freshPerson->affiliations->first()->is_primary)->toBeTrue();
});

it('tracks the formatted_name on the searchable payload', function () {
    $person = Person::factory()->create([
        'name' => 'Ahmad Fauzi',
    ]);

    expect($person->toSearchableArray()['formatted_name'] ?? null)->toBe('Ahmad Fauzi');
});
