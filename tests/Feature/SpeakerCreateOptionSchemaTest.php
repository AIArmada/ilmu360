<?php

use App\Forms\SpeakerFormSchema;
use App\Models\Institution;
use App\Models\Speaker;

it('includes biography, cover image, and institution position fields in speaker create option form', function () {
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

    $components = collect($flatten(SpeakerFormSchema::createOptionForm()))
        ->keyBy(fn (mixed $component): ?string => method_exists($component, 'getName') ? $component->getName() : null);

    $fieldNames = $components
        ->keys()
        ->filter()
        ->values()
        ->all();

    expect($fieldNames)->toContain('bio');
    expect($fieldNames)->toContain('cover');
    expect($fieldNames)->toContain('institution_position');
    expect($fieldNames)->toContain('institution_id');
    expect($fieldNames)->toContain('honorific');
    expect($fieldNames)->toContain('pre_nominal');
    expect($fieldNames)->toContain('post_nominal');
    expect($components->get('institution_id')?->isMultiple())->toBeFalse();
    expect($components->get('honorific')?->isMultiple())->toBeTrue();
    expect($components->get('pre_nominal')?->isMultiple())->toBeTrue();
});

it('stores biography and institution pivot position when creating a speaker via create option', function () {
    $institution = Institution::factory()->create();

    $bio = [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [[
                'type' => 'text',
                'text' => 'Biografi ujian penceramah.',
            ]],
        ]],
    ];

    $speakerId = SpeakerFormSchema::createOptionUsing([
        'name' => 'Ustaz Test Speaker',
        'gender' => 'male',
        'bio' => $bio,
        'post_nominal' => ['PhD', 'MSc'],
        'institution_id' => $institution->id,
        'institution_position' => 'Mudir',
    ]);

    /** @var Speaker $speaker */
    $speaker = Speaker::query()
        ->with('institutions')
        ->findOrFail($speakerId);

    $linkedInstitution = $speaker->institutions->firstWhere('id', $institution->id);

    expect($speaker->bio)->toBe($bio)
        ->and($speaker->post_nominal)->toBe(['PhD', 'MSc'])
        ->and($speaker->status)->toBe('pending')
        ->and($linkedInstitution)->not->toBeNull()
        ->and($linkedInstitution?->pivot?->position)->toBe('Mudir')
        ->and((bool) $linkedInstitution?->pivot?->is_primary)->toBeTrue();
});
