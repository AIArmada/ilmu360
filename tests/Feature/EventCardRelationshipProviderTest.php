<?php

declare(strict_types=1);

use AIArmada\Events\Contracts\EventSearchRelationProvider;
use App\Features\Discovery\Queries\EventCardRelationshipProvider;

it('is the configured application adapter for the package relation seam', function (): void {
    expect(app(EventSearchRelationProvider::class))
        ->toBeInstanceOf(EventCardRelationshipProvider::class);
});
