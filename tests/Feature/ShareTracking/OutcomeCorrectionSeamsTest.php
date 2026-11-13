<?php

declare(strict_types=1);

use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\States\ApprovedConversion;
use AIArmada\Affiliates\States\ConversionStatus;
use AIArmada\Rewards\Jobs\RecheckRewardAwardsJob;
use App\Actions\ShareTracking\ReverseShareOutcomeAction;
use App\Features\ShareTracking\Api\EffectReceipts;
use App\Features\ShareTracking\Api\OutcomeCorrections;
use App\Features\ShareTracking\Persistence\DatabaseOutcomeCorrections;
use App\Features\ShareTracking\Support\ShareTrackingModel;
use App\Jobs\Referrals\ReverseReferralRewardsForShareOutcomeJob;
use App\Models\ShareEffectReceipt;
use App\Models\ShareOutcomeCorrection;
use App\Models\ShareSourceAttributionReceipt;
use App\Models\User;
use App\Platform\FeatureCatalog;
use App\Shared\ShareTracking\ShareEffectStatus;
use App\Shared\ShareTracking\ShareOutcomeBusinessKey;
use App\Shared\ShareTracking\ShareOutcomeType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function s2a2BusinessKey(): string
{
    return ShareOutcomeBusinessKey::eventSave((string) Str::uuid());
}

/**
 * @return array{0: string, 1: AffiliateConversion}
 */
function s2a2ReversibleOutcome(?string $businessKey = null): array
{
    $businessKey ??= s2a2BusinessKey();

    ShareSourceAttributionReceipt::factory()->attributed()->create([
        'namespace' => ShareOutcomeBusinessKey::NAMESPACE,
        'outcome_type' => ShareOutcomeType::EventSave->value,
        'source_transition_id' => 'bookmark:'.Str::uuid(),
        'business_key' => $businessKey,
    ]);

    $conversion = AffiliateConversion::query()->create([
        'affiliate_id' => (string) Str::uuid(),
        'affiliate_code' => 'S2A2CODE',
        'subject_type' => 'event',
        'subject_key' => 'event:s2a2-probe',
        'subject_instance' => 'default',
        'conversion_type' => 'event_save',
        'idempotency_key' => hash('sha256', $businessKey),
        'value_minor' => 0,
        'subtotal_minor' => 0,
        'commission_minor' => 0,
        'commission_currency' => 'MYR',
        'status' => ApprovedConversion::class,
        'occurred_at' => now(),
    ]);

    return [$businessKey, $conversion];
}

function s2a2ConversionStatus(AffiliateConversion $conversion): string
{
    $status = $conversion->status;

    return $status instanceof ConversionStatus
        ? (string) $status->getValue()
        : (string) $status;
}

it('claims the outcome correction table for its single persistence writer', function (): void {
    $catalog = FeatureCatalog::load(base_path());
    $manifest = json_decode((string) file_get_contents(app_path('Features/ShareTracking/feature.json')), true, 512, JSON_THROW_ON_ERROR);

    $writers = array_keys(array_filter(
        $catalog['writes'],
        static fn (array $resources): bool => in_array('table:share_outcome_corrections', $resources, true),
    ));

    expect($catalog['owners']['table:share_outcome_corrections'] ?? null)->toBe('ShareTracking')
        ->and($manifest['owns']['table:share_outcome_corrections'] ?? null)->toBe([
            DatabaseOutcomeCorrections::class,
        ])
        ->and($writers)->toBe([DatabaseOutcomeCorrections::class])
        ->and($catalog['writes'][DatabaseOutcomeCorrections::class] ?? null)->toContain('table:share_outcome_corrections')
        ->and(array_filter($catalog['owners'], static fn (string $owner): bool => $owner === 'ShareTracking'))->toHaveCount(4);
});

it('binds the outcome correction Api and resolves the root model through its morph alias', function (): void {
    expect(app(OutcomeCorrections::class))->toBeInstanceOf(DatabaseOutcomeCorrections::class)
        ->and(ShareTrackingModel::outcomeCorrection())->toBe(ShareOutcomeCorrection::class)
        ->and((new ShareOutcomeCorrection)->getMorphClass())->toBe('share_outcome_correction');
});

it('records the exact correction row with a non-null actor', function (): void {
    $businessKey = s2a2BusinessKey();
    $conversionId = (string) Str::uuid();
    $actor = User::factory()->create();

    $this->travelTo(now()->startOfSecond()->addMinute());
    $occurredAt = now();

    $snapshot = app(OutcomeCorrections::class)->ensure(
        businessKey: $businessKey,
        conversionId: $conversionId,
        reason: 'save_removed',
        actorType: $actor->getMorphClass(),
        actorId: (string) $actor->getKey(),
    );

    $row = ShareOutcomeCorrection::query()->where('business_key', $businessKey)->sole();

    expect($row->conversion_id)->toBe($conversionId)
        ->and($row->business_key)->toBe($businessKey)
        ->and($row->reason)->toBe('save_removed')
        ->and($row->actor_type)->toBe('user')
        ->and($row->actor_id)->toBe((string) $actor->getKey())
        ->and($row->occurred_at->equalTo($occurredAt))->toBeTrue()
        ->and($row->created_at)->not->toBeNull()
        ->and($row->updated_at)->not->toBeNull()
        ->and($snapshot->id)->toBe((string) $row->getKey())
        ->and($snapshot->conversionId)->toBe($conversionId)
        ->and($snapshot->businessKey)->toBe($businessKey)
        ->and($snapshot->reason)->toBe('save_removed')
        ->and($snapshot->actorType)->toBe('user')
        ->and($snapshot->actorId)->toBe((string) $actor->getKey())
        ->and($snapshot->occurredAt)->toStartWith($row->occurred_at->toIso8601String());
});

it('records a null actor as an unattributed correction', function (): void {
    $businessKey = s2a2BusinessKey();

    $snapshot = app(OutcomeCorrections::class)->ensure(
        businessKey: $businessKey,
        conversionId: (string) Str::uuid(),
        reason: 'registration_cancelled',
    );

    $row = ShareOutcomeCorrection::query()->where('business_key', $businessKey)->sole();

    expect($row->actor_type)->toBeNull()
        ->and($row->actor_id)->toBeNull()
        ->and($snapshot->actorType)->toBeNull()
        ->and($snapshot->actorId)->toBeNull();
});

it('replays the first committed correction unchanged on a duplicate business key', function (): void {
    $businessKey = s2a2BusinessKey();
    $conversionId = (string) Str::uuid();
    $actor = User::factory()->create();
    $corrections = app(OutcomeCorrections::class);

    $first = $corrections->ensure(
        businessKey: $businessKey,
        conversionId: $conversionId,
        reason: 'save_removed',
        actorType: $actor->getMorphClass(),
        actorId: (string) $actor->getKey(),
    );

    // A duplicate insert hits the unique business key and replays the row.
    $replayed = $corrections->ensure(
        businessKey: $businessKey,
        conversionId: (string) Str::uuid(),
        reason: 'different_reason',
    );

    expect($replayed->id)->toBe($first->id)
        ->and($replayed->conversionId)->toBe($conversionId)
        ->and($replayed->reason)->toBe('save_removed')
        ->and($replayed->actorType)->toBe('user')
        ->and($replayed->actorId)->toBe((string) $actor->getKey())
        ->and(ShareOutcomeCorrection::query()->count())->toBe(1);
});

it('dispatches the correction observer jobs once when the row is created', function (): void {
    Queue::fake();

    $businessKey = s2a2BusinessKey();

    app(OutcomeCorrections::class)->ensure(
        businessKey: $businessKey,
        conversionId: (string) Str::uuid(),
        reason: 'save_removed',
    );

    Queue::assertPushed(
        ReverseReferralRewardsForShareOutcomeJob::class,
        static fn (ReverseReferralRewardsForShareOutcomeJob $job): bool => $job->businessKey === $businessKey,
    );
    Queue::assertPushed(
        RecheckRewardAwardsJob::class,
        static fn (RecheckRewardAwardsJob $job): bool => $job->businessKey === $businessKey,
    );
    Queue::assertPushed(RecheckRewardAwardsJob::class, 1);

    // Replaying an existing row does not fire the created observer again.
    app(OutcomeCorrections::class)->ensure(
        businessKey: $businessKey,
        conversionId: (string) Str::uuid(),
        reason: 'different_reason',
    );

    Queue::assertPushed(RecheckRewardAwardsJob::class, 1);
});

it('records the correction row through the reversal while preserving reversal and effect cancellation', function (): void {
    Queue::fake();

    [$businessKey, $conversion] = s2a2ReversibleOutcome();
    $actor = User::factory()->create();

    $effect = app(EffectReceipts::class)->ensureProjection(
        businessKey: $businessKey,
        conversionId: (string) $conversion->getKey(),
        affiliateId: (string) Str::uuid(),
        linkId: (string) Str::uuid(),
    );

    $correction = app(ReverseShareOutcomeAction::class)->handle($businessKey, 'save_removed', $actor);

    expect($correction)->not->toBeNull();

    $row = ShareOutcomeCorrection::query()->where('business_key', $businessKey)->sole();

    expect((string) $row->getKey())->toBe((string) $correction?->getKey())
        ->and($row->conversion_id)->toBe((string) $conversion->getKey())
        ->and($row->reason)->toBe('save_removed')
        ->and($row->actor_type)->toBe($actor->getMorphClass())
        ->and($row->actor_id)->toBe((string) $actor->getKey())
        ->and(s2a2ConversionStatus($conversion->refresh()))->toBe('reversed')
        ->and(ShareEffectReceipt::query()->whereKey($effect->id)->sole()->status)->toBe(ShareEffectStatus::Cancelled)
        ->and(ShareEffectReceipt::query()->whereKey($effect->id)->sole()->cancelled_at)->not->toBeNull();

    Queue::assertPushed(
        RecheckRewardAwardsJob::class,
        static fn (RecheckRewardAwardsJob $job): bool => $job->businessKey === $businessKey,
    );

    // Replaying the same reversal returns the same correction and never
    // rewrites its reason or re-fires the created observer.
    $replayed = app(ReverseShareOutcomeAction::class)->handle($businessKey, 'different_reason');

    expect((string) $replayed?->getKey())->toBe((string) $row->getKey())
        ->and(ShareOutcomeCorrection::query()->count())->toBe(1)
        ->and(ShareOutcomeCorrection::query()->sole()->reason)->toBe('save_removed');

    Queue::assertPushed(RecheckRewardAwardsJob::class, 1);
});

it('records a null actor through the reversal', function (): void {
    Queue::fake();

    [$businessKey] = s2a2ReversibleOutcome();

    $correction = app(ReverseShareOutcomeAction::class)->handle($businessKey, 'registration_cancelled');

    expect($correction)->not->toBeNull();

    $row = ShareOutcomeCorrection::query()->where('business_key', $businessKey)->sole();

    expect($row->actor_type)->toBeNull()
        ->and($row->actor_id)->toBeNull();
});
