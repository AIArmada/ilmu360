<?php

use App\Enums\EventVisibility;
use App\Models\Event;
use App\Services\Notifications\NotificationMessageRenderer;

it('returns fallback when definition is null', function () {
    $result = app(NotificationMessageRenderer::class)->renderDefinition(null, fallback: 'default message');

    expect($result)->toBe('default message');
});

it('returns fallback when key is missing', function () {
    $result = app(NotificationMessageRenderer::class)->renderDefinition([], fallback: 'fallback');

    expect($result)->toBe('fallback');
});

it('runs renderDefinition with a key and params', function () {
    $renderer = app(NotificationMessageRenderer::class);

    $result = $renderer->renderDefinition([
        'key' => 'auth.throttle',
        'params' => ['seconds' => 30],
    ]);

    expect($result)->toBeString()->not->toBeEmpty();
});

it('resolves backed enum values to their scalar value', function () {
    $renderer = app(NotificationMessageRenderer::class);

    $reflection = new ReflectionMethod($renderer, 'resolveValue');
    $reflection->setAccessible(true);

    $result = $reflection->invoke($renderer, EventVisibility::Public);

    expect($result)->toBe('public');
});

it('resolves plain values as-is', function () {
    $renderer = app(NotificationMessageRenderer::class);

    $reflection = new ReflectionMethod($renderer, 'resolveValue');
    $reflection->setAccessible(true);

    $result = $reflection->invoke($renderer, 'plain string');

    expect($result)->toBe('plain string');
});

it('resolves nested arrays recursively', function () {
    $renderer = app(NotificationMessageRenderer::class);

    $reflection = new ReflectionMethod($renderer, 'resolveValue');
    $reflection->setAccessible(true);

    $result = $reflection->invoke($renderer, [
        'name' => 'Test',
        'visibility' => EventVisibility::Public,
    ]);

    expect($result)->toBe([
        'name' => 'Test',
        'visibility' => 'public',
    ]);
});

it('formats event timing with user preferred timezone', function () {
    $renderer = app(NotificationMessageRenderer::class);

    $event = Event::factory()->create([
        'timezone' => 'Asia/Kuala_Lumpur',
    ]);

    $token = $renderer->eventTimingToken($event);

    expect($token['type'])->toBe('event_timing')
        ->and($token['timezone'])->toBe('Asia/Kuala_Lumpur');
});

it('returns to_be_confirmed when timing token has no starts_at', function () {
    $renderer = app(NotificationMessageRenderer::class);

    $reflection = new ReflectionMethod($renderer, 'formatEventTiming');
    $reflection->setAccessible(true);

    $result = $reflection->invoke($renderer, ['type' => 'event_timing', 'starts_at' => null, 'timezone' => null]);

    expect($result)->toBe(__('notifications.messages.timing.to_be_confirmed'));
});
