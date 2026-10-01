<?php

use App\Models\Person;

it('gates bottom-anchored decor on settled load and sizes the header logo', function () {
    $person = Person::factory()->create(['status' => 'verified']);

    $this->get(route('persons.show', $person))
        ->assertSuccessful()
        ->assertSee('mi-bg-settled', false)
        ->assertSee('is-loaded', false)
        ->assertSee('width="2172" height="724"', false);
});
