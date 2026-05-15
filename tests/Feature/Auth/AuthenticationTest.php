<?php

use App\Models\User;

test('login screen can be rendered', function () {
    $response = $this->get('/login');

    $response->assertStatus(200)
        ->assertSee('autocomplete="email"', false)
        ->assertSee('autocomplete="current-password"', false);
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect('/dashboard');
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('register screen can be rendered', function () {
    $response = $this->get('/register');
    $response->assertStatus(200)
        ->assertSee('autocomplete="name"', false)
        ->assertSee('autocomplete="email"', false)
        ->assertSee('autocomplete="new-password"', false);
});

test('new users can register', function () {
    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect('/dashboard');
});

test('forgot password screen can be rendered', function () {
    $response = $this->get('/forgot-password');
    $response->assertStatus(200)
        ->assertSee('autocomplete="email"', false);
});

test('users are redirected back to the current page when they logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->from('/majlis?halaman=2')
        ->post(route('logout'));

    $this->assertGuest();
    $response->assertRedirect(url('/majlis?halaman=2'));
});

test('logout ignores external redirect targets', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withHeader('referer', 'https://example.com/phishing')
        ->post(route('logout'));

    $this->assertGuest();
    $response->assertRedirect(route('home'));
});
