<?php

use AIArmada\Persons\Models\Title;
use App\Features\Contributions\Api\Enums\ContributionSubjectType;
use App\Features\Contributions\EntryPoints\Livewire\Pages\SuggestUpdate;
use App\Features\Institutions\Api\Enums\InstitutionNameType;
use App\Features\Institutions\Api\InstitutionWorkflow;
use App\Features\Institutions\EntryPoints\Filament\Forms\InstitutionFormSchema;
use App\Features\Institutions\Models\Institution;
use App\Features\Institutions\Models\InstitutionName;
use App\Features\Persons\Api\PersonWorkflow;
use App\Features\Persons\Models\Person;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

it('preloads the title catalog once and reuses its shared cache on the person update form', function (): void {
    $user = User::factory()->create();
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);

    addTestMember($person, $user, 'owner');

    DB::flushQueryLog();
    DB::enableQueryLog();

    $component = Livewire::actingAs($user)->test(SuggestUpdate::class, [
        'subjectType' => ContributionSubjectType::Person->publicRouteSegment(),
        'subjectId' => $person->slug,
    ]);

    $fields = collect($component->instance()->getForm('form')->getFlatFields());
    $titleField = $fields->first(fn (mixed $field): bool => method_exists($field, 'getName') && $field->getName() === 'title_ids');
    $languageField = $fields->first(fn (mixed $field): bool => method_exists($field, 'getName') && $field->getName() === 'language_ids');

    expect($titleField)->not->toBeNull()
        ->and($languageField)->not->toBeNull();

    $titleField->getSearchResults('syeikh');
    $languageField->getOptions();

    $initialQueries = collect(DB::getQueryLog());
    $titleCatalogQueries = $initialQueries
        ->filter(fn (array $query): bool => str_contains($query['query'], 'from "titles"'));
    $languageCatalogQueries = $initialQueries
        ->filter(fn (array $query): bool => str_contains($query['query'], 'select "id", "code", "name", "native" from "languages"'));

    DB::flushQueryLog();

    expect($titleCatalogQueries)->toHaveCount(1)
        ->and($languageCatalogQueries)->toHaveCount(1)
        ->and($titleField->getSearchResults('datuk'))->not->toBeEmpty()
        ->and($titleField->getSearchResults('dato'))->not->toBeEmpty();

    $reuseQueries = collect(DB::getQueryLog());

    expect($reuseQueries->filter(fn (array $query): bool => str_contains($query['query'], 'from "titles"')))->toBeEmpty()
        ->and($reuseQueries->filter(fn (array $query): bool => str_contains($query['query'], 'select "id", "code", "name", "native" from "languages"')))->toBeEmpty();
});

it('hydrates and saves a selected title from the preloaded title catalog', function (): void {
    $user = User::factory()->create();
    $user->assignRole(AIArmada\Authz\Models\Role::findOrCreate('admin', 'web'));
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);
    $title = Title::query()->firstOrFail();

    addTestMember($person, $user, 'owner');

    $component = Livewire::actingAs($user)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Person->publicRouteSegment(),
            'subjectId' => $person->slug,
        ]);

    $component
        ->set('data.title_ids', [$title->getKey()])
        ->call('submit')
        ->assertHasNoErrors();

    expect($person->fresh()->titleAssignments()->pluck('title_id')->all())
        ->toContain($title->getKey());
});

it('keeps person institution primary toggles exclusive during live updates', function (): void {
    $user = User::factory()->create();
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);
    $firstInstitution = Institution::factory()->create(['status' => 'verified']);
    $secondInstitution = Institution::factory()->create(['status' => 'verified']);

    addTestMember($person, $user, 'owner');

    Livewire::actingAs($user)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Person->publicRouteSegment(),
            'subjectId' => $person->slug,
        ])
        ->set('data.names', [
            [
                'name_type' => 'display',
                'full_name' => 'First Name',
                'language_code' => 'ms',
                'is_primary' => true,
            ],
            [
                'name_type' => 'display',
                'full_name' => 'Second Name',
                'language_code' => 'ms',
                'is_primary' => false,
            ],
        ])
        ->set('data.names.1.is_primary', true)
        ->assertSet('data.names.0.is_primary', false)
        ->assertSet('data.names.1.is_primary', true)
        ->set('data.institutions', [
            [
                'institution_id' => $firstInstitution->getKey(),
                'position' => null,
                'is_primary' => true,
            ],
            [
                'institution_id' => $secondInstitution->getKey(),
                'position' => null,
                'is_primary' => false,
            ],
        ])
        ->set('data.institutions.1.is_primary', true)
        ->assertSet('data.institutions.0.is_primary', false)
        ->assertSet('data.institutions.1.is_primary', true);
});

it('normalizes duplicate primary person names when applying a contribution', function (): void {
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);

    app(PersonWorkflow::class)->applyContribution((string) $person->getKey(), [
        'names' => [
            [
                'name_type' => 'display',
                'full_name' => 'First Name',
                'language_code' => 'ms',
                'is_primary' => true,
            ],
            [
                'name_type' => 'nickname',
                'full_name' => 'Second Name',
                'language_code' => 'ms',
                'is_primary' => true,
            ],
        ],
    ]);

    expect($person->fresh()->names()->where('is_primary', true)->pluck('full_name')->all())
        ->toBe(['First Name']);
});

it('keeps institution names exclusive across quick-create and contribution saves', function (): void {
    $quickCreatedId = InstitutionFormSchema::createOptionUsing([
        'name' => 'Institution with Names',
        'type' => 'masjid',
        'names' => [
            ['full_name' => 'First Name', 'name_type' => InstitutionNameType::Nickname, 'language_code' => 'ms', 'is_primary' => true],
            ['full_name' => 'Second Name', 'name_type' => InstitutionNameType::Nickname, 'language_code' => 'ms', 'is_primary' => true],
        ],
    ]);

    $quickCreated = Institution::query()->findOrFail($quickCreatedId);

    expect($quickCreated->names()->where('is_primary', true)->pluck('full_name')->all())
        ->toBe(['First Name']);

    $institution = Institution::factory()->create();

    app(InstitutionWorkflow::class)->applyContribution((string) $institution->getKey(), [
        'names' => [
            ['full_name' => 'Primary Name', 'name_type' => InstitutionNameType::Nickname, 'language_code' => 'ms', 'is_primary' => true],
            ['full_name' => 'Duplicate Name', 'name_type' => InstitutionNameType::Nickname, 'language_code' => 'ms', 'is_primary' => true],
        ],
    ]);

    expect($institution->fresh()->names()->where('is_primary', true)->pluck('full_name')->all())
        ->toBe(['Primary Name']);
});

it('normalizes direct institution name writes as a final persistence guard', function (): void {
    $institution = Institution::factory()->create();

    InstitutionName::create([
        'institution_id' => $institution->getKey(),
        'name_type' => InstitutionNameType::Nickname,
        'language_code' => 'ms',
        'full_name' => 'First Name',
        'is_primary' => true,
    ]);

    $second = InstitutionName::create([
        'institution_id' => $institution->getKey(),
        'name_type' => InstitutionNameType::Nickname,
        'language_code' => 'ms',
        'full_name' => 'Second Name',
        'is_primary' => true,
    ]);

    expect($institution->fresh()->names()->where('is_primary', true)->pluck('id')->all())
        ->toBe([$second->getKey()]);
});
