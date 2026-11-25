<?php

use App\Features\Contributions\EntryPoints\Filament\Forms\PersonContributionFormSchema;
use App\Features\Events\Api\Filament\EventContributionFormContext;
use App\Features\Events\Api\Filament\EventContributionFormMode;
use App\Features\Events\Api\Filament\EventContributionFormSchema;
use App\Features\Events\EntryPoints\Filament\Resources\Series\Schemas\SeriesForm;
use App\Features\Persons\EntryPoints\Filament\Resources\Persons\Schemas\PersonForm;
use App\Features\Places\EntryPoints\Filament\Resources\Spaces\Schemas\SpaceForm;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;

/**
 * @param  array<int, mixed>  $components
 * @return array<int, object>
 */
function flattenPlaceholderComponents(array $components): array
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
                array_push($flattened, ...flattenPlaceholderComponents($nestedComponents));
            }
        }
    }

    return $flattened;
}

/**
 * @param  array<int, object>  $components
 * @return array<string, Select>
 */
function selectPlaceholders(array $components): array
{
    return collect(flattenPlaceholderComponents($components))
        ->filter(fn (object $component): bool => $component instanceof Select)
        ->mapWithKeys(fn (Select $select): array => [$select->getName() => $select])
        ->all();
}

it('uses contextual placeholders for contribution multi-selects', function () {
    $schema = app(EventContributionFormSchema::class);
    $context = new EventContributionFormContext(mode: EventContributionFormMode::Update);

    $selects = selectPlaceholders([
        ...$schema->topicComponents($context),
        ...$schema->scheduleComponents($context),
        ...$schema->attendanceComponents($context),
        ...$schema->organizerComponents($context),
        ...$schema->locationComponents($context),
        ...$schema->speakerComponents($context),
    ]);

    expect($selects)->toHaveKeys([
        'event_category_ids',
        'age_group',
        'language_ids',
        'domain_tags',
        'discipline_tags',
        'source_tags',
        'issue_tags',
        'reference_ids',
        'series_ids',
        'space_ids',
        'person_ids',
    ]);

    foreach ($selects as $select) {
        if ($select->isMultiple()) {
            expect($select->getPlaceholder())->not->toBe(__('Pilih satu pilihan'));
        }
    }

    expect($selects['language_ids']->getPlaceholder())->toBe(__('Pilih bahasa'))
        ->and($selects['age_group']->getPlaceholder())->toBe(__('Pilih peringkat umur'))
        ->and($selects['reference_ids']->getPlaceholder())->toBe(__('Cari atau pilih rujukan…'));
});

it('uses contextual placeholders for person and relationship multi-selects', function () {
    $personContributionSelects = selectPlaceholders(PersonContributionFormSchema::components(
        includeMedia: false,
        useTitleMultiSelect: true,
    ));
    $personAdminSelects = selectPlaceholders(PersonForm::configure(Schema::make())->getComponents());
    $seriesSelects = selectPlaceholders(SeriesForm::configure(Schema::make())->getComponents());
    $spaceSelects = selectPlaceholders(SpaceForm::configure(Schema::make())->getComponents());

    expect($personContributionSelects['language_ids']->getPlaceholder())->toBe(__('Pilih bahasa'))
        ->and($personContributionSelects['title_ids']->getPlaceholder())->toBe(__('Select honorifics'))
        ->and($personAdminSelects['languages']->getPlaceholder())->toBe(__('Pilih bahasa'))
        ->and($seriesSelects['languages']->getPlaceholder())->toBe(__('Pilih bahasa'))
        ->and($spaceSelects['institutions']->getPlaceholder())->toBe(__('Select institution'));
});
