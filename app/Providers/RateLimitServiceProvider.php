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
        // Default API rate limit: 60 per minute
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));

        // Public search: 30 per minute per IP
        RateLimiter::for('search', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));

        // Event submission: 5 per hour per IP (anti-spam)
        RateLimiter::for('event-submission', fn (Request $request) => Limit::perHour(5)->by($request->ip()));

        // Registration: 10 per hour per IP
        RateLimiter::for('registration', fn (Request $request) => Limit::perHour(10)->by($request->ip()));

        // Report submission: 3 per hour per user/IP
        RateLimiter::for('reports', fn (Request $request) => Limit::perHour(3)->by($request->user()?->id ?: $request->ip()));

        // GitHub issue reporting: 3 per hour per user/IP
        RateLimiter::for('github-issues', fn (Request $request) => Limit::perHour(3)->by($request->user()?->id ?: $request->ip()));

        // Saved searches: 20 per minute
        RateLimiter::for('saved-searches', fn (Request $request) => Limit::perMinute(20)->by($request->user()?->id ?: $request->ip()));

        // Share tracking payloads can create guest-scoped affiliate records.
        RateLimiter::for('share-tracking', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));

        // Native mobile telemetry is batched and may be sent frequently by iOS/Android clients.
        RateLimiter::for('mobile-telemetry', function (Request $request) {
            $identifier = $request->user()?->id
                ?: trim((string) $request->input('anonymous_id'))
                ?: trim((string) $request->input('session_identifier'))
                ?: $request->ip();

            return Limit::perMinute(120)->by($identifier);
        });

        // Admin/Moderation: Higher limits for moderators
        RateLimiter::for('moderation', function (Request $request) {
            $user = $request->user();

            if ($user?->hasAnyRole(['super_admin', 'moderator'])) {
                return Limit::perMinute(200)->by($user->id);
            }

            return Limit::perMinute(60)->by($request->ip());
        });
    }
}
