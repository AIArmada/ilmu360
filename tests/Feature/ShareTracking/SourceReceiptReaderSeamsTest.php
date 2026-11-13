<?php

declare(strict_types=1);

// Rw2a seam: the lazy, ordered source-receipt key reader that the Rewards
// preclaim backfill consumes instead of importing the receipt model.

use App\Features\ShareTracking\Api\SourceReceiptReader;
use App\Features\ShareTracking\Persistence\DatabaseSourceReceiptReader;
use App\Models\ShareSourceAttributionReceipt;
use App\Shared\ShareTracking\ShareOutcomeBusinessKey;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function rw2aSourceReceipt(
    string $businessKey,
    CarbonImmutable $occurredAt,
    string $namespace = ShareOutcomeBusinessKey::NAMESPACE,
): ShareSourceAttributionReceipt {
    return ShareSourceAttributionReceipt::query()->create([
        'namespace' => $namespace,
        'outcome_type' => 'event_registration',
        'source_transition_id' => 'registration:'.Str::uuid(),
        'business_key' => $businessKey,
        'attribution_id' => null,
        'provenance' => ShareSourceAttributionReceipt::PROVENANCE_SYSTEM,
        'definition_version' => 'rw2a',
        'occurred_at' => $occurredAt,
    ]);
}

it('binds the source receipt reader Api to its persistence implementation', function (): void {
    expect(app(SourceReceiptReader::class))->toBeInstanceOf(DatabaseSourceReceiptReader::class);
});

it('reads only share-outcome keys inside the inclusive window in occurred_at then id order', function (): void {
    $from = CarbonImmutable::parse('2026-11-01 10:00:00', 'UTC');
    $to = CarbonImmutable::parse('2026-11-01 12:00:00', 'UTC');

    $atFrom = rw2aSourceReceipt(ShareOutcomeBusinessKey::eventRegistration((string) Str::uuid()), $from);
    $tieOne = rw2aSourceReceipt(ShareOutcomeBusinessKey::eventRegistration((string) Str::uuid()), CarbonImmutable::parse('2026-11-01 11:00:00', 'UTC'));
    $tieTwo = rw2aSourceReceipt(ShareOutcomeBusinessKey::eventRegistration((string) Str::uuid()), CarbonImmutable::parse('2026-11-01 11:00:00', 'UTC'));
    $atTo = rw2aSourceReceipt(ShareOutcomeBusinessKey::eventRegistration((string) Str::uuid()), $to);
    $before = rw2aSourceReceipt(ShareOutcomeBusinessKey::eventRegistration((string) Str::uuid()), CarbonImmutable::parse('2026-11-01 09:59:59', 'UTC'));
    $after = rw2aSourceReceipt(ShareOutcomeBusinessKey::eventRegistration((string) Str::uuid()), CarbonImmutable::parse('2026-11-01 12:00:01', 'UTC'));
    $otherNamespace = rw2aSourceReceipt(
        'activity:event_registration:registration:'.Str::uuid(),
        CarbonImmutable::parse('2026-11-01 11:00:00', 'UTC'),
        'activity',
    );

    $tieKeys = collect([$tieOne, $tieTwo])
        ->sortBy(static fn (ShareSourceAttributionReceipt $receipt): string => (string) $receipt->getKey())
        ->pluck('business_key')
        ->values()
        ->all();

    $keys = iterator_to_array(app(SourceReceiptReader::class)->keysBetween($from, $to), false);

    expect($keys)->toBe([
        $atFrom->business_key,
        ...$tieKeys,
        $atTo->business_key,
    ])
        ->and($keys)->not->toContain($before->business_key)
        ->and($keys)->not->toContain($after->business_key)
        ->and($keys)->not->toContain($otherNamespace->business_key);
});

it('defers the receipt query until the returned iterable is consumed', function (): void {
    $from = CarbonImmutable::parse('2026-11-01 08:00:00', 'UTC');
    $to = CarbonImmutable::parse('2026-11-01 12:00:00', 'UTC');
    $existing = rw2aSourceReceipt(
        ShareOutcomeBusinessKey::eventRegistration((string) Str::uuid()),
        CarbonImmutable::parse('2026-11-01 10:00:00', 'UTC'),
    );

    $keys = app(SourceReceiptReader::class)->keysBetween($from, $to);

    expect($keys)->toBeInstanceOf(Generator::class);

    $inserted = rw2aSourceReceipt(
        ShareOutcomeBusinessKey::eventRegistration((string) Str::uuid()),
        CarbonImmutable::parse('2026-11-01 09:00:00', 'UTC'),
    );

    expect(iterator_to_array($keys, false))->toBe([
        $inserted->business_key,
        $existing->business_key,
    ]);
});
