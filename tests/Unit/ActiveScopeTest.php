<?php

use App\Features\Events\Models\Event;
use App\Features\Institutions\Models\Institution;
use App\Features\Persons\Models\Person;
use App\Features\References\Models\Reference;
use App\Features\Places\Models\Venue;
use App\Features\Events\States\EventStatus\Approved;
use App\Features\Events\States\EventStatus\Draft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class);

it('models have active scopes', function () {
    withGlobalOwnerContext(function (): void {
        Person::factory()->create(['status' => 'verified']);
        Person::factory()->create(['status' => 'inactive']);
        expect(Person::active()->count())->toBe(1);

        Institution::factory()->create(['status' => 'verified']);
        Institution::factory()->create(['status' => 'inactive']);
        expect(Institution::active()->count())->toBe(1);

        Reference::factory()->create(['status' => 'verified']);
        Reference::factory()->create(['status' => 'pending']);
        Reference::factory()->pending()->unpublished()->create();
        Reference::factory()->create(['status' => 'inactive']);
        expect(Reference::active()->count())->toBe(2);

        Venue::factory()->create(['status' => 'verified']);
        Venue::factory()->create(['status' => 'inactive']);
        expect(Venue::active()->count())->toBe(1);

        Event::factory()->create([
            'status' => Approved::class,
            'visibility' => 'public',
            'published_at' => now(),
        ]);
        Event::factory()->create([
            'status' => Draft::class,
            'visibility' => 'public',
            'published_at' => null,
        ]);
        // Public event factories publish approved events as part of the current
        // lifecycle contract, so the approved fixture above is the sole active
        // event in this scope check.
        expect(Event::active()->count())->toBe(1);
    });
});
