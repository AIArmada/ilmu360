<?php

use AIArmada\Persons\Models\Title;

it('scopes application-seeded titles to Malaysia', function (): void {
    $malaysia = ensureTestMalaysiaCountry();

    expect(Title::query()->whereNull('country_id')->count())->toBe(0)
        ->and(Title::query()->where('country_id', $malaysia->getKey())->count())->toBeGreaterThan(0);
});
