<?php

use App\Features\Communications\EntryPoints\Livewire\NotificationBell;
use App\Models\User;
use App\Platform\Time\Http\Middleware\SetFilamentTimezone;
use Filament\Events\ServingFilament;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;

function freshTimezoneSessionId(array $attributes): string
{
    $sessionId = Str::random(40);

    app('session')->driver()->getHandler()->write(
        $sessionId,
        json_encode($attributes, JSON_THROW_ON_ERROR),
    );

    return $sessionId;
}

function freshTimezoneGuardKey(): string
{
    $guard = app('auth')->guard('web');

    if (! $guard instanceof SessionGuard) {
        throw new RuntimeException('The default web guard must be a session guard.');
    }

    return $guard->getName();
}

/**
 * @param  array<string, string>  $headers
 * @param  array<string, string>  $unencryptedCookies
 */
function freshTimezoneProbe(
    TestCase $test,
    array $sessionAttributes,
    array $headers = [],
    array $unencryptedCookies = [],
    string $query = '',
): TestResponse {
    $request = $test->withCookie((string) config('session.cookie'), freshTimezoneSessionId($sessionAttributes));

    foreach ($unencryptedCookies as $name => $value) {
        $request = $request->withUnencryptedCookie($name, $value);
    }

    return $request->withHeaders($headers)->get('/_timezone-probe'.$query);
}

final class FreshTimezoneCapture
{
    public static ?string $timezone = null;

    public function handle(Request $request, Closure $next): Response
    {
        self::$timezone = FilamentTimezone::get();

        return $next($request);
    }
}

beforeEach(function (): void {
    RouteFacade::middleware('web')->get('/_timezone-probe', static fn (Request $request): array => [
        'timezone' => FilamentTimezone::get(),
        'session_timezone' => $request->session()->get('user_timezone'),
    ]);
});

it('applies the session timezone on a fresh web request', function () {
    $response = freshTimezoneProbe($this, ['user_timezone' => 'Asia/Tokyo']);

    $response->assertOk()->assertJson([
        'timezone' => 'Asia/Tokyo',
        'session_timezone' => 'Asia/Tokyo',
    ]);
});

it('prioritizes the X-Timezone header over a fresh session', function () {
    $response = freshTimezoneProbe($this, ['user_timezone' => 'Asia/Tokyo'], headers: ['X-Timezone' => 'Asia/Bangkok']);

    $response->assertOk()->assertJson([
        'timezone' => 'Asia/Bangkok',
        'session_timezone' => 'Asia/Bangkok',
    ]);
});

it('prioritizes user_timezone request input over a fresh session', function () {
    $response = freshTimezoneProbe($this, ['user_timezone' => 'Asia/Manila'], query: '?user_timezone=Asia/Tokyo');

    $response->assertOk()->assertJson([
        'timezone' => 'Asia/Tokyo',
        'session_timezone' => 'Asia/Tokyo',
    ]);
});

it('prioritizes the unencrypted timezone cookie over a fresh session', function () {
    $response = freshTimezoneProbe(
        $this,
        ['user_timezone' => 'Asia/Manila'],
        unencryptedCookies: ['user_timezone' => 'Asia/Singapore'],
    );

    $response->assertOk()->assertJson([
        'timezone' => 'Asia/Singapore',
        'session_timezone' => 'Asia/Singapore',
    ]);
});

it('falls back to the configured default user timezone when every candidate is invalid', function () {
    config()->set('app.default_user_timezone', 'Asia/Kuala_Lumpur');

    $response = freshTimezoneProbe(
        $this,
        ['user_timezone' => 'Not/A-Timezone'],
        headers: ['X-Timezone' => 'Invalid/Zone'],
        unencryptedCookies: ['user_timezone' => 'Still/Invalid'],
    );

    $response->assertOk()->assertJson([
        'timezone' => 'Asia/Kuala_Lumpur',
        'session_timezone' => 'Asia/Kuala_Lumpur',
    ]);
});

it('resolves the authenticated profile timezone from a fresh session', function () {
    $user = User::factory()->create(['timezone' => 'Asia/Jakarta']);

    $response = freshTimezoneProbe($this, [
        freshTimezoneGuardKey() => $user->getAuthIdentifier(),
        'user_timezone' => 'Asia/Tokyo',
    ]);

    $response->assertOk()->assertJson([
        'timezone' => 'Asia/Jakarta',
        'session_timezone' => 'Asia/Jakarta',
    ]);

    expect($user->fresh()?->timezone)->toBe('Asia/Jakarta');
});

it('adopts the header timezone on a fresh session whose profile has no timezone', function () {
    $user = User::factory()->create(['timezone' => null]);

    $response = freshTimezoneProbe(
        $this,
        [
            freshTimezoneGuardKey() => $user->getAuthIdentifier(),
            'user_timezone' => 'Asia/Tokyo',
        ],
        headers: ['X-Timezone' => 'Asia/Jakarta'],
    );

    $response->assertOk()->assertJson([
        'timezone' => 'Asia/Jakarta',
        'session_timezone' => 'Asia/Jakarta',
    ]);

    expect($user->fresh()?->timezone)->toBe('Asia/Jakarta');
});

it('does not persist a session-resolved timezone onto the profile', function () {
    $user = User::factory()->create(['timezone' => null]);

    $response = freshTimezoneProbe($this, [
        freshTimezoneGuardKey() => $user->getAuthIdentifier(),
        'user_timezone' => 'Asia/Manila',
    ]);

    $response->assertOk()->assertJson([
        'timezone' => 'Asia/Manila',
        'session_timezone' => 'Asia/Manila',
    ]);

    expect($user->fresh()?->timezone)->toBeNull();
});

it('sets the filament timezone before a panel serves a fresh session', function (string $routeName) {
    $captured = null;

    Event::listen(ServingFilament::class, function () use (&$captured): void {
        $captured = FilamentTimezone::get();
    });

    $this->withCookie((string) config('session.cookie'), freshTimezoneSessionId(['user_timezone' => 'Asia/Tokyo']))
        ->get(route($routeName))
        ->assertOk();

    expect($captured)->toBe('Asia/Tokyo');
})->with([
    'admin panel' => 'filament.admin.auth.login',
    'ahli panel' => 'filament.ahli.auth.login',
]);

it('applies the fresh session timezone on the shared livewire update endpoint', function () {
    $route = RouteFacade::getRoutes()->getByName('default-livewire.update');

    if (! $route instanceof Route) {
        throw new RuntimeException('The default Livewire update route is not registered.');
    }

    $route->middleware(FreshTimezoneCapture::class);
    $route->computedMiddleware = null;

    $snapshot = json_encode(Livewire::test(NotificationBell::class)->snapshot, JSON_THROW_ON_ERROR);

    FilamentTimezone::set();
    FreshTimezoneCapture::$timezone = null;

    $this->withCookie((string) config('session.cookie'), freshTimezoneSessionId(['user_timezone' => 'Asia/Tokyo']))
        ->withCredentials()
        ->withHeader('X-Livewire', '1')
        ->postJson(route('default-livewire.update'), [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => [],
                'calls' => [],
            ]],
        ])
        ->assertOk();

    expect(FreshTimezoneCapture::$timezone)->toBe('Asia/Tokyo')
        ->and(app(Router::class)->gatherRouteMiddleware($route))->toContain(SetFilamentTimezone::class);
});

it('keeps both filament panels on the default guard the timezone middleware resolves', function () {
    $defaultGuard = config('auth.defaults.guard');

    expect(Filament::getPanel('admin')->getAuthGuard())->toBe($defaultGuard)
        ->and(Filament::getPanel('ahli')->getAuthGuard())->toBe($defaultGuard);
});
