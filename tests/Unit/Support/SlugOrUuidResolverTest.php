<?php

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Models\Reference;
use App\Support\Models\SlugOrUuidResolver;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

it('does not append key lookup bindings for non-uuid identifiers', function () {
    $resolver = app(SlugOrUuidResolver::class);

    $bindings = OwnerContext::withOwner(null, fn (): array => $resolver->apply(Reference::query(), 'references.slug', 'fiqh-muamalat')->getBindings());

    expect($bindings)
        ->toHaveCount(1)
        ->and($bindings[0])->toBe('fiqh-muamalat');
});

it('appends key lookup bindings for uuid identifiers', function () {
    $resolver = app(SlugOrUuidResolver::class);
    $identifier = (string) Str::uuid();

    $bindings = OwnerContext::withOwner(null, fn (): array => $resolver->apply(Reference::query(), 'references.slug', $identifier)->getBindings());

    expect($bindings)
        ->toHaveCount(2)
        ->and($bindings[0])->toBe($identifier)
        ->and($bindings[1])->toBe($identifier);
});
