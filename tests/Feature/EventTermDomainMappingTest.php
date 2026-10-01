<?php

use AIArmada\Authz\Models\Role;
use AIArmada\Events\Models\EventTerm;
use AIArmada\FilamentEvents\Resources\EventTermResource\Pages\CreateEventTerm;
use AIArmada\FilamentEvents\Resources\EventTermResource\Pages\EditEventTerm;
use App\Enums\EventTaxonomyCode;
use App\Filament\Resources\EventTerms\TermDomainMappingExtension;
use App\Livewire\Pages\SubmitEvent\Create;
use App\Models\User;
use Livewire\Livewire;

function termMappingAdminUser(): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate('admin', 'web'));

    return $user;
}

it('shows domain mapping for discipline and issue terms', function () {
    $this->actingAs(termMappingAdminUser());

    $disciplineTaxonomyId = (string) submitEventTerm('discipline')->event_taxonomy_id;
    $issueTaxonomyId = (string) submitEventTerm('issue')->event_taxonomy_id;
    $domainTaxonomyId = (string) submitEventTerm('domain')->event_taxonomy_id;

    Livewire::test(CreateEventTerm::class)
        ->fillForm(['event_taxonomy_id' => $disciplineTaxonomyId])
        ->assertFormFieldVisible('domain_ids')
        ->fillForm(['event_taxonomy_id' => $issueTaxonomyId])
        ->assertFormFieldVisible('domain_ids')
        ->fillForm(['event_taxonomy_id' => $domainTaxonomyId])
        ->assertFormFieldHidden('domain_ids');
});

it('creates a discipline term with domain mapping', function () {
    $this->actingAs(termMappingAdminUser());

    $agamaId = (string) submitEventTerm('domain')->id;
    $disciplineTaxonomyId = (string) submitEventTerm('discipline')->event_taxonomy_id;

    Livewire::test(CreateEventTerm::class)
        ->fillForm([
            'event_taxonomy_id' => $disciplineTaxonomyId,
            'code' => 'test-discipline-mapping',
            'name' => 'Test Discipline Mapping',
            'domain_ids' => [$agamaId],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(EventTerm::query()->where('code', 'test-discipline-mapping')->firstOrFail()->metadata)
        ->toBe(['domain_ids' => [$agamaId]]);
});

it('hydrates existing domain mapping on the edit form', function () {
    $this->actingAs(termMappingAdminUser());

    $agamaId = (string) submitEventTerm('domain')->id;
    $term = submitEventTerm('discipline');
    TermDomainMappingExtension::syncDomainMapping($term, [$agamaId]);

    Livewire::test(EditEventTerm::class, ['record' => $term->getKey()])
        ->assertFormFieldVisible('domain_ids')
        ->assertSet('data.domain_ids', [$agamaId]);
});

it('persists domain mapping into term metadata', function () {
    $agama = submitEventTerm('domain');
    $pendidikan = submitEventTerm('domain');
    $term = submitEventTerm('discipline');

    TermDomainMappingExtension::syncDomainMapping($term, [(string) $agama->id, (string) $pendidikan->id]);

    expect($term->fresh()->metadata)->toBe(['domain_ids' => [(string) $agama->id, (string) $pendidikan->id]]);
});

it('clears the mapping key when no domains are selected', function () {
    $term = submitEventTerm('discipline');
    $term->metadata = ['domain_ids' => ['some-id'], 'other' => 'kept'];
    $term->save();

    TermDomainMappingExtension::syncDomainMapping($term, []);

    expect($term->fresh()->metadata)->toBe(['other' => 'kept']);
});

it('stores null metadata when clearing the only mapping key', function () {
    $term = submitEventTerm('discipline');
    $term->metadata = ['domain_ids' => ['some-id']];
    $term->save();

    TermDomainMappingExtension::syncDomainMapping($term, []);

    expect($term->fresh()->metadata)->toBeNull();
});

it('filters discipline options by the selected domain', function () {
    $agama = submitEventTerm('domain');
    $pendidikan = submitEventTerm('domain');

    $agamaOnly = submitEventTerm('discipline');
    TermDomainMappingExtension::syncDomainMapping($agamaOnly, [(string) $agama->id]);

    $global = submitEventTerm('discipline');

    $pendidikanOnly = submitEventTerm('discipline');
    TermDomainMappingExtension::syncDomainMapping($pendidikanOnly, [(string) $pendidikan->id]);

    $options = Livewire::test(Create::class)->instance()->taxonomyTermOptionsForDomain(EventTaxonomyCode::Discipline, (string) $agama->id);

    expect($options)
        ->toHaveKey((string) $agamaOnly->id)
        ->toHaveKey((string) $global->id)
        ->not->toHaveKey((string) $pendidikanOnly->id);

    $options = Livewire::test(Create::class)->instance()->taxonomyTermOptionsForDomain(EventTaxonomyCode::Discipline, (string) $pendidikan->id);

    expect($options)
        ->toHaveKey((string) $pendidikanOnly->id)
        ->toHaveKey((string) $global->id)
        ->not->toHaveKey((string) $agamaOnly->id);
});

it('filters issue options by the selected domain', function () {
    $agama = submitEventTerm('domain');
    $pendidikan = submitEventTerm('domain');

    $agamaOnly = submitEventTerm('issue');
    TermDomainMappingExtension::syncDomainMapping($agamaOnly, [(string) $agama->id]);

    $global = submitEventTerm('issue');

    $pendidikanOnly = submitEventTerm('issue');
    TermDomainMappingExtension::syncDomainMapping($pendidikanOnly, [(string) $pendidikan->id]);

    $options = Livewire::test(Create::class)->instance()->taxonomyTermOptionsForDomain(EventTaxonomyCode::Issue, (string) $agama->id);

    expect($options)
        ->toHaveKey((string) $agamaOnly->id)
        ->toHaveKey((string) $global->id)
        ->not->toHaveKey((string) $pendidikanOnly->id);

    $options = Livewire::test(Create::class)->instance()->taxonomyTermOptionsForDomain(EventTaxonomyCode::Issue, (string) $pendidikan->id);

    expect($options)
        ->toHaveKey((string) $pendidikanOnly->id)
        ->toHaveKey((string) $global->id)
        ->not->toHaveKey((string) $agamaOnly->id);
});
