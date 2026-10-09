<?php

declare(strict_types=1);

namespace App\Support\Rewards;

use App\Enums\Rewards\RewardProgramMode;

/**
 * Disabled draft preset builders for every program mode.
 *
 * Presets produce draft-only definitions (enabled=false, status=draft) that
 * require explicit staff setup before anything can activate. They never claim
 * activation capability: money/benefit presets truthfully list the missing
 * finance adapters instead of a fake capability registry, and every amount is
 * an editable draft default — administrators supply the real commitments.
 */
final class RewardPresetBuilders
{
    /**
     * @return array<string, mixed>
     */
    public static function for(RewardProgramMode $mode): array
    {
        $components = match ($mode) {
            RewardProgramMode::Recognition => [
                ['type' => 'recognition', 'template' => 'quiet-thanks'],
            ],
            RewardProgramMode::Points => [
                ['type' => 'points', 'points' => 10, 'unit' => 'pts'],
            ],
            RewardProgramMode::Badges => [
                ['type' => 'badge', 'badge_key' => 'first-share'],
            ],
            RewardProgramMode::Cash => [
                ['type' => 'cash', 'minor_units' => 500, 'currency' => 'MYR'],
            ],
            RewardProgramMode::Benefit => [
                ['type' => 'benefit', 'benefit_key' => 'ebook-credit', 'conditions' => ['Member account in good standing', 'One redemption per award']],
            ],
            RewardProgramMode::Combined => [
                ['type' => 'recognition', 'template' => 'quiet-thanks'],
                ['type' => 'points', 'points' => 10, 'unit' => 'pts'],
                ['type' => 'badge', 'badge_key' => 'first-share'],
            ],
        };

        $minVerification = $mode->involvesMoneyOrBenefitFulfillment() && $mode !== RewardProgramMode::Combined
            ? 'staff_verified'
            : 'accepted';

        $outcomes = match ($mode) {
            RewardProgramMode::Cash, RewardProgramMode::Benefit => ['event_checkin', 'event_submission'],
            default => ['signup', 'event_registration', 'event_checkin', 'event_submission'],
        };

        $rules = [
            'schema' => RewardRuleValidator::SCHEMA,
            'mode' => $mode->value,
            'components' => $components,
            'eligibility' => [
                'outcomes' => $outcomes,
                'categories' => [],
                'min_verification' => $minVerification,
                'first_only' => false,
                'exclude_self_credit' => true,
                'require_enrollment' => true,
                'require_claimed_identity' => true,
            ],
            'windows' => [
                'preclaim_lookback_days' => 30,
                'hold_days' => 0,
            ],
            'caps' => [
                ['scope' => 'recipient_source_component', 'limit' => 1],
            ],
            'stacking' => ['compatible_with' => []],
            'holds' => ['ambiguous_device' => true, 'manual_review_outcomes' => []],
        ];

        $blockedReasons = $mode->involvesMoneyOrBenefitFulfillment() && $mode !== RewardProgramMode::Combined
            ? [
                'preset-is-draft-only',
                'explicit-staff-setup-required',
                'funding-adapter-missing',
                'payee-verification-missing',
                'retention-policy-missing',
                'reversal-settlement-missing',
            ]
            : ['preset-is-draft-only', 'explicit-staff-setup-required'];

        return [
            'schema' => RewardRuleValidator::SCHEMA,
            'status' => 'draft',
            'enabled' => false,
            'activation_blocked' => true,
            'blocked_reasons' => $blockedReasons,
            'mode' => $mode->value,
            'rules' => $rules,
            'terms' => self::terms($mode),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return array_map(
            static fn (RewardProgramMode $mode): array => self::for($mode),
            RewardProgramMode::cases()
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function terms(RewardProgramMode $mode): array
    {
        return [
            'label' => 'Draft program terms (requires staff review)',
            'version_label' => 'draft-1',
            'body' => 'Draft only. An administrator must review every amount, rule, and condition before this program can be readied or activated. No reward is promised by this draft.',
            'material' => [
                'mode' => $mode->value,
                'earn_basis' => 'Verified eligible outcomes only; visits and unverified intent never qualify.',
                'changes' => 'Material changes apply prospectively after reacceptance; accrued obligations keep their frozen terms.',
                'no_ranking' => 'No public ranking, streak pressure, or claims of spiritual merit.',
            ],
        ];
    }
}
