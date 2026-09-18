<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('does not expose legacy market switcher markup after the country cutover', function () {
    $response = $this->withSession(['locale' => 'en'])
        ->get(route('about'));

    $response->assertOk()
        ->assertDontSee('aria-label="Market"', false)
        ->assertDontSee('/market/', false)
        ->assertDontSee('/pasaran/', false);
});

it('does not resolve legacy market switch routes after the country cutover', function () {
    $this->get('/market/malaysia')->assertNotFound();
    $this->get('/pasaran/malaysia')->assertNotFound();
});
