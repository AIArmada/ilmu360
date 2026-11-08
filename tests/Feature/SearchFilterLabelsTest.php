<?php

use AIArmada\Addressing\Models\City;
use AIArmada\Addressing\Models\State;
use AIArmada\Events\Models\EventTaxonomy;
use AIArmada\Events\Models\EventTerm;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\Venue;
use App\Shared\Discovery\SearchFilterLabels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('resolves all record kinds through scalar discovery labels', function (): void {
    $country = ensureTestMalaysiaCountry();
    $state = State::factory()->create(['country_id' => $country->id, 'name' => 'Label State']);
    $city = City::factory()->create(['country_id' => $country->id, 'state_id' => $state->id, 'name' => 'Label City']);
    $area = createTestAddressArea('Label District', 2, country: $country);
    $institution = Institution::factory()->create(['name' => 'Label Institution']);
    $venue = Venue::factory()->create(['name' => 'Label Venue']);
    $person = Person::factory()->create(['name' => 'Label Person']);
    $term = EventTerm::factory()->create(['event_taxonomy_id' => EventTaxonomy::factory()->create()->getKey(), 'name' => 'Label Term']);
    $reference = Reference::factory()->create(['title' => 'Label Reference']);
    $labels = app(SearchFilterLabels::class);

    expect([
        $labels->label('country_id', (string) $country->id),
        $labels->label('state_id', (string) $state->id),
        $labels->label('city_id', (string) $city->id),
        $labels->label('area_assignments', (string) $area->id),
        $labels->label('institution_id', (string) $institution->id),
        $labels->label('venue_id', (string) $venue->id),
        $labels->label('person_ids', (string) $person->id),
        $labels->label('domain_tag_ids', (string) $term->id),
        $labels->label('reference_ids', (string) $reference->id),
    ])->toBe([
        'Malaysia', 'Label State', 'Label City', 'Label District', 'Label Institution',
        'Label Venue', 'Label Person', 'Label Term', 'Label Reference',
    ]);
});

it('does not query invalid guarded identifiers or unknown filter keys', function (string $key): void {
    $labels = app(SearchFilterLabels::class);
    DB::enableQueryLog();
    DB::flushQueryLog();

    expect($labels->label($key, 'not-a-uuid'))->toBeNull()
        ->and(DB::getQueryLog())->toBeEmpty();
})->with(['country_id', 'state_id', 'city_id', 'area_assignments', 'institution_id', 'venue_id', 'person_ids', 'unknown_filter']);

it('shares a person lookup between role-specific filters', function (): void {
    $person = Person::factory()->create(['name' => 'Role Profile']);
    $labels = app(SearchFilterLabels::class);
    DB::enableQueryLog();
    DB::flushQueryLog();

    foreach (['person_ids', 'person_in_charge_ids', 'moderator_ids', 'imam_ids', 'khatib_ids', 'bilal_ids'] as $key) {
        expect($labels->label($key, (string) $person->id))->toBe('Role Profile');
    }

    expect(DB::getQueryLog())->toHaveCount(1);
});

it('shares a taxonomy term lookup between vocabulary filters', function (): void {
    $term = EventTerm::factory()->create(['event_taxonomy_id' => EventTaxonomy::factory()->create()->getKey(), 'name' => 'Shared Term']);
    $labels = app(SearchFilterLabels::class);
    DB::enableQueryLog();
    DB::flushQueryLog();

    foreach (['domain_tag_ids', 'discipline_tag_ids', 'source_tag_ids', 'issue_tag_ids'] as $key) {
        expect($labels->label($key, (string) $term->id))->toBe('Shared Term');
    }

    expect(DB::getQueryLog())->toHaveCount(1);
});

it('keeps a label within one lifecycle and refreshes it in the next', function (): void {
    $person = Person::factory()->create(['name' => 'Before Rename']);
    $labels = app(SearchFilterLabels::class);
    expect($labels->label('person_ids', (string) $person->id))->toBe('Before Rename');

    $person->update(['name' => 'After Rename']);

    expect(app(SearchFilterLabels::class))->toBe($labels)
        ->and($labels->label('person_ids', (string) $person->id))->toBe('Before Rename');

    app()->forgetScopedInstances();

    expect(app(SearchFilterLabels::class))->not->toBe($labels)
        ->and(app(SearchFilterLabels::class)->label('person_ids', (string) $person->id))->toBe('After Rename');
});

it('caches missing labels only for the current lifecycle', function (): void {
    $id = (string) Str::uuid();
    $labels = app(SearchFilterLabels::class);
    DB::enableQueryLog();
    DB::flushQueryLog();

    expect($labels->label('person_ids', $id))->toBeNull()
        ->and($labels->label('person_ids', $id))->toBeNull()
        ->and(DB::getQueryLog())->toHaveCount(1);

    Person::factory()->create(['id' => $id, 'name' => 'New Profile']);

    expect($labels->label('person_ids', $id))->toBeNull();

    app()->forgetScopedInstances();

    expect(app(SearchFilterLabels::class)->label('person_ids', $id))->toBe('New Profile');
});
