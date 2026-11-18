<?php

use App\Features\Persons\EntryPoints\Filament\Forms\PersonFormSchema;
use App\Features\Institutions\Models\Institution;
use App\Features\Persons\Models\Person;
use Illuminate\Validation\ValidationException;

it('includes biography, cover image, and affiliation rows in person create option form', function () {
    $flatten = function (array $components) use (&$flatten): array {
        $flattened = [];

        foreach ($components as $component) {
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

            $defaultChildComponents = $childComponents['default'] ?? null;

            if (! is_array($defaultChildComponents)) {
                continue;
            }

            array_push($flattened, ...$flatten($defaultChildComponents));
        }

        return $flattened;
    };

    $components = collect($flatten(PersonFormSchema::createOptionForm()))
        ->keyBy(fn (mixed $component): ?string => method_exists($component, 'getName') ? $component->getName() : null);

    $fieldNames = $components
        ->keys()
        ->filter()
        ->values()
        ->all();

    expect($fieldNames)->toContain('bio', 'cover', 'institutions');
});

it('stores biography and institution pivot position when creating a person via create option', function () {
    $institution = Institution::factory()->create();

    $bio = [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [[
                'text' => 'Biografi ujian penceramah.',
                'type' => 'text',
            ]],
        ]],
    ];

    $personId = PersonFormSchema::createOptionUsing([
        'name' => 'Ustaz Test Person',
        'gender' => 'male',
        'bio' => $bio,
        'institutions' => [[
            'institution_id' => $institution->id,
            'position' => 'Mudir',
            'is_primary' => true,
        ]],
    ]);

    /** @var Person $person */
    $person = Person::query()
        ->with('institutions')
        ->findOrFail($personId);

    $linkedInstitution = $person->institutions->firstWhere('id', $institution->id);

    expect($person->bio)->toBe($bio)
        ->and($person->status)->toBe('pending')
        ->and($linkedInstitution)->not->toBeNull()
        ->and($linkedInstitution?->pivot?->position)->toBe('Mudir')
        ->and($linkedInstitution?->pivot?->is_primary)->toBeTruthy();
});

it('rejects blank or missing speaker identity before quick creation', function (array $identity) {
    $count = Person::query()->count();

    expect(fn () => PersonFormSchema::createOptionUsing($identity))
        ->toThrow(ValidationException::class)
        ->and(Person::query()->count())->toBe($count);
})->with([
    'blank name' => [['name' => '   ', 'gender' => 'male']],
    'missing name' => [['gender' => 'male']],
    'missing gender' => [['name' => 'Known Speaker']],
    'invalid gender' => [['name' => 'Known Speaker', 'gender' => 'invented']],
]);
