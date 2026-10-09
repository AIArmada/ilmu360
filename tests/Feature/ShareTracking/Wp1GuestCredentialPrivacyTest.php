<?php

declare(strict_types=1);

use AIArmada\Communications\Contracts\PayloadRedactor;
use App\Models\Event;
use App\Models\User;
use App\Support\Communications\ListSafePayloadRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->sharer = User::factory()->create([
        'name' => 'WP1 Guest Privacy Sharer',
        'email' => 'wp1-guest-privacy-sharer@example.test',
    ]);

    $this->event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
    ]);
});

function wp1GuestPayloadParams(TestCase $testCase): array
{
    return [
        'url' => route('events.show', $testCase->event),
        'text' => 'Share this page',
        'title' => 'Shared Page',
    ];
}

it('serves share payloads with private no-store cache headers', function (): void {
    $guest = $this->getJson(route('dawah-share.payload', wp1GuestPayloadParams($this)))->assertOk();

    // Symfony normalizes directive order; both directives must be present.
    expect($guest->headers->get('Cache-Control'))->toContain('private')
        ->and($guest->headers->get('Cache-Control'))->toContain('no-store')
        ->and($guest->headers->get('Pragma'))->toBe('no-cache')
        ->and($guest->json('guest_credential'))->toBeString()->not->toBe('');

    $authed = $this->actingAs($this->sharer)
        ->getJson(route('dawah-share.payload', wp1GuestPayloadParams($this)))
        ->assertOk();

    expect($authed->headers->get('Cache-Control'))->toContain('private')
        ->and($authed->headers->get('Cache-Control'))->toContain('no-store')
        ->and($authed->headers->get('Pragma'))->toBe('no-cache');
});

it('keeps the issued guest credential out of share urls platform links and native data', function (): void {
    $payload = $this->getJson(route('dawah-share.payload', wp1GuestPayloadParams($this)))
        ->assertOk()
        ->json();

    $credential = (string) ($payload['guest_credential'] ?? '');

    expect($credential)->not->toBe('');

    expect((string) $payload['url'])->not->toContain($credential)
        ->and((string) $payload['url'])->not->toContain('guest_credential');

    foreach (['platform_links', 'channel_urls'] as $group) {
        foreach ((array) $payload[$group] as $name => $value) {
            expect((string) $value)->not->toContain($credential, "credential leaked into {$group}.{$name}");
        }
    }

    expect((string) data_get($payload, 'copy_link.url'))->not->toContain($credential);

    foreach (['title', 'text', 'url', 'message'] as $key) {
        expect((string) $payload['native_share'][$key])->not->toContain($credential, "credential leaked into native_share.{$key}");
    }

    $sweep = $payload;
    unset($sweep['guest_credential']);

    expect(json_encode($sweep, JSON_THROW_ON_ERROR))->not->toContain($credential);
});

it('redacts guest credential transports through the central redactor', function (): void {
    $redactor = app(PayloadRedactor::class);

    expect($redactor)->toBeInstanceOf(ListSafePayloadRedactor::class);

    $header = (string) config('dawah-share.guest_credential.header', 'X-Dawah-Guest-Credential');
    $input = (string) config('dawah-share.guest_credential.input', 'guest_credential');

    $sample = [
        $input => 'wp1-secret-credential-value',
        $header => 'wp1-secret-credential-value',
        'credential_verifier' => 'wp1-verifier-value',
        'nested' => [$input => 'wp1-nested-secret'],
        'provider' => 'copy_link',
        'origin' => 'web',
        'matched_labels' => ['Kuliah Subuh', 'Kuliah Maghrib'],
    ];

    foreach (['redact', 'redactRequest', 'redactResponse'] as $method) {
        $clean = $redactor->{$method}($sample);

        expect($clean[$input])->toBe('**[REDACTED]**')
            ->and($clean[$header])->toBe('**[REDACTED]**')
            ->and($clean['credential_verifier'])->toBe('**[REDACTED]**')
            ->and($clean['nested'][$input])->toBe('**[REDACTED]**')
            ->and($clean['provider'])->toBe('copy_link')
            ->and($clean['origin'])->toBe('web')
            ->and($clean['matched_labels'])->toBe(['Kuliah Subuh', 'Kuliah Maghrib']);
    }

    $packageCovered = $redactor->redact(['password' => 'hunter2', 'token' => 'abc']);

    expect($packageCovered)->toBe(['password' => '**[REDACTED]**', 'token' => '**[REDACTED]**']);
});
