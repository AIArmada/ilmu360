<?php

declare(strict_types=1);

it('omits market switching markup', function () {
    $response = $this->withSession(['locale' => 'en'])
        ->get(route('about'));

    $response->assertOk()
        ->assertDontSee('aria-label="Market"', false)
        ->assertDontSee('/market/', false)
        ->assertDontSee('/pasaran/', false);
});

it('does not register market switching routes', function () {
    $this->get('/market/malaysia')->assertNotFound();
    $this->get('/pasaran/malaysia')->assertNotFound();
});
