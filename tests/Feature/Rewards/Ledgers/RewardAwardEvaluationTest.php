<?php

use App\Actions\Rewards\EnrollRewardMemberAction;
use App\Actions\Rewards\EvaluateRewardEntitlementAction;
use App\Actions\Rewards\LeaveRewardProgramAction;
use App\Actions\Rewards\PublishRewardProgramVersionAction;
use App\Actions\Rewards\TransitionRewardProgramStatusAction;
use App\Enums\Rewards\RewardAwardStatus;
use App\Enums\Rewards\RewardBeneficiaryRole;
use App\Enums\Rewards\RewardComponent;
use App\Enums\Rewards\RewardEligibleOutcome;
use App\Enums\Rewards\RewardEvaluationOutcome;
use App\Enums\Rewards\RewardProgramMode;
use App\Enums\Rewards\RewardProgramStatus;
use App\Enums\Rewards\RewardSourceCorrectionStatus;
use App\Enums\Rewards\RewardSourceFraudStatus;
use App\Enums\Rewards\RewardVerificationLevel;
use App\Models\RewardAward;
use App\Models\RewardSourceComponentClaim;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Feature\Rewards\Ledgers\Support\FakeRewardSourceFactReader;
use Tests\Feature\Rewards\Ledgers\Support\RewardLedgerFixtures;

function ledgerHarness(string $prefix = 'ledger-eval', array $ruleOverrides = []): array
{
    return RewardLedgerFixtures::seedActiveEnrolled($prefix, RewardProgramMode::Points, $ruleOverrides);
}

it('awards a clean fact with frozen snapshots, entry, claim, and caps', function () {
    [$program, $version, , $memberId] = ledgerHarness();

    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact('src-clean-1', $memberId));

    $action = new EvaluateRewardEntitlementAction($reader);
    $result = $action->handle(
        $program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient,
        'src-clean-1', CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    expect($result->outcome)->toBe(RewardEvaluationOutcome::Awarded)
        ->and($result->duplicate)->toBeFalse()
        ->and($result->award->status)->toBe(RewardAwardStatus::Approved)
        ->and($result->award->approved_at)->not->toBeNull()
        ->and($result->award->amount_snapshot)->toBe(['type' => 'points', 'points' => 10, 'unit' => 'pts'])
        ->and($result->award->policy_snapshot['policy_version'])->toBe(1)
        ->and($result->award->policy_snapshot['material_terms_hash'])->toBe((string) $version->material_terms_hash)
        ->and($result->award->consent_snapshot['acceptance_id'])->not->toBeEmpty()
        ->and($result->award->source_snapshot['source_business_key'])->toBe('src-clean-1')
        ->and($result->award->entries()->count())->toBe(1)
        ->and(RewardSourceComponentClaim::query()->count())->toBe(1)
        ->and($reader->readCount('src-clean-1'))->toBeGreaterThanOrEqual(2);
});

it('returns the frozen first award on duplicate evaluation and version switch', function () {
    [$program, $v1, , $memberId] = ledgerHarness('ledger-dup');

    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact('src-dup-1', $memberId));

    $action = new EvaluateRewardEntitlementAction($reader);
    $decision = CarbonImmutable::parse('2026-06-15T00:00:00Z');

    $first = $action->handle($program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient, 'src-dup-1', $decision);
    $second = $action->handle($program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient, 'src-dup-1', $decision);

    expect($second->duplicate)->toBeTrue()
        ->and((string) $second->award->getKey())->toBe((string) $first->award->getKey())
        ->and(RewardAward::query()->count())->toBe(1);

    $rules = RewardLedgerFixtures::rules();
    $rules['components'] = [['type' => 'points', 'points' => 50, 'unit' => 'pts']];
    $terms = RewardLedgerFixtures::terms();
    $terms['version_label'] = 'draft-2';
    $effective = CarbonImmutable::parse('2026-07-01T00:00:00Z');
    $v2 = PublishRewardProgramVersionAction::run($program, $effective, $rules, $terms, 'raise', null, null, $effective);

    EnrollRewardMemberAction::run($program, 'user', $memberId, $v2, 'member-portal', CarbonImmutable::parse('2026-07-02T00:00:00Z'));

    $third = $action->handle($program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient, 'src-dup-1', CarbonImmutable::parse('2026-07-05T00:00:00Z'));

    expect($third->duplicate)->toBeTrue()
        ->and((string) $third->award->getKey())->toBe((string) $first->award->getKey())
        ->and($third->award->amount_snapshot['points'])->toBe(10)
        ->and($third->award->policy_snapshot['policy_version'])->toBe(1)
        ->and(RewardAward::query()->count())->toBe(1);
});

it('refuses unknown sources without writing rows', function () {
    [$program, , , $memberId] = ledgerHarness('ledger-unk');

    $action = new EvaluateRewardEntitlementAction(new FakeRewardSourceFactReader);

    expect(fn () => $action->handle($program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient, 'src-missing', CarbonImmutable::parse('2026-06-15T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'unknown_source');

    expect(RewardAward::query()->count())->toBe(0);

    $defaultAction = new EvaluateRewardEntitlementAction;

    expect(fn () => $defaultAction->handle($program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient, 'src-missing', CarbonImmutable::parse('2026-06-15T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'unknown_source');
});

it('records permanent rejections for merits failures', function (array $factOverrides, string $reason) {
    [$program, , , $memberId] = ledgerHarness('ledger-rej');

    if (($factOverrides['sharerId'] ?? null) === '@recipient') {
        $factOverrides['sharerId'] = $memberId;
    }

    if (($factOverrides['actorId'] ?? null) === '@recipient') {
        $factOverrides['actorId'] = $memberId;
    }

    $source = 'src-rej-'.Str::lower(Str::random(6));
    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact($source, $memberId, '2026-06-12T00:00:00Z', RewardEligibleOutcome::EventCheckin, $factOverrides));

    $action = new EvaluateRewardEntitlementAction($reader);
    $result = $action->handle($program, RewardComponent::Points, 'user', $memberId, $factOverrides['@role'] ?? RewardBeneficiaryRole::Recipient, $source, CarbonImmutable::parse('2026-06-15T00:00:00Z'));

    expect($result->outcome)->toBe(RewardEvaluationOutcome::Rejected)
        ->and($result->reasons)->toBe([$reason])
        ->and($result->award->status)->toBe(RewardAwardStatus::Rejected)
        ->and($result->award->rejected_at)->not->toBeNull()
        ->and($result->award->entries()->count())->toBe(1);

    $retry = $action->handle($program, RewardComponent::Points, 'user', $memberId, $factOverrides['@role'] ?? RewardBeneficiaryRole::Recipient, $source, CarbonImmutable::parse('2026-06-15T00:00:00Z'));

    expect($retry->duplicate)->toBeTrue()
        ->and(RewardAward::query()->count())->toBe(1);
})->with([
    'self credit' => [['actorId' => '@recipient', 'sharerId' => '@recipient'], 'self_credit_excluded'],
    'bot' => [['isBot' => true], 'source_bot_excluded'],
    'invalid' => [['isValid' => false], 'source_invalid'],
    'cancelled' => [['isCancelled' => true], 'source_cancelled'],
    'unattributed' => [['isAttributed' => false], 'late_attribution_refused'],
    'weak verification' => [['verification' => RewardVerificationLevel::Unverified], 'verification_insufficient'],
    'outcome not allowlisted' => [['outcome' => RewardEligibleOutcome::EventSave], 'outcome_not_allowlisted'],
    'fraud confirmed' => [['fraudStatus' => RewardSourceFraudStatus::Confirmed], 'fraud_confirmed'],
    'withdrawn' => [['correctionStatus' => RewardSourceCorrectionStatus::Withdrawn], 'source_withdrawn'],
    'beneficiary mismatch' => [['sharerId' => (string) Str::uuid()], 'beneficiary_mismatch'],
]);

it('refuses not-yet-authorized attempts without freezing rows', function () {
    [$program, $version, $enrollment, $memberId] = ledgerHarness('ledger-trans');

    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Paused);

    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact('src-paused-1', $memberId));
    $action = new EvaluateRewardEntitlementAction($reader);

    expect(fn () => $action->handle($program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient, 'src-paused-1', CarbonImmutable::parse('2026-06-15T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'program_not_active');

    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active);

    $ok = $action->handle($program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient, 'src-paused-1', CarbonImmutable::parse('2026-06-15T00:00:00Z'));

    expect($ok->outcome)->toBe(RewardEvaluationOutcome::Awarded);

    LeaveRewardProgramAction::run($enrollment, 'left-test', CarbonImmutable::parse('2026-06-16T00:00:00Z'));

    $reader->seed(RewardLedgerFixtures::fact('src-left-1', $memberId));

    expect(fn () => $action->handle($program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient, 'src-left-1', CarbonImmutable::parse('2026-06-17T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'enrollment_left');

    expect(RewardAward::query()->where('source_business_key', 'src-left-1')->count())->toBe(0);
});

it('refuses stale consent, guest claims, and evidence after the decision transiently', function () {
    [$program, , , $memberId] = ledgerHarness('ledger-stale');

    $rules = RewardLedgerFixtures::rules();
    $rules['components'] = [['type' => 'points', 'points' => 20, 'unit' => 'pts']];
    $terms = RewardLedgerFixtures::terms();
    $terms['version_label'] = 'draft-2';
    $effective = CarbonImmutable::parse('2026-07-01T00:00:00Z');
    PublishRewardProgramVersionAction::run($program, $effective, $rules, $terms, 'raise', null, null, $effective);

    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact('src-stale-1', $memberId, '2026-07-02T00:00:00Z'));
    $action = new EvaluateRewardEntitlementAction($reader);

    expect(fn () => $action->handle($program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient, 'src-stale-1', CarbonImmutable::parse('2026-07-05T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'acceptance_stale_material_change');

    expect(RewardAward::query()->where('source_business_key', 'src-stale-1')->count())->toBe(0);

    $reader->seed(RewardLedgerFixtures::fact('src-guest-1', $memberId, '2026-06-12T00:00:00Z', RewardEligibleOutcome::EventCheckin, ['isClaimedIdentity' => false]));

    expect(fn () => $action->handle($program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient, 'src-guest-1', CarbonImmutable::parse('2026-06-15T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'guest_unclaimed');

    $reader->seed(RewardLedgerFixtures::fact('src-future-1', $memberId, '2026-06-20T00:00:00Z'));

    expect(fn () => $action->handle($program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient, 'src-future-1', CarbonImmutable::parse('2026-06-15T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'evidence_after_decision');

    expect(RewardAward::query()->count())->toBe(0);
});

it('holds fraud suspects, ambiguous devices, and policy holds instead of approving', function () {
    [$program, , , $memberId] = ledgerHarness('ledger-hold');

    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact('src-suspect-1', $memberId, '2026-06-12T00:00:00Z', RewardEligibleOutcome::EventCheckin, ['fraudStatus' => RewardSourceFraudStatus::Suspect]));
    $reader->seed(RewardLedgerFixtures::fact('src-ambig-1', $memberId, '2026-06-12T00:00:00Z', RewardEligibleOutcome::EventCheckin, ['isAmbiguousDevice' => true]));

    $action = new EvaluateRewardEntitlementAction($reader);

    $suspect = $action->handle($program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient, 'src-suspect-1', CarbonImmutable::parse('2026-06-15T00:00:00Z'));
    $ambiguous = $action->handle($program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient, 'src-ambig-1', CarbonImmutable::parse('2026-06-15T00:00:00Z'));

    expect($suspect->outcome)->toBe(RewardEvaluationOutcome::Held)
        ->and($suspect->reasons)->toBe(['fraud_suspect_held'])
        ->and($suspect->award->status)->toBe(RewardAwardStatus::Held)
        ->and($ambiguous->outcome)->toBe(RewardEvaluationOutcome::Held)
        ->and($ambiguous->reasons)->toBe(['hold_required']);
});

it('records cash candidates as held prerequisites, never approved money', function () {
    [$program] = RewardLedgerFixtures::seedVersionedProgram('ledger-cash', RewardProgramMode::Cash);
    $program = RewardLedgerFixtures::forceActive($program);

    $memberId = (string) Str::uuid();
    $version = $program->versions()->orderBy('version')->firstOrFail();
    EnrollRewardMemberAction::run($program, 'user', $memberId, $version, 'member-portal', CarbonImmutable::parse('2026-06-10T00:00:00Z'));

    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact('src-cash-1', $memberId));

    $action = new EvaluateRewardEntitlementAction($reader);
    $result = $action->handle($program, RewardComponent::Cash, 'user', $memberId, RewardBeneficiaryRole::Recipient, 'src-cash-1', CarbonImmutable::parse('2026-06-15T00:00:00Z'));

    expect($result->outcome)->toBe(RewardEvaluationOutcome::Held)
        ->and($result->reasons)->toBe(['financial_readiness_pending'])
        ->and($result->award->amount_snapshot)->toBe(['type' => 'cash', 'minor_units' => 500, 'currency' => 'MYR'])
        ->and($result->award->status)->toBe(RewardAwardStatus::Held);
});

it('records benefit candidates as held prerequisites with exact amount snapshots', function () {
    [$program] = RewardLedgerFixtures::seedVersionedProgram('ledger-benefit', RewardProgramMode::Benefit);
    $program = RewardLedgerFixtures::forceActive($program);

    $memberId = (string) Str::uuid();
    $version = $program->versions()->orderBy('version')->firstOrFail();
    EnrollRewardMemberAction::run($program, 'user', $memberId, $version, 'member-portal', CarbonImmutable::parse('2026-06-10T00:00:00Z'));

    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact('src-benefit-1', $memberId));

    $action = new EvaluateRewardEntitlementAction($reader);
    $result = $action->handle($program, RewardComponent::Benefit, 'user', $memberId, RewardBeneficiaryRole::Recipient, 'src-benefit-1', CarbonImmutable::parse('2026-06-15T00:00:00Z'));

    expect($result->outcome)->toBe(RewardEvaluationOutcome::Held)
        ->and($result->reasons)->toBe(['financial_readiness_pending'])
        ->and($result->award->amount_snapshot['type'])->toBe('benefit')
        ->and($result->award->amount_snapshot['benefit_key'])->toBe('ebook-credit')
        ->and($result->award->fulfilled_at)->toBeNull();
});

it('proceeds on corrected sources while freezing the correction evidence', function () {
    [$program, , , $memberId] = ledgerHarness('ledger-corr');

    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact('src-corr-1', $memberId, '2026-06-12T00:00:00Z', RewardEligibleOutcome::EventCheckin, [
        'correctionStatus' => RewardSourceCorrectionStatus::Corrected,
        'correctionId' => 'corr-42',
    ]));

    $action = new EvaluateRewardEntitlementAction($reader);
    $result = $action->handle($program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient, 'src-corr-1', CarbonImmutable::parse('2026-06-15T00:00:00Z'));

    expect($result->outcome)->toBe(RewardEvaluationOutcome::Awarded)
        ->and($result->award->source_snapshot['correction_status'])->toBe('corrected')
        ->and($result->award->source_snapshot['correction_id'])->toBe('corr-42');
});

it('qualifies preclaim facts inside the published lookback and rejects outside', function () {
    [$program, , , $memberId] = ledgerHarness('ledger-look');

    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact('src-pre-in-1', $memberId, '2026-05-20T00:00:00Z'));
    $reader->seed(RewardLedgerFixtures::fact('src-pre-out-1', $memberId, '2026-04-01T00:00:00Z'));

    $action = new EvaluateRewardEntitlementAction($reader);

    $inside = $action->handle($program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient, 'src-pre-in-1', CarbonImmutable::parse('2026-06-15T00:00:00Z'));
    $outside = $action->handle($program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient, 'src-pre-out-1', CarbonImmutable::parse('2026-06-15T00:00:00Z'));

    expect($inside->outcome)->toBe(RewardEvaluationOutcome::Awarded)
        ->and($outside->outcome)->toBe(RewardEvaluationOutcome::Rejected)
        ->and($outside->reasons)->toBe(['outside_preclaim_lookback']);
});

it('aborts when the source fact changes mid-evaluation and succeeds on retry', function () {
    [$program, , , $memberId] = ledgerHarness('ledger-chg');

    $first = RewardLedgerFixtures::fact('src-chg-1', $memberId);
    $changed = RewardLedgerFixtures::fact('src-chg-1', $memberId, '2026-06-12T00:00:00Z', RewardEligibleOutcome::EventCheckin, ['isBot' => true]);

    $reader = new FakeRewardSourceFactReader(['src-chg-1' => [$first, $changed]]);
    $action = new EvaluateRewardEntitlementAction($reader);

    expect(fn () => $action->handle($program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient, 'src-chg-1', CarbonImmutable::parse('2026-06-15T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'Source fact changed');

    expect(RewardAward::query()->count())->toBe(0);

    $steady = new FakeRewardSourceFactReader;
    $steady->seed($first);
    $retry = (new EvaluateRewardEntitlementAction($steady))->handle($program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient, 'src-chg-1', CarbonImmutable::parse('2026-06-15T00:00:00Z'));

    expect($retry->outcome)->toBe(RewardEvaluationOutcome::Awarded);
});

it('binds participant, purchaser, staff, and sharer roles to distinct identities', function () {
    [$program, , , $memberId] = ledgerHarness('ledger-roles');

    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact('src-part-1', (string) Str::uuid(), '2026-06-12T00:00:00Z', RewardEligibleOutcome::EventCheckin, ['actorId' => $memberId]));
    $reader->seed(RewardLedgerFixtures::fact('src-staff-1', (string) Str::uuid(), '2026-06-12T00:00:00Z', RewardEligibleOutcome::EventCheckin, ['actorId' => $memberId, 'actorRole' => RewardBeneficiaryRole::Staff]));

    $action = new EvaluateRewardEntitlementAction($reader);

    $participant = $action->handle($program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Participant, 'src-part-1', CarbonImmutable::parse('2026-06-15T00:00:00Z'));
    $staff = $action->handle($program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Staff, 'src-staff-1', CarbonImmutable::parse('2026-06-15T00:00:00Z'));

    expect($participant->outcome)->toBe(RewardEvaluationOutcome::Awarded)
        ->and($staff->outcome)->toBe(RewardEvaluationOutcome::Awarded);

    $reader->seed(RewardLedgerFixtures::fact('src-part-bad-1', (string) Str::uuid(), '2026-06-12T00:00:00Z', RewardEligibleOutcome::EventCheckin, ['actorId' => (string) Str::uuid()]));

    $mismatch = $action->handle($program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Participant, 'src-part-bad-1', CarbonImmutable::parse('2026-06-15T00:00:00Z'));

    expect($mismatch->outcome)->toBe(RewardEvaluationOutcome::Rejected)
        ->and($mismatch->reasons)->toBe(['beneficiary_mismatch']);
});
