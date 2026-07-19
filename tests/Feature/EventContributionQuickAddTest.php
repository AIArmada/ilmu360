<?php

use AIArmada\Events\Models\EventTaxonomy;
use AIArmada\Events\Models\EventTerm;
use App\Enums\EventTaxonomyCode;
use App\Forms\EventContributionFormSchema;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;
use App\Models\Venue;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @param  array<int, mixed>  $components
 * @return array<int, object>
 */
function flattenEventContributionComponents(array $components): array
{
    $flattened = [];

    foreach ($components as $component) {
        if (! is_object($component)) {
            continue;
        }

        $flattened[] = $component;

        $reflection = new ReflectionObject($component);

        while (! $reflection->hasProperty('childComponents') && ($parent = $reflection->getParentClass())) {
            $reflection = $parent;
        }

        if (! $reflection->hasProperty('childComponents')) {
            continue;
        }

        $childComponents = $reflection->getProperty('childComponents')->getValue($component);

        if (! is_array($childComponents)) {
            continue;
        }

        foreach ($childComponents as $nestedComponents) {
            if (is_array($nestedComponents)) {
                array_push($flattened, ...flattenEventContributionComponents($nestedComponents));
            }
        }
    }

    return $flattened;
}

function eventContributionSelect(string $name): Select
{
    $select = collect(flattenEventContributionComponents(EventContributionFormSchema::components()))
        ->first(fn (mixed $component): bool => $component instanceof Select && $component->getName() === $name);

    expect($select)->toBeInstanceOf(Select::class);

    return $select;
}

it('exposes quick-add actions for every update-form event select that supports create option', function () {
    $quickAddFieldNames = [
        'discipline_tags',
        'issue_tags',
        'reference_ids',
        'primary_organizer_institution_id',
        'primary_organizer_speaker_id',
        'location_institution_id',
        'location_venue_id',
        'speaker_ids',
    ];

    foreach ($quickAddFieldNames as $fieldName) {
        $field = eventContributionSelect($fieldName);

        expect($field->hasCreateOptionActionFormSchema())->toBeTrue()
            ->and($field->getCreateOptionUsing())->toBeInstanceOf(Closure::class);
    }
});

it('creates pending related records from event update quick-add actions', function () {
    $institutionId = (eventContributionSelect('primary_organizer_institution_id')->getCreateOptionUsing())(
        [
            'name' => 'Masjid Quick Add Update',
            'type' => 'masjid',
        ],
        Schema::make(),
    );

    $speakerId = (eventContributionSelect('primary_organizer_speaker_id')->getCreateOptionUsing())(
        [
            'name' => 'Ustaz Quick Add Update',
            'gender' => 'male',
        ],
        Schema::make(),
    );

    $venueId = (eventContributionSelect('location_venue_id')->getCreateOptionUsing())(
        [
            'name' => 'Dewan Quick Add Update',
            'type' => 'dewan',
        ],
        Schema::make(),
    );

    $referenceId = (eventContributionSelect('reference_ids')->getCreateOptionUsing())(
        [
            'title' => 'Kitab Quick Add Update',
            'type' => 'book',
        ],
        Schema::make(),
    );

    expect(Institution::query()->findOrFail($institutionId)->status)->toBe('pending')
        ->and(Speaker::query()->findOrFail($speakerId)->status)->toBe('pending')
        ->and(Venue::query()->findOrFail($venueId)->status)->toBe('pending')
        ->and(Reference::query()->findOrFail($referenceId)->status)->toBe('pending');
});

it('creates pending tags from event update quick-add actions', function () {
    $disciplineTagId = (eventContributionSelect('discipline_tags')->getCreateOptionUsing())([
        'name' => 'Usul Fiqh Quick Add',
    ]);

    $issueTagId = (eventContributionSelect('issue_tags')->getCreateOptionUsing())([
        'name' => 'Pemuda Quick Add',
    ]);

    $disciplineTag = EventTerm::query()->findOrFail($disciplineTagId);
    $issueTag = EventTerm::query()->findOrFail($issueTagId);

    expect($disciplineTag->is_active)->toBeTrue()
        ->and(EventTaxonomy::query()->findOrFail($disciplineTag->event_taxonomy_id)->code)->toBe(EventTaxonomyCode::Discipline->value)
        ->and($disciplineTag->name)->toBe('Usul Fiqh Quick Add')
        ->and($issueTag->is_active)->toBeTrue()
        ->and(EventTaxonomy::query()->findOrFail($issueTag->event_taxonomy_id)->code)->toBe(EventTaxonomyCode::Issue->value)
        ->and($issueTag->name)->toBe('Pemuda Quick Add');
});
