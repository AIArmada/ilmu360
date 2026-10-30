<?php

declare(strict_types=1);

use AIArmada\Affiliates\Actions\Identity\RevokeAffiliateClaim;
use AIArmada\Affiliates\Models\AffiliateClaim;
use AIArmada\Affiliates\Models\AffiliateFraudSignal;
use AIArmada\Rewards\Actions\MarkRewardReservationSubmittedAction;
use AIArmada\Rewards\Enums\RewardBeneficiaryRole;
use AIArmada\Rewards\Enums\RewardSourceCorrectionStatus;
use AIArmada\Rewards\Enums\RewardSourceFraudStatus;
use AIArmada\Rewards\Enums\RewardVerificationLevel;
use AIArmada\Rewards\Models\RewardPointReservationSubmission;
use AIArmada\Rewards\Support\PointLedgerRewardReservationResolver;
use App\Models\EventCheckin;
use App\Models\Registration;
use App\Models\ShareOutcomeCorrection;
use App\Models\User;
use App\Support\Rewards\ShareAttributionRewardSourceFactReader;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Rewards\Host\Support\RewardHostFixtures;

uses(RefreshDatabase::class);

it('refuses unknown sources without a fact', function (): void {
    $reader = new ShareAttributionRewardSourceFactReader;

    expect($reader->read(''))->toBeNull();
    expect($reader->read('commercial:signup:user:'.((string) Str::uuid())))->toBeNull();
    expect($reader->read('share-outcome:visit:user:'.((string) Str::uuid())))->toBeNull();
    expect($reader->read('share-outcome:signup:user:not-a-uuid'))->toBeNull();
    expect($reader->read('share-outcome:signup:user:'.((string) Str::uuid())))->toBeNull();
});

it('returns null when the conversion is missing under an attributed receipt', function (): void {
    $actor = User::factory()->create();
    $key = ShareOutcomeBusinessKey::signup((string) $actor->getKey());
    RewardHostFixtures::receipt($key, 'user:'.$actor->getKey(), ['attribution_id' => (string) Str::uuid()]);

    expect((new ShareAttributionRewardSourceFactReader)->read($key))->toBeNull();
});

it('maps an attributed member signup fact from committed evidence', function (): void {
    $actor = User::factory()->create();
    $sharer = User::factory()->create();
    $key = ShareOutcomeBusinessKey::signup((string) $actor->getKey());
    $occurred = CarbonImmutable::now('UTC')->subDay();
    RewardHostFixtures::receipt($key, 'user:'.$actor->getKey(), [
        'attribution_id' => (string) Str::uuid(),
        'outcome_type' => 'signup',
        'occurred_at' => $occurred,
    ]);
    RewardHostFixtures::conversion($key, [
        'sharer_user_id' => (string) $sharer->getKey(),
        'actor_user_id' => (string) $actor->getKey(),
        'subject_type' => 'user',
        'occurred_at' => $occurred,
    ]);

    $fact = (new ShareAttributionRewardSourceFactReader)->read($key);

    expect($fact)->not->toBeNull();
    expect($fact->outcome)->toBe('signup');
    expect($fact->occurredAt->toIso8601String())->toBe($occurred->toIso8601String());
    expect($fact->verification)->toBe(RewardVerificationLevel::Accepted);
    expect($fact->actorId)->toBe((string) $actor->getKey());
    expect($fact->sharerId)->toBe((string) $sharer->getKey());
    expect($fact->actorRole)->toBe(RewardBeneficiaryRole::Recipient);
    expect($fact->isClaimedIdentity)->toBeTrue();
    expect($fact->isAttributed)->toBeTrue();
    expect($fact->isFirstTime)->toBeTrue();
    expect($fact->isValid)->toBeTrue();
    expect($fact->isCancelled)->toBeFalse();
    expect($fact->fraudStatus)->toBe(RewardSourceFraudStatus::Clear);
    expect($fact->correctionStatus)->toBe(RewardSourceCorrectionStatus::Current);
});

it('reports unattributed receipts as facts so policy rejects on merits', function (): void {
    $actor = User::factory()->create();
    $key = ShareOutcomeBusinessKey::signup((string) $actor->getKey());
    RewardHostFixtures::receipt($key, 'user:'.$actor->getKey(), ['attribution_id' => null]);
    RewardHostFixtures::conversion($key, ['actor_user_id' => (string) $actor->getKey()]);

    $fact = (new ShareAttributionRewardSourceFactReader)->read($key);

    expect($fact)->not->toBeNull();
    expect($fact->isAttributed)->toBeFalse();
    expect($fact->sharerId)->toBeNull();
});

it('resolves a guest sharer only through an active claim', function (): void {
    $actor = User::factory()->create();
    $claimant = User::factory()->create();
    $guestAffiliateId = (string) RewardHostFixtures::guestAffiliate()->getKey();
    $key = ShareOutcomeBusinessKey::signup((string) $actor->getKey());
    RewardHostFixtures::receipt($key, 'user:'.$actor->getKey(), ['attribution_id' => (string) Str::uuid()]);
    RewardHostFixtures::conversion($key, [
        'affiliate_id' => $guestAffiliateId,
        'sharer_user_id' => null,
        'actor_user_id' => (string) $actor->getKey(),
    ]);

    $reader = new ShareAttributionRewardSourceFactReader;
    expect($reader->read($key)->sharerId)->toBeNull();

    AffiliateClaim::query()->create([
        'affiliate_id' => $guestAffiliateId,
        'claimant_type' => (new User)->getMorphClass(),
        'claimant_id' => (string) $claimant->getKey(),
        'capability_verifier_hash' => hash('sha256', 'host-adapter-test'),
        'status' => 'active',
        'claimed_at' => CarbonImmutable::now('UTC'),
    ]);

    $fact = $reader->read($key);
    expect($fact->sharerId)->toBe((string) $claimant->getKey());
    expect($fact->isClaimedIdentity)->toBeTrue();

    app(RevokeAffiliateClaim::class)->handle(
        AffiliateClaim::query()->where('affiliate_id', $guestAffiliateId)->firstOrFail()
    );

    expect($reader->read($key)->sharerId)->toBeNull();
});

it('grades checkin verification from staff evidence only', function (): void {
    $actor = User::factory()->create();
    $staff = User::factory()->create();
    $checkin = EventCheckin::factory()->create(['verified_by_user_id' => null]);
    $key = ShareOutcomeBusinessKey::eventCheckin((string) $checkin->getKey());
    RewardHostFixtures::receipt($key, 'checkin:'.$checkin->getKey(), [
        'attribution_id' => (string) Str::uuid(),
        'outcome_type' => 'event_checkin',
    ]);
    RewardHostFixtures::conversion($key, [
        'conversion_type' => 'event_checkin',
        'actor_user_id' => (string) $actor->getKey(),
        'sharer_user_id' => (string) $staff->getKey(),
    ]);

    $reader = new ShareAttributionRewardSourceFactReader;
    $recorded = $reader->read($key);
    expect($recorded->verification)->toBe(RewardVerificationLevel::Unverified);
    expect($recorded->actorRole)->toBe(RewardBeneficiaryRole::Participant);

    $checkin->forceFill(['verified_by_user_id' => (string) $staff->getKey()])->save();

    expect($reader->read($key)->verification)->toBe(RewardVerificationLevel::StaffVerified);
});

it('invalidates facts on correction rows and reversed conversions', function (): void {
    $actor = User::factory()->create();
    $key = ShareOutcomeBusinessKey::signup((string) $actor->getKey());
    RewardHostFixtures::receipt($key, 'user:'.$actor->getKey(), ['attribution_id' => (string) Str::uuid()]);
    $conversion = RewardHostFixtures::conversion($key, ['actor_user_id' => (string) $actor->getKey()]);

    ShareOutcomeCorrection::query()->create([
        'conversion_id' => (string) $conversion->getKey(),
        'business_key' => $key,
        'reason' => 'cancelled',
        'actor_type' => 'user',
        'actor_id' => (string) $actor->getKey(),
        'occurred_at' => CarbonImmutable::now('UTC'),
    ]);

    $fact = (new ShareAttributionRewardSourceFactReader)->read($key);
    expect($fact->isValid)->toBeFalse();
    expect($fact->correctionStatus)->toBe(RewardSourceCorrectionStatus::Withdrawn);
    expect($fact->correctionId)->not->toBeNull();
});

it('tracks fraud suspect/confirmed/dismissed readings', function (): void {
    $actor = User::factory()->create();
    $key = ShareOutcomeBusinessKey::signup((string) $actor->getKey());
    RewardHostFixtures::receipt($key, 'user:'.$actor->getKey(), ['attribution_id' => (string) Str::uuid()]);
    $conversion = RewardHostFixtures::conversion($key, ['actor_user_id' => (string) $actor->getKey()]);

    $reader = new ShareAttributionRewardSourceFactReader;
    expect($reader->read($key)->fraudStatus)->toBe(RewardSourceFraudStatus::Clear);

    $signal = AffiliateFraudSignal::query()->create([
        'affiliate_id' => (string) $conversion->getAttribute('affiliate_id'),
        'conversion_id' => (string) $conversion->getKey(),
        'rule_code' => 'HOST-ADAPTER',
        'risk_points' => 10,
        'severity' => 'medium',
        'description' => 'host adapter test signal',
        'status' => 'detected',
        'detected_at' => CarbonImmutable::now('UTC'),
    ]);
    expect($reader->read($key)->fraudStatus)->toBe(RewardSourceFraudStatus::Suspect);

    $signal->forceFill(['status' => 'confirmed', 'confirmed_at' => CarbonImmutable::now('UTC')])->save();
    expect($reader->read($key)->fraudStatus)->toBe(RewardSourceFraudStatus::Confirmed);

    $signal->forceFill(['status' => 'dismissed', 'dismissed_at' => CarbonImmutable::now('UTC')])->save();
    expect($reader->read($key)->fraudStatus)->toBe(RewardSourceFraudStatus::Clear);
});

it('marks paid registrations as purchaser and cancelled ones invalid', function (): void {
    $actor = User::factory()->create();
    $registration = Registration::factory()->create([
        'total_amount' => 5000,
        'payment_status' => 'paid',
    ]);
    $key = ShareOutcomeBusinessKey::eventRegistration((string) $registration->getKey());
    RewardHostFixtures::receipt($key, 'registration:'.$registration->getKey(), [
        'attribution_id' => (string) Str::uuid(),
        'outcome_type' => 'event_registration',
    ]);
    RewardHostFixtures::conversion($key, [
        'conversion_type' => 'event_registration',
        'actor_user_id' => (string) $actor->getKey(),
    ]);

    $reader = new ShareAttributionRewardSourceFactReader;
    expect($reader->read($key)->actorRole)->toBe(RewardBeneficiaryRole::Purchaser);

    $registration->forceFill(['status' => 'cancelled'])->save();

    $cancelled = $reader->read($key);
    expect($cancelled->isCancelled)->toBeTrue();
    expect($cancelled->isValid)->toBeFalse();
});

it('attests unsubmitted reservations and refuses submitted ones', function (): void {
    $resolver = new PointLedgerRewardReservationResolver;

    expect($resolver->mayVoidReservation('rsv-host-unsubmitted-001'))->toBeTrue();
    expect($resolver->mayVoidReservation(''))->toBeFalse();

    MarkRewardReservationSubmittedAction::run('rsv-host-sending-002', RewardPointReservationSubmission::STATUS_SENDING, 'op-1');
    expect($resolver->mayVoidReservation('rsv-host-sending-002'))->toBeFalse();

    MarkRewardReservationSubmittedAction::run('rsv-host-sending-002', RewardPointReservationSubmission::STATUS_FAILED, 'op-1-failed');
    expect($resolver->mayVoidReservation('rsv-host-sending-002'))->toBeTrue();

    MarkRewardReservationSubmittedAction::run('rsv-host-done-003', RewardPointReservationSubmission::STATUS_DELIVERED, 'op-2');
    expect($resolver->mayVoidReservation('rsv-host-done-003'))->toBeFalse();

    $replay = MarkRewardReservationSubmittedAction::run('rsv-host-done-003', RewardPointReservationSubmission::STATUS_DELIVERED, 'op-2');
    expect((string) $replay->getAttribute('reservation_key'))->toBe('rsv-host-done-003');
});

it('refuses to rewrite terminal submission rows', function (): void {
    MarkRewardReservationSubmittedAction::run('rsv-host-terminal-004', RewardPointReservationSubmission::STATUS_FAILED, 'op-3');

    expect(fn (): mixed => MarkRewardReservationSubmittedAction::run('rsv-host-terminal-004', RewardPointReservationSubmission::STATUS_SUBMITTED))
        ->toThrow(InvalidArgumentException::class, 'terminal [failed]');
});
