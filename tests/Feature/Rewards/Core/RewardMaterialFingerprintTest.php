<?php

use App\Actions\Rewards\EnrollRewardMemberAction;
use App\Actions\Rewards\PreviewRewardQualificationAction;
use App\Actions\Rewards\PublishRewardProgramVersionAction;
use App\Actions\Rewards\TransitionRewardProgramStatusAction;
use App\Enums\Rewards\RewardComponent;
use App\Enums\Rewards\RewardPreviewDecision;
use App\Enums\Rewards\RewardPreviewReason;
use App\Enums\Rewards\RewardProgramStatus;
use App\Support\Rewards\RewardMaterialTerms;
use App\Support\Rewards\RewardRuleValidator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Feature\Rewards\Core\Support\RewardCoreTestFixtures;

it('blocks new earning on rule-only material change while old references stay immutable', function () {
    [$program, $v1] = RewardCoreTestFixtures::seedVersionedProgram('core-c1-rule');
    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready);
    $program = TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active);

    $memberId = (string) Str::uuid();
    EnrollRewardMemberAction::run($program, 'user', $memberId, $v1, 'member-portal', CarbonImmutable::parse('2026-07-05T00:00:00Z'));

    $member = ['member_type' => 'user', 'member_id' => $memberId];
    $eligible = PreviewRewardQualificationAction::run(
        $program,
        RewardComponent::Points,
        [
            'source_business_key' => 'share-outcome:event_checkin:c1-1',
            'outcome' => 'event_checkin',
            'category' => null,
            'occurred_at' => '2026-07-10T00:00:00Z',
            'verification' => 'accepted',
            'is_first_time' => true,
            'actor_id' => 'actor-1',
            'sharer_id' => 'sharer-1',
            'is_claimed_identity' => true,
            'is_attributed' => true,
        ],
        CarbonImmutable::parse('2026-07-11T00:00:00Z'),
        $member,
    );

    expect($eligible->decision)->toBe(RewardPreviewDecision::Eligible);

    $rules2 = RewardCoreTestFixtures::rules();
    $rules2['components'][0]['points'] = 99;
    $terms2 = RewardCoreTestFixtures::terms();
    $terms2['version_label'] = 'draft-2';

    $effective = CarbonImmutable::parse('2026-08-01T00:00:00Z');
    $v2 = PublishRewardProgramVersionAction::run($program, $effective, $rules2, $terms2, null, null, null, $effective);

    expect($v2->material_terms_hash)->not->toBe($v1->material_terms_hash);

    $stale = PreviewRewardQualificationAction::run(
        $program,
        RewardComponent::Points,
        [
            'source_business_key' => 'share-outcome:event_checkin:c1-2',
            'outcome' => 'event_checkin',
            'category' => null,
            'occurred_at' => '2026-08-10T00:00:00Z',
            'verification' => 'accepted',
            'is_first_time' => true,
            'actor_id' => 'actor-1',
            'sharer_id' => 'sharer-1',
            'is_claimed_identity' => true,
            'is_attributed' => true,
        ],
        CarbonImmutable::parse('2026-08-11T00:00:00Z'),
        $member,
    );

    expect($stale->reasons)->toBe([RewardPreviewReason::AcceptanceStaleMaterialChange]);

    $oldAcceptance = $program->enrollments()->where('member_id', $memberId)->first()->acceptances()->orderBy('seq')->first();

    expect($oldAcceptance->material_terms_hash)->toBe($v1->material_terms_hash)
        ->and($oldAcceptance->policy_version_id)->toBe((string) $v1->getKey());

    EnrollRewardMemberAction::run($program, 'user', $memberId, $v2, 'member-portal', CarbonImmutable::parse('2026-08-05T00:00:00Z'));

    $fresh = PreviewRewardQualificationAction::run(
        $program,
        RewardComponent::Points,
        [
            'source_business_key' => 'share-outcome:event_checkin:c1-3',
            'outcome' => 'event_checkin',
            'category' => null,
            'occurred_at' => '2026-08-10T00:00:00Z',
            'verification' => 'accepted',
            'is_first_time' => true,
            'actor_id' => 'actor-1',
            'sharer_id' => 'sharer-1',
            'is_claimed_identity' => true,
            'is_attributed' => true,
        ],
        CarbonImmutable::parse('2026-08-11T00:00:00Z'),
        $member,
    );

    expect($fresh->decision)->toBe(RewardPreviewDecision::Eligible);
    expect($oldAcceptance->refresh()->material_terms_hash)->toBe($v1->material_terms_hash);
});

it('changes the fingerprint on component enablement, value, eligibility, and window edits', function () {
    $rules = RewardCoreTestFixtures::rules();
    $terms = RewardCoreTestFixtures::terms();
    $base = RewardMaterialTerms::hashForVersion($rules, $terms);

    $value = $rules;
    $value['components'][0]['points'] = 11;
    expect(RewardMaterialTerms::hashForVersion($value, $terms))->not->toBe($base);

    $outcomes = $rules;
    $outcomes['eligibility']['outcomes'] = ['signup'];
    expect(RewardMaterialTerms::hashForVersion($outcomes, $terms))->not->toBe($base);

    $verification = $rules;
    $verification['eligibility']['min_verification'] = 'staff_verified';
    expect(RewardMaterialTerms::hashForVersion($verification, $terms))->not->toBe($base);

    $lookback = $rules;
    $lookback['windows']['preclaim_lookback_days'] = 7;
    expect(RewardMaterialTerms::hashForVersion($lookback, $terms))->not->toBe($base);

    $material = $terms;
    $material['material']['earn_basis'] = 'Different basis.';
    expect(RewardMaterialTerms::hashForVersion($rules, $material))->not->toBe($base);
});

it('is order-insensitive but cannot be masked by an unchanged material map', function () {
    $rules = RewardCoreTestFixtures::rules();
    $terms = RewardCoreTestFixtures::terms();

    $reorderedRules = [];
    foreach (array_reverse(array_keys($rules)) as $key) {
        $reorderedRules[$key] = $rules[$key];
    }

    $reorderedTerms = $terms;
    $reorderedTerms['material'] = [];
    foreach (array_reverse(array_keys($terms['material'])) as $key) {
        $reorderedTerms['material'][$key] = $terms['material'][$key];
    }

    expect(RewardMaterialTerms::hashForVersion($reorderedRules, $reorderedTerms))
        ->toBe(RewardMaterialTerms::hashForVersion($rules, $terms));

    $masked = $rules;
    $masked['eligibility']['outcomes'] = ['signup'];

    expect(RewardMaterialTerms::hashForVersion($masked, $terms))
        ->not->toBe(RewardMaterialTerms::hashForVersion($rules, $terms));
});

it('ignores nonmaterial copy changes', function () {
    $rules = RewardCoreTestFixtures::rules();
    $terms = RewardCoreTestFixtures::terms();
    $base = RewardMaterialTerms::hashForVersion($rules, $terms);

    $copy = $terms;
    $copy['label'] = 'A different display label';
    $copy['body'] = 'Reworded body copy with the same meaning.';
    $copy['version_label'] = 'draft-2-copy';

    expect(RewardMaterialTerms::hashForVersion($rules, $copy))->toBe($base);
});

it('rejects missing mandatory clauses and mode mismatches', function () {
    $validator = new RewardRuleValidator;
    $rules = RewardCoreTestFixtures::rules();
    $terms = RewardCoreTestFixtures::terms();

    $missing = $terms;
    unset($missing['material']['earn_basis']);
    expect($validator->termsErrors($missing))->toContain('Material clause [earn_basis] is mandatory and must be a non-empty string up to 2000 characters.');

    $mismatched = $terms;
    $mismatched['material']['mode'] = 'cash';
    expect($validator->termsErrors($mismatched, $rules))->toContain('Material clause [mode] must match the authoritative rules mode [points].');
});
