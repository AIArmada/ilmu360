<?php

declare(strict_types=1);

test('home page renders in a real browser', function () {
    $this->get('/')->assertOk();

    visit('/')
        ->assertSee('ilmu360');
});
