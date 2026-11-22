<?php

declare(strict_types=1);

use AIArmada\CommerceSupport\Http\PinnedHttpClient;
use AIArmada\CommerceSupport\Support\PublicHttpUrlGuard;
use AIArmada\CommerceSupport\Support\ValidatedHttpTarget;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

pest()->extend(TestCase::class);

it('rejects empty, invalid-scheme, credentialed, private, loopback, cgnat, mapped-ipv6, and non-standard port urls', function (string $unsafeUrl): void {
    $guard = new PublicHttpUrlGuard(static fn (string $host): array => match ($host) {
        'private.example.test' => ['10.0.0.15'],
        'metadata.example.test' => ['169.254.169.254'],
        'cgnat.example.test' => ['100.64.10.2'],
        'mapped.example.test' => ['::ffff:127.0.0.1'],
        'empty.example.test' => [],
        default => ['93.184.216.34'],
    });

    expect(fn () => $guard->assertAllowed($unsafeUrl))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'empty string' => [''],
    'ftp scheme' => ['ftp://example.com/file.png'],
    'file scheme' => ['file:///etc/passwd'],
    'javascript scheme' => ['javascript:alert(1)'],
    'userinfo credentials' => ['https://user:secret@example.com/resource'],
    'localhost' => ['http://localhost/admin'],
    'subdomain localhost' => ['http://api.localhost/v1'],
    'internal domain' => ['http://service.internal/metrics'],
    'custom port' => ['https://example.com:8443/webhook'],
    'loopback ipv4' => ['http://127.0.0.1/status'],
    'metadata ipv4' => ['http://169.254.169.254/latest/meta-data/'],
    'cgnat ipv4' => ['http://100.64.0.5/internal'],
    'private ipv4' => ['http://192.168.1.1/login'],
    'this network' => ['http://0.1.2.3/'],
    'mapped ipv6 loopback' => ['http://[::ffff:127.0.0.1]/'],
    'dns private' => ['https://private.example.test/page'],
    'dns metadata' => ['https://metadata.example.test/page'],
    'dns cgnat' => ['https://cgnat.example.test/page'],
    'dns mapped ipv6' => ['https://mapped.example.test/page'],
    'unresolvable host' => ['https://empty.example.test/page'],
]);

it('resolves and pins public http targets without duplicate dns lookups', function (): void {
    $guard = new PublicHttpUrlGuard(static fn (string $host): array => $host === 'example.com' ? ['93.184.216.34'] : []);

    $pinned = $guard->validate('https://Example.COM/assets/logo.png');

    expect($pinned)->toBeInstanceOf(ValidatedHttpTarget::class)
        ->and($pinned->url)->toBe('https://example.com/assets/logo.png')
        ->and($pinned->host)->toBe('example.com')
        ->and($pinned->port)->toBe(443)
        ->and($pinned->selectedIp)->toBe('93.184.216.34')
        ->and($pinned->curlResolveEntry())->toBe('example.com:443:93.184.216.34');
});

it('follows safe public redirects and rejects redirects to internal addresses', function (): void {
    $guard = new PublicHttpUrlGuard(static fn (string $host): array => match ($host) {
        'start.example.com', 'final.example.org' => ['93.184.216.34'],
        default => [],
    });
    $client = new PinnedHttpClient;

    Http::fake([
        'https://start.example.com/go' => Http::response('', 302, ['Location' => 'https://final.example.org/done']),
        'https://final.example.org/done' => Http::response('ok', 200),
    ]);

    $response = $client->sendFollowingRedirects(
        guard: $guard,
        method: 'GET',
        url: 'https://start.example.com/go',
        configure: static function (PendingRequest $request): void {
            $request->timeout(5);
        },
    );

    expect($response->status())->toBe(200)
        ->and($response->body())->toBe('ok');

    Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://final.example.org/done');

    Http::fake([
        'https://start.example.com/evil' => Http::response('', 302, ['Location' => 'http://127.0.0.1/internal']),
    ]);

    expect(fn () => $client->sendFollowingRedirects(guard: $guard, method: 'GET', url: 'https://start.example.com/evil'))
        ->toThrow(InvalidArgumentException::class);
});
