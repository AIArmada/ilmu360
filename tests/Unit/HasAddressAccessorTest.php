<?php

use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('returns line1 through the address_line1 accessor', function () {
    $venue = Venue::factory()->create();

    $venue->address()->updateOrCreate([], [
        'line1' => 'Jalan Bukit Jelutong',
    ]);

    expect($venue->fresh()->address_line1)->toBe('Jalan Bukit Jelutong');
});

it('trims address line spacing before rendering address joins', function () {
    $venue = Venue::factory()->create();

    $venue->address()->updateOrCreate([], [
        'line1' => 'Persiaran Masjid ',
        'line2' => ' Seksyen 14',
    ]);

    $address = $venue->fresh()->addressModel;

    expect($address)->not->toBeNull()
        ->and($address?->getRawOriginal('line1'))->toBe('Persiaran Masjid')
        ->and($address?->getRawOriginal('line2'))->toBe('Seksyen 14')
        ->and($address?->line1)->toBe('Persiaran Masjid')
        ->and($address?->line2)->toBe('Seksyen 14')
        ->and(sprintf('%s, %s', $address?->line1, $address?->line2))->toBe('Persiaran Masjid, Seksyen 14');
});
