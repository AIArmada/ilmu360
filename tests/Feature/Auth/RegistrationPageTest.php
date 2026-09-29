<?php

it('renders the registration page title', function () {
    $this->get(route('register'))
        ->assertOk()
        ->assertSee('<title>'.__('Register').' - ', false);
});
