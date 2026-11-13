<?php

declare(strict_types=1);

use App\Actions\ShareTracking\DispatchShareEffectReceiptsAction;
use App\Features\ShareTracking\Api\EffectReceipts;
use App\Features\ShareTracking\Persistence\DatabaseEffectReceipts;
use App\Features\ShareTracking\Support\ShareTrackingModel;
use App\Models\ShareEffectReceipt;
use App\Platform\FeatureCatalog;
use App\Shared\ShareTracking\ShareEffectStatus;
use App\Shared\ShareTracking\ShareOutcomeBusinessKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function effectReceiptBusinessKey(): string
{
    return ShareOutcomeBusinessKey::eventSave((string) Str::uuid());
}

it('claims the effect receipt table for its single persistence writer', function (): void {
    $catalog = FeatureCatalog::load(base_path());
    $manifest = json_decode((string) file_get_contents(app_path('Features/ShareTracking/feature.json')), true, 512, JSON_THROW_ON_ERROR);

    $writers = array_keys(array_filter(
        $catalog['writes'],
        static fn (array $resources): bool => in_array('table:share_effect_receipts', $resources, true),
    ));

    expect($catalog['owners']['table:share_effect_receipts'] ?? null)->toBe('ShareTracking')
        ->and($manifest['owns']['table:share_effect_receipts'] ?? null)->toBe([
            DatabaseEffectReceipts::class,
        ])
        ->and($writers)->toBe([DatabaseEffectReceipts::class])
        ->and($catalog['writes'][DatabaseEffectReceipts::class] ?? null)->toContain('table:share_effect_receipts');
});

it('binds the effect receipt Api and resolves the root model through its morph alias', function (): void {
    expect(app(EffectReceipts::class))->toBeInstanceOf(DatabaseEffectReceipts::class)
        ->and(ShareTrackingModel::effectReceipt())->toBe(ShareEffectReceipt::class)
        ->and((new ShareEffectReceipt)->getMorphClass())->toBe('share_effect_receipt');
});

it('freezes the projection receipt once with the exact row shape', function (): void {
    $businessKey = effectReceiptBusinessKey();
    $conversionId = (string) Str::uuid();
    $affiliateId = (string) Str::uuid();
    $linkId = (string) Str::uuid();
    $receipts = app(EffectReceipts::class);

    $snapshot = $receipts->ensureProjection(
        businessKey: $businessKey,
        conversionId: $conversionId,
        affiliateId: $affiliateId,
        linkId: $linkId,
    );

    $receipt = ShareEffectReceipt::query()->where('effect_key', $businessKey.':projection')->sole();

    expect($receipt->effect_key)->toBe($businessKey.':projection')
        ->and($receipt->source_type)->toBe(ShareOutcomeBusinessKey::NAMESPACE)
        ->and($receipt->source_id)->toBe($businessKey)
        ->and($receipt->effect_type)->toBe('projection')
        ->and($receipt->status)->toBe(ShareEffectStatus::Pending)
        ->and($receipt->payload)->toBe([
            'conversion_id' => $conversionId,
            'affiliate_id' => $affiliateId,
            'link_id' => $linkId,
        ])
        ->and((int) $receipt->attempts)->toBe(0)
        ->and($receipt->lease_token)->toBeNull()
        ->and($receipt->leased_by)->toBeNull()
        ->and($receipt->lease_expires_at)->toBeNull()
        ->and($receipt->succeeded_at)->toBeNull()
        ->and($receipt->failed_at)->toBeNull()
        ->and($receipt->cancelled_at)->toBeNull()
        ->and($snapshot->id)->toBe((string) $receipt->getKey())
        ->and($snapshot->status)->toBe(ShareEffectStatus::Pending->value)
        ->and($snapshot->payload)->toBe($receipt->payload);

    // Freeze-once: a replay with a different payload keeps the first row.
    $replayed = $receipts->ensureProjection(
        businessKey: $businessKey,
        conversionId: (string) Str::uuid(),
        affiliateId: (string) Str::uuid(),
        linkId: (string) Str::uuid(),
    );

    expect($replayed->id)->toBe($snapshot->id)
        ->and($replayed->payload)->toBe($snapshot->payload)
        ->and(ShareEffectReceipt::query()->count())->toBe(1);
});

it('creates the ticket reversal receipt once by its effect key', function (): void {
    $effectKey = 'ticket-affiliate-reversal:'.hash('sha256', (string) Str::uuid());
    $registrationId = (string) Str::uuid();
    $conversionId = (string) Str::uuid();
    $orderId = (string) Str::uuid();
    $receipts = app(EffectReceipts::class);

    $snapshot = $receipts->ensureTicketReversal(
        effectKey: $effectKey,
        registrationId: $registrationId,
        conversionId: $conversionId,
        orderId: $orderId,
        reason: 'registration_refunded',
    );

    $replayed = $receipts->ensureTicketReversal(
        effectKey: $effectKey,
        registrationId: (string) Str::uuid(),
        conversionId: (string) Str::uuid(),
        orderId: (string) Str::uuid(),
        reason: 'changed_reason',
    );

    $receipt = ShareEffectReceipt::query()->where('effect_key', $effectKey)->sole();

    expect($snapshot->id)->toBe((string) $receipt->getKey())
        ->and($replayed->id)->toBe($snapshot->id)
        ->and($receipt->source_type)->toBe('event_registration')
        ->and($receipt->source_id)->toBe($registrationId)
        ->and($receipt->effect_type)->toBe('ticket_affiliate_reversal')
        ->and($receipt->status)->toBe(ShareEffectStatus::Pending)
        ->and($receipt->payload)->toBe([
            'conversion_id' => $conversionId,
            'registration_id' => $registrationId,
            'order_id' => $orderId,
            'reason' => 'registration_refunded',
        ])
        ->and(ShareEffectReceipt::query()->count())->toBe(1);
});

it('settles a ticket reversal effect through the dispatcher when its registration is missing', function (): void {
    $receipts = app(EffectReceipts::class);

    $receipt = $receipts->ensureTicketReversal(
        effectKey: 'ticket-affiliate-reversal:'.hash('sha256', (string) Str::uuid()),
        registrationId: (string) Str::uuid(),
        conversionId: (string) Str::uuid(),
        orderId: (string) Str::uuid(),
        reason: 'registration_refunded',
    );

    expect(app(DispatchShareEffectReceiptsAction::class)->handle(10, 'seam-worker'))->toBe(1);

    $row = ShareEffectReceipt::query()->whereKey($receipt->id)->sole();

    expect($row->status)->toBe(ShareEffectStatus::Failed)
        ->and($row->failed_at)->not->toBeNull()
        ->and($row->lease_token)->toBeNull()
        ->and((int) $row->attempts)->toBe(1);
});

it('leases pending effects for five minutes and fences other workers', function (): void {
    $businessKey = effectReceiptBusinessKey();
    $receipts = app(EffectReceipts::class);

    $projection = $receipts->ensureProjection(
        businessKey: $businessKey,
        conversionId: (string) Str::uuid(),
        affiliateId: (string) Str::uuid(),
        linkId: (string) Str::uuid(),
    );

    expect($receipts->claimableBatch(['projection'], 10))->toHaveCount(1)
        ->and($receipts->claimableBatch(['ticket_affiliate_reversal'], 10))->toBe([]);

    $claimed = $receipts->claim($projection->id, ['projection'], 'worker-a', 'lease-a');

    expect($claimed)->not->toBeNull()
        ->and($claimed?->status)->toBe(ShareEffectStatus::Leased->value)
        ->and($claimed?->attempts)->toBe(1)
        ->and($claimed?->leaseToken)->toBe('lease-a')
        ->and($claimed?->leasedBy)->toBe('worker-a');

    $row = ShareEffectReceipt::query()->whereKey($projection->id)->sole();

    expect($row->status)->toBe(ShareEffectStatus::Leased)
        ->and((int) $row->attempts)->toBe(1)
        ->and($row->lease_expires_at?->greaterThan(now()->addMinutes(4)))->toBeTrue()
        ->and($row->lease_expires_at?->lessThan(now()->addMinutes(6)))->toBeTrue();

    // A live lease cannot be stolen by another worker.
    expect($receipts->claim($projection->id, ['projection'], 'worker-b', 'lease-b'))->toBeNull()
        ->and($receipts->claimableBatch(['projection'], 10))->toBe([])
        ->and($receipts->lockLease($projection->id, 'worker-b', 'lease-b'))->toBeNull();

    $this->travel(6)->minutes();

    // The expired lease is reclaimable by the next worker.
    $reclaimed = $receipts->claim($projection->id, ['projection'], 'worker-b', 'lease-b');

    expect($reclaimed)->not->toBeNull()
        ->and($reclaimed?->status)->toBe(ShareEffectStatus::Leased->value)
        ->and($reclaimed?->attempts)->toBe(2)
        ->and($reclaimed?->leaseToken)->toBe('lease-b')
        ->and($receipts->lockLease($projection->id, 'worker-a', 'lease-a'))->toBeNull()
        ->and($receipts->lockLease($projection->id, 'worker-b', 'lease-b'))->not->toBeNull();

    // A stale worker cannot settle the receipt it no longer owns.
    $receipts->markSucceeded($projection->id, 'worker-a', 'lease-a');

    expect(ShareEffectReceipt::query()->whereKey($projection->id)->sole()->status)->toBe(ShareEffectStatus::Leased);

    $receipts->markSucceeded($projection->id, 'worker-b', 'lease-b');

    $settled = ShareEffectReceipt::query()->whereKey($projection->id)->sole();

    expect($settled->status)->toBe(ShareEffectStatus::Succeeded)
        ->and($settled->lease_token)->toBeNull()
        ->and($settled->leased_by)->toBeNull()
        ->and($settled->lease_expires_at)->toBeNull()
        ->and($settled->succeeded_at)->not->toBeNull()
        ->and($receipts->claimableBatch(['projection'], 10))->toBe([])
        ->and($receipts->claim($projection->id, ['projection'], 'worker-c', 'lease-c'))->toBeNull();
});

it('writes failed, cancelled and pending releases through the lease fence', function (): void {
    $receipts = app(EffectReceipts::class);

    $makeClaimed = function (string $key, string $worker, string $token) use ($receipts): string {
        $receipt = $receipts->ensureProjection(
            businessKey: $key,
            conversionId: (string) Str::uuid(),
            affiliateId: (string) Str::uuid(),
            linkId: (string) Str::uuid(),
        );

        $receipts->claim($receipt->id, ['projection'], $worker, $token);

        return $receipt->id;
    };

    $failedId = $makeClaimed(effectReceiptBusinessKey(), 'worker-fail', 'lease-fail');
    $cancelledId = $makeClaimed(effectReceiptBusinessKey(), 'worker-cancel', 'lease-cancel');
    $pendingId = $makeClaimed(effectReceiptBusinessKey(), 'worker-pending', 'lease-pending');

    $receipts->markFailed($failedId, 'worker-fail', 'lease-fail');
    $receipts->markCancelled($cancelledId, 'worker-cancel', 'lease-cancel');
    $receipts->releaseToPending($pendingId, 'worker-pending', 'lease-pending');

    $failed = ShareEffectReceipt::query()->whereKey($failedId)->sole();
    $cancelled = ShareEffectReceipt::query()->whereKey($cancelledId)->sole();
    $pending = ShareEffectReceipt::query()->whereKey($pendingId)->sole();

    expect($failed->status)->toBe(ShareEffectStatus::Failed)
        ->and($failed->failed_at)->not->toBeNull()
        ->and($failed->lease_token)->toBeNull()
        ->and($cancelled->status)->toBe(ShareEffectStatus::Cancelled)
        ->and($cancelled->cancelled_at)->not->toBeNull()
        ->and($cancelled->lease_token)->toBeNull()
        ->and($pending->status)->toBe(ShareEffectStatus::Pending)
        ->and($pending->lease_token)->toBeNull()
        ->and($pending->leased_by)->toBeNull()
        ->and($pending->lease_expires_at)->toBeNull()
        ->and((int) $pending->attempts)->toBe(1)
        ->and($pending->failed_at)->toBeNull();
});

it('cancels only the non-terminal effects of a corrected source', function (): void {
    $receipts = app(EffectReceipts::class);
    $sourceId = effectReceiptBusinessKey();

    $pending = $receipts->ensureProjection(
        businessKey: $sourceId,
        conversionId: (string) Str::uuid(),
        affiliateId: (string) Str::uuid(),
        linkId: (string) Str::uuid(),
    );

    // Distinct effect keys so the fixture can hold leased and terminal rows;
    // they are re-pointed at the same source below.
    $leased = $receipts->ensureProjection(
        businessKey: effectReceiptBusinessKey(),
        conversionId: (string) Str::uuid(),
        affiliateId: (string) Str::uuid(),
        linkId: (string) Str::uuid(),
    );

    $receipts->claim($leased->id, ['projection'], 'worker-lease', 'lease-token');

    $succeededKey = effectReceiptBusinessKey();

    $succeeded = $receipts->ensureProjection(
        businessKey: $succeededKey,
        conversionId: (string) Str::uuid(),
        affiliateId: (string) Str::uuid(),
        linkId: (string) Str::uuid(),
    );

    $receipts->claim($succeeded->id, ['projection'], 'worker-success', 'lease-success');
    $receipts->markSucceeded($succeeded->id, 'worker-success', 'lease-success');

    // Re-point the fixtures at one source id so cancellation sees them all.
    ShareEffectReceipt::query()->whereKey($pending->id)->update(['source_id' => $sourceId]);
    ShareEffectReceipt::query()->whereKey($leased->id)->update(['source_id' => $sourceId]);
    ShareEffectReceipt::query()->whereKey($succeeded->id)->update(['source_id' => $sourceId]);

    $otherSource = effectReceiptBusinessKey();

    $other = $receipts->ensureProjection(
        businessKey: $otherSource,
        conversionId: (string) Str::uuid(),
        affiliateId: (string) Str::uuid(),
        linkId: (string) Str::uuid(),
    );

    expect($receipts->lockCancelableBySource($sourceId))->toHaveCount(2);

    $receipts->cancelPendingBySource($sourceId);

    expect(ShareEffectReceipt::query()->whereKey($pending->id)->sole()->status)->toBe(ShareEffectStatus::Cancelled)
        ->and(ShareEffectReceipt::query()->whereKey($leased->id)->sole()->status)->toBe(ShareEffectStatus::Cancelled)
        ->and(ShareEffectReceipt::query()->whereKey($leased->id)->sole()->lease_token)->toBeNull()
        ->and(ShareEffectReceipt::query()->whereKey($succeeded->id)->sole()->status)->toBe(ShareEffectStatus::Succeeded)
        ->and(ShareEffectReceipt::query()->whereKey($other->id)->sole()->status)->toBe(ShareEffectStatus::Pending);
});
