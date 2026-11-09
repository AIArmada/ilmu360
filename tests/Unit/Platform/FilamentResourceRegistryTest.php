<?php

declare(strict_types=1);

use App\Filament\Resources\Persons\PersonResource;
use App\Platform\Admin\FilamentResourceRegistry;

it('registers each filament resource once', function (): void {
    $registry = new FilamentResourceRegistry;

    $registry->register(PersonResource::class);
    $registry->register(PersonResource::class);

    expect($registry->all())->toBe([PersonResource::class]);
});

it('rejects a class that is not a filament resource', function (): void {
    $registry = new FilamentResourceRegistry;

    expect(fn () => $registry->register(stdClass::class))->toThrow(InvalidArgumentException::class);
});
