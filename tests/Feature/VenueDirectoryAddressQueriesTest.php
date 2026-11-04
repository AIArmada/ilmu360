<?php

use App\Models\Venue;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('renders canonical venue area names with batched address assignment and area reads', function () {
    foreach (range(1, 4) as $number) {
        $geography = createTestPackageGeography(
            'Negeri Direktori Tempat',
            "Daerah Direktori {$number}",
            "Mukim Direktori {$number}",
        );
        $venue = Venue::factory()->create([
            'name' => "Dewan Kawasan {$number}",
            'status' => 'verified',
        ]);
        syncPrimaryAddressForTest($venue, [
            ...$geography['address'],
            'country_code' => 'MY',
            'city' => 'Obsolete Entered City',
        ]);
    }

    $areaQueryCount = 0;
    $assignmentQueryCount = 0;
    DB::listen(function (QueryExecuted $query) use (&$areaQueryCount, &$assignmentQueryCount): void {
        if (! preg_match('/\bfrom\s+["`]?([a-z_]+)/i', $query->sql, $matches)) {
            return;
        }

        if ($matches[1] === 'address_areas') {
            $areaQueryCount++;
        }

        if ($matches[1] === 'address_area_assignments') {
            $assignmentQueryCount++;
        }
    });

    $component = Livewire::test('pages.venues.results')
        ->assertDontSeeText('Obsolete Entered City');

    foreach (range(1, 4) as $number) {
        $component->assertSeeText("Dewan Kawasan {$number}")
            ->assertSeeText("Daerah Direktori {$number}")
            ->assertSeeText("Mukim Direktori {$number}");
    }

    expect($assignmentQueryCount)->toBe(1);
    expect($areaQueryCount)->toBe(1);
});
