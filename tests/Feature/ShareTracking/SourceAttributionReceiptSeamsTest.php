<?php

declare(strict_types=1);

use App\Features\ShareTracking\Api\SourceAttributionReceipts;
use App\Features\ShareTracking\Persistence\DatabaseSourceAttributionReceipts;
use App\Models\ShareSourceAttributionReceipt;
use App\Platform\FeatureCatalog;
use App\Services\ShareTracking\AffiliatesShareTrackingService;
use App\Shared\ShareTracking\ShareOutcomeBusinessKey;
use App\Shared\ShareTracking\ShareOutcomeType;
use AIArmada\Rewards\Jobs\EvaluateRewardEntitlementJob;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
});

/**
 * @return array{business_key: string, source_transition_id: string}
 */
function sourceReceiptCanonicalKey(ShareOutcomeType $type = ShareOutcomeType::EventRegistration): array
{
    $sourceTransitionId = $type === ShareOutcomeType::Signup
        ? 'user:'.Str::uuid()
        : 'registration:'.Str::uuid();

    $businessKey = ShareOutcomeBusinessKey::for($type, $sourceTransitionId);
    $parsed = ShareOutcomeBusinessKey::parse($businessKey, $type);

    return [
        'business_key' => $businessKey,
        'source_transition_id' => $parsed['source_transition_id'],
    ];
}

it('discovers the source receipt table claim with its persistence writer', function (): void {
    $catalog = FeatureCatalog::load(base_path());
    $manifest = json_decode((string) file_get_contents(app_path('Features/ShareTracking/feature.json')), true, 512, JSON_THROW_ON_ERROR);

    expect($catalog['features'])->toHaveKey('ShareTracking')
        ->and($catalog['owners']['table:share_source_attribution_receipts'] ?? null)->toBe('ShareTracking')
        ->and($manifest['owns']['table:share_source_attribution_receipts'] ?? null)->toBe([
            DatabaseSourceAttributionReceipts::class,
        ])
        ->and($catalog['writes'][DatabaseSourceAttributionReceipts::class] ?? null)->toContain('table:share_source_attribution_receipts');
});

it('binds the source receipt Api to its persistence implementation', function (): void {
    expect(app(SourceAttributionReceipts::class))->toBeInstanceOf(DatabaseSourceAttributionReceipts::class)
        ->and((new ShareSourceAttributionReceipt)->getMorphClass())->toBe('share_source_attribution_receipt');
});

it('freezes an unattributed receipt with the exact row shape', function (): void {
    $key = sourceReceiptCanonicalKey();
    $occurredAt = CarbonImmutable::parse('2026-11-01 12:34:56', 'UTC');

    $snapshot = app(SourceAttributionReceipts::class)->freezeUnattributed(
        type: ShareOutcomeType::EventRegistration,
        businessKey: $key['business_key'],
        sourceTransitionId: $key['source_transition_id'],
        provenance: ShareSourceAttributionReceipt::PROVENANCE_COOKIE,
        definitionVersion: 'wp1',
        occurredAt: $occurredAt,
    );

    $receipt = ShareSourceAttributionReceipt::query()
        ->where('business_key', $key['business_key'])
        ->sole();

    expect($receipt->namespace)->toBe(ShareOutcomeBusinessKey::NAMESPACE)
        ->and($receipt->outcome_type)->toBe(ShareOutcomeType::EventRegistration->value)
        ->and($receipt->source_transition_id)->toBe($key['source_transition_id'])
        ->and($receipt->business_key)->toBe($key['business_key'])
        ->and($receipt->attribution_id)->toBeNull()
        ->and($receipt->provenance)->toBe(ShareSourceAttributionReceipt::PROVENANCE_COOKIE)
        ->and($receipt->definition_version)->toBe('wp1')
        ->and($receipt->occurred_at->equalTo($occurredAt))->toBeTrue()
        ->and($snapshot->isAttributed())->toBeFalse()
        ->and($snapshot->attributionId)->toBeNull()
        ->and($snapshot->businessKey)->toBe($key['business_key'])
        ->and($snapshot->sourceTransitionId)->toBe($key['source_transition_id'])
        ->and($snapshot->outcomeType)->toBe(ShareOutcomeType::EventRegistration->value)
        ->and($snapshot->provenance)->toBe(ShareSourceAttributionReceipt::PROVENANCE_COOKIE)
        ->and($snapshot->definitionVersion)->toBe('wp1')
        ->and($snapshot->occurredAt)->toStartWith('2026-11-01T12:34:56');

    Queue::assertPushed(
        EvaluateRewardEntitlementJob::class,
        fn (EvaluateRewardEntitlementJob $job): bool => $job->businessKey === $key['business_key'],
    );
});

it('replays the first committed decision even when a later freeze is attributed', function (): void {
    $key = sourceReceiptCanonicalKey();
    $occurredAt = CarbonImmutable::parse('2026-11-02 08:00:00', 'UTC');
    $receipts = app(SourceAttributionReceipts::class);

    $first = $receipts->freezeUnattributed(
        type: ShareOutcomeType::EventRegistration,
        businessKey: $key['business_key'],
        sourceTransitionId: $key['source_transition_id'],
        provenance: ShareSourceAttributionReceipt::PROVENANCE_COOKIE,
        definitionVersion: 'wp1',
        occurredAt: $occurredAt,
    );

    $replayed = $receipts->freezeAttributed(
        type: ShareOutcomeType::EventRegistration,
        businessKey: $key['business_key'],
        sourceTransitionId: $key['source_transition_id'],
        attributionId: (string) Str::uuid(),
        provenance: ShareSourceAttributionReceipt::PROVENANCE_SYSTEM,
        definitionVersion: 'wp1',
        occurredAt: $occurredAt->addMinute(),
    );

    $receiptsInTable = ShareSourceAttributionReceipt::query()->get();

    expect($replayed->id)->toBe($first->id)
        ->and($replayed->isAttributed())->toBeFalse()
        ->and($replayed->attributionId)->toBeNull()
        ->and($replayed->businessKey)->toBe($key['business_key'])
        ->and($receiptsInTable)->toHaveCount(1)
        ->and($receiptsInTable->first()->attribution_id)->toBeNull()
        ->and($receiptsInTable->first()->provenance)->toBe(ShareSourceAttributionReceipt::PROVENANCE_COOKIE);
});

it('freezes an attributed receipt on a fresh canonical key', function (): void {
    $key = sourceReceiptCanonicalKey();
    $occurredAt = CarbonImmutable::parse('2026-11-03 09:15:00', 'UTC');
    $attributionId = (string) Str::uuid();

    $snapshot = app(SourceAttributionReceipts::class)->freezeAttributed(
        type: ShareOutcomeType::EventRegistration,
        businessKey: $key['business_key'],
        sourceTransitionId: $key['source_transition_id'],
        attributionId: $attributionId,
        provenance: ShareSourceAttributionReceipt::PROVENANCE_REGISTRATION,
        definitionVersion: 'wp1',
        occurredAt: $occurredAt,
    );

    $receipt = ShareSourceAttributionReceipt::query()
        ->where('business_key', $key['business_key'])
        ->sole();

    expect($snapshot->isAttributed())->toBeTrue()
        ->and($snapshot->attributionId)->toBe($attributionId)
        ->and($receipt->attribution_id)->toBe($attributionId)
        ->and($receipt->provenance)->toBe(ShareSourceAttributionReceipt::PROVENANCE_REGISTRATION)
        ->and($receipt->definition_version)->toBe('wp1')
        ->and($receipt->occurred_at->equalTo($occurredAt))->toBeTrue();
});

it('moves the freeze writers out of the root service', function (): void {
    $reflection = new ReflectionClass(AffiliatesShareTrackingService::class);

    expect($reflection->hasMethod('freezeAttributedReceipt'))->toBeFalse()
        ->and($reflection->hasMethod('freezeUnattributedReceipt'))->toBeFalse()
        ->and(app(AffiliatesShareTrackingService::class))->toBeInstanceOf(AffiliatesShareTrackingService::class);
});
