<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class RateLimitServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    #[\Override]
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     * Configures rate limits per documentation B9e.
     */
    public function boot(): void
    {
        // Public search: 30 per minute per IP
        RateLimiter::for('search', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));

        RateLimiter::for('event-submission', function (Request $request): Limit {
            $authenticated = $request->user() !== null || $request->user('sanctum') !== null;

            $audience = $authenticated ? 'authenticated' : 'guest';

            return Limit::perHour($authenticated ? 20 : 5)->by($audience.':'.$request->ip());
        });

        RateLimiter::for('event-media-extraction', fn (Request $request): Limit => Limit::perHour(5)->by($request->ip()));

        // Registration: 10 per hour per IP
        RateLimiter::for('registration', fn (Request $request) => Limit::perHour(10)->by($request->ip()));

        // Report submission: 3 per hour per user/IP
        RateLimiter::for('reports', fn (Request $request) => Limit::perHour(3)->by($request->user()?->id ?: $request->ip()));

        // GitHub issue reporting: 3 per hour per user/IP
        RateLimiter::for('github-issues', fn (Request $request) => Limit::perHour(3)->by($request->user()?->id ?: $request->ip()));

        // Share tracking payloads can create guest-scoped affiliate records.
        RateLimiter::for('share-tracking', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));

        // Guest-share claims prove ownership with a bearer capability: tight
        // ceiling keyed by both account and network address.
        RateLimiter::for('share-claim', fn (Request $request) => Limit::perMinute(10)->by(($request->user()?->id ?: 'guest').'|'.$request->ip()));

        // Native mobile telemetry is batched and may be sent frequently by iOS/Android clients.
        RateLimiter::for('mobile-telemetry', function (Request $request) {
            $identifier = $request->user()?->id
                ?: trim((string) $request->input('anonymous_id'))
                ?: trim((string) $request->input('session_identifier'))
                ?: $request->ip();

            return Limit::perMinute(120)->by($identifier);
        });
    }
}
