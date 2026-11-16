<?php

declare(strict_types=1);

use App\Models\Person;

it('renders the redesigned person hero with stable event totals and sharing anchor', function () {
    $person = Person::factory()->create(['status' => 'verified']);

    $this->get(route('persons.show', $person))
        ->assertSuccessful()
        ->assertSee('data-testid="hero-upcoming-total"', false)
        ->assertSee('data-testid="hero-past-total"', false)
        ->assertSee('person-share-panel', false);
});
