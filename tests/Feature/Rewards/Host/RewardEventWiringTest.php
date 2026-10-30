<?php

declare(strict_types=1);

use AIArmada\Affiliates\Models\AffiliateAttribution;
use AIArmada\Affiliates\Models\AffiliateClaim;
use AIArmada\Affiliates\Models\AffiliateLink;
use AIArmada\Rewards\Actions\EnrollRewardMemberAction;
use AIArmada\Rewards\Actions\TransitionRewardProgramStatusAction;
use AIArmada\Rewards\Enums\RewardAwardStatus;
use AIArmada\Rewards\Enums\RewardComponent;
use AIArmada\Rewards\Enums\RewardProgramMode;
use AIArmada\Rewards\Enums\RewardProgramStatus;
use AIArmada\Rewards\Jobs\EvaluateRewardEntitlementJob;
use AIArmada\Rewards\Jobs\RecheckRewardAwardsJob;
use AIArmada\Rewards\Models\RewardAward;
use App\Models\ShareOutcomeCorrection;
use App\Models\User;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Rewards\Host\Support\RewardHostFixtures;
use Tests\Feature\Rewards\Host\Support\RewardLedgerFixtures;

uses(RefreshDatabase::class);

/**
 * @return array{0: mixed, 1: mixed}
 */
function rewireEnrolledRecognition(string $prefix, User $member): array
{
    [$program, $version] = RewardLedgerFixtures::seedVersionedProgram($prefix, RewardProgramMode::Recognition);

    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready);
    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active);

    EnrollRewardMemberAction::run(
        $program->refresh(), 'user', (string) $member->getKey(), $version,
        'member-portal', CarbonImmutable::parse('2026-06-10T00:00:00Z'),
    );

    return [$program->refresh(), $version];
}

it('dispatches evaluation when a receipt commits', function (): void {
    Queue::fake();

    $sharer = User::factory()->create();
    $actor = User::factory()->create();
    rewireEnrolledRecognition('rewire-trigger', $sharer);

    $key = ShareOutcomeBusinessKey::signup((string) $actor->getKey());

    DB::transaction(function () use ($key, $actor, $sharer): void {
        RewardHostFixtures::receipt($key, 'user:'.$actor->getKey(), [
            'attribution_id' => (string) Str::uuid(),
            'outcome_type' => 'signup',
            'occurred_at' => CarbonImmutable::now('UTC')->subMinutes(5),
        ]);
        RewardHostFixtures::conversion($key, [
            'sharer_user_id' => (string) $sharer->getKey(),
            'actor_user_id' => (string) $actor->getKey(),
            'occurred_at' => CarbonImmutable::now('UTC')->subMinutes(5),
        ]);
    });

    Queue::assertPushed(EvaluateRewardEntitlementJob::class, fn (EvaluateRewardEntitlementJob $job): bool => $job->businessKey === $key);
});

it('awards an enrolled sharer from the committed fact', function (): void {
    $sharer = User::factory()->create();
    $actor = User::factory()->create();
    [$program] = rewireEnrolledRecognition('rewire-award', $sharer);

    $key = ShareOutcomeBusinessKey::signup((string) $actor->getKey());
    RewardHostFixtures::receipt($key, 'user:'.$actor->getKey(), [
        'attribution_id' => (string) Str::uuid(),
        'outcome_type' => 'signup',
        'occurred_at' => CarbonImmutable::now('UTC')->subMinutes(5),
    ]);
    RewardHostFixtures::conversion($key, [
        'sharer_user_id' => (string) $sharer->getKey(),
        'actor_user_id' => (string) $actor->getKey(),
        'occurred_at' => CarbonImmutable::now('UTC')->subMinutes(5),
    ]);

    EvaluateRewardEntitlementJob::dispatchSync($key);

    $award = RewardAward::query()
        ->where('program_id', $program->getKey())
        ->where('source_business_key', $key)
        ->where('component', RewardComponent::Recognition->value)
        ->firstOrFail();

    expect((string) $award->recipient_id)->toBe((string) $sharer->getKey());
    expect($award->status)->toBe(RewardAwardStatus::Approved);
});

it('dispatches award recheck when a correction commits', function (): void {
    Queue::fake();

    $actor = User::factory()->create();
    $key = ShareOutcomeBusinessKey::signup((string) $actor->getKey());
    $conversion = RewardHostFixtures::conversion($key, ['actor_user_id' => (string) $actor->getKey()]);

    ShareOutcomeCorrection::query()->create([
        'conversion_id' => (string) $conversion->getKey(),
        'business_key' => $key,
        'reason' => 'cancelled',
        'actor_type' => 'user',
        'actor_id' => (string) $actor->getKey(),
        'occurred_at' => CarbonImmutable::now('UTC'),
    ]);

    Queue::assertPushed(RecheckRewardAwardsJob::class, fn (RecheckRewardAwardsJob $job): bool => $job->businessKey === $key);
});

it('reverses an approved award when its source is corrected', function (): void {
    $sharer = User::factory()->create();
    $actor = User::factory()->create();
    [$program] = rewireEnrolledRecognition('rewire-recheck', $sharer);

    $key = ShareOutcomeBusinessKey::signup((string) $actor->getKey());
    RewardHostFixtures::receipt($key, 'user:'.$actor->getKey(), [
        'attribution_id' => (string) Str::uuid(),
        'outcome_type' => 'signup',
        'occurred_at' => CarbonImmutable::now('UTC')->subMinutes(5),
    ]);
    $conversion = RewardHostFixtures::conversion($key, [
        'sharer_user_id' => (string) $sharer->getKey(),
        'actor_user_id' => (string) $actor->getKey(),
        'occurred_at' => CarbonImmutable::now('UTC')->subMinutes(5),
    ]);

    EvaluateRewardEntitlementJob::dispatchSync($key);

    $awardId = RewardAward::query()
        ->where('program_id', $program->getKey())
        ->where('source_business_key', $key)
        ->valueOrFail('id');

    ShareOutcomeCorrection::query()->create([
        'conversion_id' => (string) $conversion->getKey(),
        'business_key' => $key,
        'reason' => 'cancelled',
        'actor_type' => 'user',
        'actor_id' => (string) $actor->getKey(),
        'occurred_at' => CarbonImmutable::now('UTC'),
    ]);

    RecheckRewardAwardsJob::dispatchSync($key);

    expect(RewardAward::query()->whereKey($awardId)->value('status'))->toBe(RewardAwardStatus::Reversed);
});

it('dispatches claimed-history evaluation on claim', function (): void {
    Queue::fake();

    $claimant = User::factory()->create(['email' => 'rewire-claimant@example.test']);
    rewireEnrolledRecognition('rewire-claim', $claimant);

    $response = test()->getJson(route('dawah-share.payload', [
        'url' => route('events.index'),
        'text' => 'Share this page',
        'title' => 'Shared Page',
    ]));
    $response->assertOk();
    $payload = $response->json();
    $link = AffiliateLink::query()->where('custom_slug', $payload['tracking_token'])->firstOrFail();
    $guestAffiliateId = (string) $link->affiliate_id;

    $actor = User::factory()->create(['email' => 'rewire-actor@example.test']);
    $key = ShareOutcomeBusinessKey::signup((string) $actor->getKey());

    $attribution = AffiliateAttribution::query()->create([
        'affiliate_id' => $guestAffiliateId,
        'affiliate_code' => 'GUEST-'.Str::upper(Str::random(8)),
        'cookie_value' => 'rewire-'.((string) Str::uuid()),
    ]);

    RewardHostFixtures::receipt($key, 'user:'.$actor->getKey(), [
        'attribution_id' => (string) $attribution->getKey(),
        'outcome_type' => 'signup',
        'occurred_at' => CarbonImmutable::now('UTC')->subMinutes(5),
    ]);
    RewardHostFixtures::conversion($key, [
        'affiliate_id' => $guestAffiliateId,
        'sharer_user_id' => null,
        'actor_user_id' => (string) $actor->getKey(),
        'occurred_at' => CarbonImmutable::now('UTC')->subMinutes(5),
    ]);

    $claim = test()->actingAs($claimant)
        ->withCredentials()
        ->withCookie('mi_dawah_guest_claim', $payload['guest_claim_capability'])
        ->post(route('share.claim.store'), ['confirm' => true]);

    $claim->assertRedirect(route('share.claim.show'));
    $claim->assertSessionHas('share_claim_status', 'claimed');

    Queue::assertPushed(EvaluateRewardEntitlementJob::class, fn (EvaluateRewardEntitlementJob $job): bool => $job->businessKey === $key);
});

it('awards the claimant for proven guest sharer history', function (): void {
    $claimant = User::factory()->create();
    $actor = User::factory()->create();
    [$program] = rewireEnrolledRecognition('rewire-claimed', $claimant);

    $guestAffiliateId = (string) RewardHostFixtures::guestAffiliate()->getKey();
    $key = ShareOutcomeBusinessKey::signup((string) $actor->getKey());
    RewardHostFixtures::receipt($key, 'user:'.$actor->getKey(), [
        'attribution_id' => (string) Str::uuid(),
        'outcome_type' => 'signup',
        'occurred_at' => CarbonImmutable::now('UTC')->subMinutes(5),
    ]);
    RewardHostFixtures::conversion($key, [
        'affiliate_id' => $guestAffiliateId,
        'sharer_user_id' => null,
        'actor_user_id' => (string) $actor->getKey(),
        'occurred_at' => CarbonImmutable::now('UTC')->subMinutes(5),
    ]);
    AffiliateClaim::query()->create([
        'affiliate_id' => $guestAffiliateId,
        'claimant_type' => (new User)->getMorphClass(),
        'claimant_id' => (string) $claimant->getKey(),
        'capability_verifier_hash' => hash('sha256', 'rewire-claimed-test'),
        'status' => 'active',
        'claimed_at' => CarbonImmutable::now('UTC'),
    ]);

    EvaluateRewardEntitlementJob::dispatchSync($key);

    $award = RewardAward::query()
        ->where('program_id', $program->getKey())
        ->where('source_business_key', $key)
        ->where('component', RewardComponent::Recognition->value)
        ->firstOrFail();

    expect((string) $award->recipient_id)->toBe((string) $claimant->getKey());
});
