<?php

use App\Models\Reference;
use App\Support\Models\SlugOrUuidResolver;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

it('does not append key lookup bindings for non-uuid identifiers', function () {
    $resolver = app(SlugOrUuidResolver::class);

    $query = $resolver->apply(Reference::query(), 'references.slug', 'fiqh-muamalat');

    expect($query->getBindings())
        ->toHaveCount(1)
        ->and($query->getBindings()[0])->toBe('fiqh-muamalat');
});

it('appends key lookup bindings for uuid identifiers', function () {
    $resolver = app(SlugOrUuidResolver::class);
    $identifier = (string) Str::uuid();

    $query = $resolver->apply(Reference::query(), 'references.slug', $identifier);

    expect($query->getBindings())
        ->toHaveCount(2)
        ->and($query->getBindings()[0])->toBe($identifier)
        ->and($query->getBindings()[1])->toBe($identifier);
});
