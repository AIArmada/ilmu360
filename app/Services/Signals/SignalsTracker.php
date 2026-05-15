<?php

declare(strict_types=1);

namespace App\Services\Signals;

use AIArmada\Signals\Models\TrackedProperty;
use App\Support\Signals\ProductSignalsSurfaceResolver;

final readonly class SignalsTracker
{
    public function __construct(
        private ProductSignalsSurfaceResolver $surfaceResolver,
    ) {}

    public function defaultTrackedProperty(): ?TrackedProperty
    {
        return $this->trackedPropertyForSurface('public');
    }

    public function trackedPropertyForSurface(string $surface): ?TrackedProperty
    {
        return $this->surfaceResolver->resolveOrCreateTrackedProperty($surface);
    }

    /**
     * @return array{anonymous_cookie_name: string, endpoint: string, event_endpoint: string, identify_endpoint: string, script_url: string, session_cookie_name: string, write_key: string}|null
     */
    public function trackerConfig(string $surface = 'public'): ?array
    {
        $trackedProperty = $this->trackedPropertyForSurface($surface);

        if (! $trackedProperty instanceof TrackedProperty) {
            return null;
        }

        $router = app('router');

        if (! $router->has('signals.tracker.script') || ! $router->has('signals.collect.event') || ! $router->has('signals.collect.identify') || ! $router->has('signals.collect.pageview')) {
            return null;
        }

        return [
            'anonymous_cookie_name' => (string) config('product-signals.identity.anonymous_cookie', 'mi_signals_anonymous_id'),
            'endpoint' => route('signals.collect.pageview'),
            'event_endpoint' => route('signals.collect.event'),
            'identify_endpoint' => route('signals.collect.identify'),
            'script_url' => route('signals.tracker.script'),
            'session_cookie_name' => (string) config('product-signals.identity.session_cookie', 'mi_signals_session_id'),
            'write_key' => $trackedProperty->write_key,
        ];
    }
}
