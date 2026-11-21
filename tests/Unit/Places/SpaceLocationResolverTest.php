<?php

declare(strict_types=1);

use App\Features\Institutions\Api\Orm\InstitutionLookup;
use App\Features\Places\Api\SpaceLocation;
use App\Features\Places\Services\SpaceLocationResolver;

it('prefers the saved name snapshot and falls back to the venue space name', function (): void {
    $resolver = app(SpaceLocation::class);

    expect($resolver)->toBeInstanceOf(SpaceLocationResolver::class)
        ->and($resolver->name('  Main Hall  ', 'Old name'))->toBe('Main Hall')
        ->and($resolver->name('  ', 'Venue Hall'))->toBe('Venue Hall')
        ->and($resolver->name(null, null))->toBeNull();
});

it('returns the selected space capacity and rejects absent spaces', function (): void {
    $resolver = app(SpaceLocation::class);

    expect($resolver->effectiveCapacity('space-id', 120))->toBe(120)
        ->and($resolver->effectiveCapacity(null, 120))->toBeNull()
        ->and($resolver->effectiveCapacity('space-id', null))->toBeNull();
});

it('uses the institution-specific space capacity even when explicitly unlimited', function (): void {
    $lookup = Mockery::mock(InstitutionLookup::class);
    $lookup->shouldReceive('institutionSpaceCapacity')
        ->once()
        ->with('institution-id', 'space-id')
        ->andReturn(['found' => true, 'capacity' => null]);

    app()->instance(InstitutionLookup::class, $lookup);

    expect(app(SpaceLocation::class)->effectiveCapacity('space-id', 120, 'institution-id'))->toBeNull();
});
