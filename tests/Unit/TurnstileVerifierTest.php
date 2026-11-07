<?php

use App\Services\Captcha\TurnstileVerifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    config()->set('services.turnstile.enabled', true);
    config()->set('services.turnstile.site_key', 'test-site-key');
    config()->set('services.turnstile.secret_key', 'test-secret-key');
    config()->set('services.turnstile.verify_url', 'https://challenges.cloudflare.com/turnstile/v0/siteverify');
    config()->set('services.turnstile.expected_action', 'submit_event');
    config()->set('services.turnstile.hostnames', ['ilmu360.test']);
});

it('accepts a token that matches the expected action and hostname', function (): void {
    Http::fake([
        'challenges.cloudflare.com/*' => Http::response([
            'success' => true,
            'action' => 'submit_event',
            'hostname' => 'ilmu360.test',
        ]),
    ]);

    expect(app(TurnstileVerifier::class)->verify('valid-token', '203.0.113.10'))->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
        && ($request->data()['secret'] ?? null) === 'test-secret-key'
        && ($request->data()['response'] ?? null) === 'valid-token'
        && ($request->data()['remoteip'] ?? null) === '203.0.113.10');
});

it('rejects a token from a different action', function (): void {
    Http::fake([
        'challenges.cloudflare.com/*' => Http::response([
            'success' => true,
            'action' => 'login',
            'hostname' => 'ilmu360.test',
        ]),
    ]);

    expect(app(TurnstileVerifier::class)->verify('valid-token'))->toBeFalse();
});

it('rejects a token from an unapproved hostname', function (): void {
    Http::fake([
        'challenges.cloudflare.com/*' => Http::response([
            'success' => true,
            'action' => 'submit_event',
            'hostname' => 'evil.test',
        ]),
    ]);

    expect(app(TurnstileVerifier::class)->verify('valid-token'))->toBeFalse();
});

it('fails closed when the expected action or hostnames are missing', function (): void {
    Http::fake();

    config()->set('services.turnstile.expected_action', null);

    expect(app(TurnstileVerifier::class)->verify('valid-token'))->toBeFalse();

    config()->set('services.turnstile.expected_action', 'submit_event');
    config()->set('services.turnstile.hostnames', []);

    expect(app(TurnstileVerifier::class)->verify('valid-token'))->toBeFalse();

    Http::assertNothingSent();
});

it('rejects blank and oversized tokens without calling siteverify', function (): void {
    Http::fake();

    $verifier = app(TurnstileVerifier::class);

    expect($verifier->verify(null))->toBeFalse()
        ->and($verifier->verify(''))->toBeFalse()
        ->and($verifier->verify(str_repeat('a', 2049)))->toBeFalse();

    Http::assertNothingSent();
});

it('rejects when siteverify is unavailable', function (): void {
    Http::fake(fn () => throw new ConnectionException('siteverify unavailable'));

    expect(app(TurnstileVerifier::class)->verify('valid-token'))->toBeFalse();
});

it('treats disabled turnstile as verified', function (): void {
    config()->set('services.turnstile.enabled', false);

    expect(app(TurnstileVerifier::class)->verify(null))->toBeTrue();
});
