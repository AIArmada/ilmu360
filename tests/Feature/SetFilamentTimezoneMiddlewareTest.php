<?php

use App\Http\Middleware\SetFilamentTimezone;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * @return array{resolved_timezone: string, status_code: int}
 */
function runTimezoneMiddleware(?string $header = null, ?string $cookie = null, ?string $session = null, ?string $requestUserTimezoneInput = null): array
{
    $request = Request::create('/');
    $sessionStore = app('session')->driver();
    $sessionStore->start();

    if ($session !== null) {
        $sessionStore->put('user_timezone', $session);
    }

    $request->setLaravelSession($sessionStore);

    if ($header !== null) {
        $request->headers->set('X-Timezone', $header);
    }

    if ($cookie !== null) {
        $request->cookies->set('user_timezone', $cookie);
    }

    if ($requestUserTimezoneInput !== null) {
        $request->request->set('user_timezone', $requestUserTimezoneInput);
    }

    $response = app(SetFilamentTimezone::class)->handle(
        $request,
        static fn () => response('ok', 200),
    );

    return [
        'resolved_timezone' => (string) $request->session()->get('user_timezone'),
        'status_code' => $response->getStatusCode(),
    ];
}

it('stores timezone from X-Timezone header', function () {
    $result = runTimezoneMiddleware(header: 'Asia/Jakarta');

    expect($result['resolved_timezone'])->toBe('Asia/Jakarta');
    expect($result['status_code'])->toBe(200);
});

it('prioritizes X-Timezone header over cookie and session', function () {
    $result = runTimezoneMiddleware(
        header: 'Asia/Bangkok',
        cookie: 'Asia/Singapore',
        session: 'Asia/Manila',
    );

    expect($result['resolved_timezone'])->toBe('Asia/Bangkok');
});

it('uses user_timezone request input when header is missing', function () {
    $result = runTimezoneMiddleware(session: 'Asia/Manila', requestUserTimezoneInput: 'Asia/Tokyo');

    expect($result['resolved_timezone'])->toBe('Asia/Tokyo');
});

it('prioritizes X-Timezone header over user_timezone request input', function () {
    $result = runTimezoneMiddleware(
        header: 'Asia/Bangkok',
        cookie: 'Asia/Singapore',
        session: 'Asia/Manila',
        requestUserTimezoneInput: 'Asia/Tokyo',
    );

    expect($result['resolved_timezone'])->toBe('Asia/Bangkok');
});

it('uses cookie timezone when header is missing', function () {
    $result = runTimezoneMiddleware(cookie: 'Asia/Singapore', session: 'Asia/Manila');

    expect($result['resolved_timezone'])->toBe('Asia/Singapore');
});

it('uses existing session timezone when header and cookie are missing', function () {
    $result = runTimezoneMiddleware(session: 'Asia/Manila');

    expect($result['resolved_timezone'])->toBe('Asia/Manila');
});

it('falls back to app timezone when provided values are invalid', function () {
    config()->set('app.timezone', 'UTC');

    $result = runTimezoneMiddleware(
        header: 'Invalid/Header',
        cookie: 'Still/Invalid',
        session: 'Not/A-Real-Timezone',
    );

    expect($result['resolved_timezone'])->toBe('UTC');
});

it('prioritizes authenticated user timezone over cookie and session', function () {
    $user = User::factory()->create([
        'timezone' => 'Europe/London',
    ]);

    $this->actingAs($user);

    $result = runTimezoneMiddleware(
        cookie: 'Asia/Singapore',
        session: 'Asia/Manila',
    );

    expect($result['resolved_timezone'])->toBe('Europe/London');
});

it('uses request timezone when authenticated user timezone is null', function () {
    $user = User::factory()->create([
        'timezone' => null,
    ]);

    $this->actingAs($user);

    $result = runTimezoneMiddleware(cookie: 'Asia/Singapore');

    expect($result['resolved_timezone'])->toBe('Asia/Singapore');
});

it('does not persist fallback timezone to authenticated user when request timezone is missing', function () {
    config()->set('app.timezone', 'UTC');

    $user = User::factory()->create([
        'timezone' => null,
    ]);

    $this->actingAs($user);

    $result = runTimezoneMiddleware();

    expect($result['resolved_timezone'])->toBe('UTC');
    expect($user->fresh()?->timezone)->toBeNull();
});

it('persists user_timezone request input to authenticated user profile when null', function () {
    $user = User::factory()->create([
        'timezone' => null,
    ]);

    $this->actingAs($user);

    runTimezoneMiddleware(requestUserTimezoneInput: 'Asia/Tokyo');

    expect($user->fresh()?->timezone)->toBe('Asia/Tokyo');
});

it('persists resolved timezone to authenticated user profile when null', function () {
    $user = User::factory()->create([
        'timezone' => null,
    ]);

    $this->actingAs($user);

    runTimezoneMiddleware(header: 'Asia/Jakarta');

    expect($user->fresh()?->timezone)->toBe('Asia/Jakarta');
});
