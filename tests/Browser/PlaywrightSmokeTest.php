<?php

test('home page renders in a real browser', function () {
    $this->get('/')->assertOk();

    visit('/')
        ->assertSee('ilmu360');
});
