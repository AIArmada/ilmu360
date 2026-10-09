<?php

declare(strict_types=1);

use AIArmada\Affiliates\Models\AffiliateLink;
use AIArmada\Communications\Contracts\PayloadRedactor;
use App\Models\User;
use App\Services\ShareTrackingService;
use App\Support\Communications\ListSafePayloadRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->sharer = User::factory()->create([
        'name' => 'WP3 Redaction Sharer',
        'email' => 'wp3-redaction-sharer@example.test',
    ]);
});

/**
 * Issue a real anonymous share through current source: the capability and
 * credential below are genuine 32-character secrets, asserted absent from
 * every redacted surface but never printed.
 *
 * @return array{capability: string, credential: string, token: string}
 */
function wp3crGuestShare(): array
{
    $payload = app(ShareTrackingService::class)->sharePayload(
        null,
        route('events.index'),
        'Share this page',
        'Shared Page',
        'web',
        Request::create(route('events.index'), 'GET')
    );

    $link = AffiliateLink::query()->where('custom_slug', $payload['tracking_token'])->firstOrFail();

    expect($link->affiliate_id)->not->toBeNull();

    return [
        'capability' => $payload['guest_claim_capability'],
        'credential' => $payload['guest_credential'],
        'token' => $payload['tracking_token'],
    ];
}

it('redacts the claim capability across its canonical transports through the bound contract', function (): void {
    $redactor = app(PayloadRedactor::class);

    expect($redactor)->toBeInstanceOf(ListSafePayloadRedactor::class);

    $secrets = wp3crGuestShare();
    $capability = $secrets['capability'];
    $credential = $secrets['credential'];

    $header = (string) config('dawah-share.claim_capability.header', 'X-Dawah-Claim-Capability');
    $input = (string) config('dawah-share.claim_capability.input', 'guest_claim_capability');
    $cookie = (string) config('dawah-share.claim_cookie.name', 'mi_dawah_guest_claim');
    $guestCookie = (string) config('dawah-share.guest_cookie.name', 'mi_dawah_guest');

    // Every canonical issuance/POST/header/cookie shape, nested forms, and
    // the real HeaderBag::all() array-valued header shape.
    $sample = [
        $input => $capability,
        $header => $capability,
        $cookie => $capability,
        'capability_verifier' => hash('sha256', 'probe'),
        'headers' => [
            mb_strtolower($header) => [$capability],
            'x-request-id' => ['req-1'],
        ],
        'nested' => [$input => $capability],
        $guestCookie => $credential,
        'guest_credential' => $credential,
        'provider' => 'copy_link',
        'origin' => 'web',
        'channel' => 'whatsapp',
        'claim_status' => 'available',
        'capability_matrix' => ['render', 'preview'],
        'matched_labels' => ['Kuliah Subuh', 'Kuliah Maghrib'],
    ];

    foreach (['redact', 'redactRequest', 'redactResponse'] as $method) {
        $clean = $redactor->{$method}($sample);

        expect($clean[$input])->toBe('**[REDACTED]**')
            ->and($clean[$header])->toBe('**[REDACTED]**')
            ->and($clean[$cookie])->toBe('**[REDACTED]**')
            ->and($clean['capability_verifier'])->toBe('**[REDACTED]**')
            ->and($clean['headers'][mb_strtolower($header)])->toBe('**[REDACTED]**')
            ->and($clean['headers']['x-request-id'])->toBe(['req-1'])
            ->and($clean['nested'][$input])->toBe('**[REDACTED]**')
            ->and($clean[$guestCookie])->toBe('**[REDACTED]**')
            ->and($clean['guest_credential'])->toBe('**[REDACTED]**')
            ->and($clean['provider'])->toBe('copy_link')
            ->and($clean['origin'])->toBe('web')
            ->and($clean['channel'])->toBe('whatsapp')
            ->and($clean['claim_status'])->toBe('available')
            ->and($clean['capability_matrix'])->toBe(['render', 'preview'])
            ->and($clean['matched_labels'])->toBe(['Kuliah Subuh', 'Kuliah Maghrib']);

        $sweep = json_encode($clean, JSON_THROW_ON_ERROR);

        expect($sweep)->not->toContain($capability)
            ->and($sweep)->not->toContain($credential);
    }
});

it('redacts sensitive array-valued subtrees before visiting their values', function (): void {
    $redactor = app(PayloadRedactor::class);

    // Package-sensitive parents with array values redact wholesale rather
    // than leaking through recursion; nonsensitive lists still pass.
    $clean = $redactor->redactRequest([
        'token' => ['inner-secret-value'],
        'password' => ['nested' => 'hunter2'],
        'utm_campaign' => ['ramadan'],
    ]);

    expect($clean['token'])->toBe('**[REDACTED]**')
        ->and($clean['password'])->toBe('**[REDACTED]**')
        ->and($clean['utm_campaign'])->toBe(['ramadan']);

    expect(json_encode($clean, JSON_THROW_ON_ERROR))->not->toContain('inner-secret-value');
});

it('keeps issued claim secrets out of shared payload surfaces', function (): void {
    $payload = $this->getJson(route('dawah-share.payload', [
        'url' => route('events.index'),
        'text' => 'Share this page',
        'title' => 'Shared Page',
    ]))->assertOk()->json();

    $capability = (string) $payload['guest_claim_capability'];
    $credential = (string) $payload['guest_credential'];

    expect($capability)->not->toBe('')
        ->and($credential)->not->toBe('');

    expect((string) $payload['url'])->not->toContain($capability)
        ->and((string) $payload['url'])->not->toContain($credential);

    foreach (['platform_links', 'channel_urls'] as $group) {
        foreach ((array) $payload[$group] as $name => $value) {
            expect((string) $value)->not->toContain($capability, "capability leaked into {$group}.{$name}")
                ->and((string) $value)->not->toContain($credential, "credential leaked into {$group}.{$name}");
        }
    }

    expect((string) data_get($payload, 'copy_link.url'))->not->toContain($capability);

    foreach (['title', 'text', 'url', 'message'] as $key) {
        expect((string) $payload['native_share'][$key])->not->toContain($capability, "capability leaked into native_share.{$key}");
    }

    $sweep = $payload;

    unset($sweep['guest_credential'], $sweep['guest_claim_capability']);

    $encoded = json_encode($sweep, JSON_THROW_ON_ERROR);

    expect($encoded)->not->toContain($capability)
        ->and($encoded)->not->toContain($credential);
});
