<?php

declare(strict_types=1);

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Features\References\Models\Reference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class);

it('persists the canonical year field', function (): void {
    $reference = OwnerContext::withOwner(null, fn () => Reference::factory()->create(['year' => 2020]));

    expect($reference->year)->toBe(2020);
});
