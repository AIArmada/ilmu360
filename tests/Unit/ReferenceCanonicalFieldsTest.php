<?php

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Models\Reference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('persists the canonical year field without the removed publication year alias', function (): void {
    $reference = OwnerContext::withOwner(null, fn () => Reference::factory()->create(['year' => 2020]));

    expect($reference->year)->toBe(2020)
        ->and($reference->getAttributes())->not->toHaveKey('publication_year')
        ->and($reference->getAttribute('publication_year'))->toBeNull();
});
