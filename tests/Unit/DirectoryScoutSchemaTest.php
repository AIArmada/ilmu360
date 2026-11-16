<?php

use App\Models\Institution;
use App\Models\Person;
use Tests\TestCase;

pest()->extend(TestCase::class);

it('keeps directory search schemas free of fixed integer geography fields', function (): void {
    foreach ([Person::class, Institution::class] as $modelClass) {
        $fields = collect(config('scout.typesense.model-settings.'.$modelClass.'.collection-schema.fields'))
            ->keyBy('name');

        expect($fields->get('id')['type'])->toBe('string')
            ->and($fields->get('status')['type'])->toBe('string')
            ->and($fields->pluck('type')->all())->not->toContain('int32');
    }
});
