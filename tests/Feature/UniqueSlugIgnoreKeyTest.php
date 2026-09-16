<?php

use AIArmada\CommerceSupport\Support\UniqueSlug;
use App\Actions\References\GenerateReferenceSlugAction;
use App\Models\Reference;

it('treats an empty ignore key as null when building a unique slug', function (): void {
    Reference::factory()->create(['slug' => 'riyadhus-solihin']);

    $withEmptyString = UniqueSlug::build(Reference::class, 'riyadhus-solihin', [], '', '');
    $withNull = UniqueSlug::build(Reference::class, 'riyadhus-solihin', [], '', null);

    expect($withEmptyString)->toBe('riyadhus-solihin-2')
        ->and($withNull)->toBe('riyadhus-solihin-2');
});

it('generates a reference slug for records without a key', function (): void {
    $slug = app(GenerateReferenceSlugAction::class)->handle('Riyadhus Solihin', '');

    expect($slug)->toBe('riyadhus-solihin');
});
