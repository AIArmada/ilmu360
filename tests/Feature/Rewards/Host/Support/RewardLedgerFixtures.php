<?php

declare(strict_types=1);

namespace Tests\Feature\Rewards\Host\Support;

use AIArmada\Rewards\Actions\CreateRewardProgramAction;
use AIArmada\Rewards\Actions\EnrollRewardMemberAction;
use AIArmada\Rewards\Actions\PublishRewardProgramVersionAction;
use AIArmada\Rewards\Actions\TransitionRewardProgramStatusAction;
use AIArmada\Rewards\Enums\RewardBeneficiaryRole;
use AIArmada\Rewards\Enums\RewardComponent;
use AIArmada\Rewards\Enums\RewardProgramMode;
use AIArmada\Rewards\Enums\RewardProgramStatus;
use AIArmada\Rewards\Enums\RewardSourceCorrectionStatus;
use AIArmada\Rewards\Enums\RewardSourceFraudStatus;
use AIArmada\Rewards\Enums\RewardVerificationLevel;
use AIArmada\Rewards\Models\RewardEnrollment;
use AIArmada\Rewards\Models\RewardEnrollmentAcceptance;
use AIArmada\Rewards\Models\RewardProgram;
use AIArmada\Rewards\Models\RewardProgramVersion;
use AIArmada\Rewards\Support\RewardPresetBuilders;
use AIArmada\Rewards\Support\RewardSourceFact;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Own ledger fixture loader: builds versioned active programs, enrollments,
 * and scripted source facts through the real core actions. FIXTURE facts
 * only — never presented as source integration.
 */
final class RewardLedgerFixtures
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(RewardProgramMode $mode = RewardProgramMode::Points): array
    {
        /** @var array<string, mixed> $rules */
        $rules = RewardPresetBuilders::for($mode)['rules'];

        if ($mode === RewardProgramMode::Cash) {
            $rules['components'] = [
                ['type' => 'cash', 'minor_units' => 500, 'currency' => 'MYR'],
            ];
        }

        if ($mode === RewardProgramMode::Benefit) {
            $rules['components'] = [
                ['type' => 'benefit', 'benefit_key' => 'ebook-credit', 'conditions' => ['Member account in good standing', 'One redemption per award']],
            ];
        }

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    public static function terms(RewardProgramMode $mode = RewardProgramMode::Points): array
    {
        /** @var array<string, mixed> $terms */
        $terms = RewardPresetBuilders::for($mode)['terms'];

        return $terms;
    }

    /**
     * Seeds a draft program plus its first published version (effective ==
     * published for deterministic fixtures).
     *
     * @param  array<string, mixed>  $ruleOverrides  Section => [key => value] merges.
     * @return array{0: RewardProgram, 1: RewardProgramVersion}
     */
    public static function seedVersionedProgram(
        string $prefix,
        RewardProgramMode $mode = RewardProgramMode::Points,
        array $ruleOverrides = [],
        string $effective = '2026-06-01T00:00:00Z',
    ): array {
        $program = CreateRewardProgramAction::run(
            $prefix.'-'.Str::lower(Str::random(6)),
            'Ledger test program',
            $mode,
            null,
            null,
            'ledger-test',
        );

        $rules = self::rules($mode);

        foreach ($ruleOverrides as $section => $values) {
            foreach ((array) $values as $key => $value) {
                $rules[$section][$key] = $value;
            }
        }

        $at = CarbonImmutable::parse($effective);
        $version = app(PublishRewardProgramVersionAction::class)->handle(
            $program,
            $at,
            $rules,
            self::terms($mode),
            null,
            null,
            null,
            $at,
        );

        return [$program->refresh(), $version];
    }

    /**
     * Seeds a versioned program driven to active, plus an enrolled member.
     *
     * @return array{0: RewardProgram, 1: RewardProgramVersion, 2: RewardEnrollment, 3: string}
     */
    public static function seedActiveEnrolled(
        string $prefix,
        RewardProgramMode $mode = RewardProgramMode::Points,
        array $ruleOverrides = [],
        string $effective = '2026-06-01T00:00:00Z',
        string $enrolledAt = '2026-06-10T00:00:00Z',
    ): array {
        [$program, $version] = self::seedVersionedProgram($prefix, $mode, $ruleOverrides, $effective);

        TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready);
        TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active);

        $memberId = (string) Str::uuid();
        $enrollment = EnrollRewardMemberAction::run(
            $program,
            'user',
            $memberId,
            $version,
            'member-portal',
            CarbonImmutable::parse($enrolledAt),
        );

        return [$program->refresh(), $version, $enrollment, $memberId];
    }

    /**
     * A clean scripted source fact (FIXTURE): attributed claimed checkin,
     * staff-verified, sharer-bound to the recipient.
     */
    public static function fact(
        string $sourceBusinessKey,
        string $recipientId,
        string $occurredAt = '2026-06-12T00:00:00Z',
        string $outcome = 'event_checkin',
        array $overrides = [],
    ): RewardSourceFact {
        $base = [
            'sourceBusinessKey' => $sourceBusinessKey,
            'outcome' => $outcome,
            'category' => null,
            'occurredAt' => CarbonImmutable::parse($occurredAt),
            'verification' => RewardVerificationLevel::StaffVerified,
            'isFirstTime' => true,
            'actorId' => (string) Str::uuid(),
            'sharerId' => $recipientId,
            'actorRole' => RewardBeneficiaryRole::Participant,
            'isClaimedIdentity' => true,
            'isAttributed' => true,
            'isAmbiguousDevice' => false,
            'isBot' => false,
            'isCancelled' => false,
            'isValid' => true,
            'fraudStatus' => RewardSourceFraudStatus::Clear,
            'correctionStatus' => RewardSourceCorrectionStatus::Current,
            'correctionId' => null,
        ];

        $merged = [...$base, ...$overrides];

        return new RewardSourceFact(
            sourceBusinessKey: $merged['sourceBusinessKey'],
            outcome: $merged['outcome'],
            category: $merged['category'],
            occurredAt: $merged['occurredAt'],
            verification: $merged['verification'],
            isFirstTime: $merged['isFirstTime'],
            actorId: $merged['actorId'],
            sharerId: $merged['sharerId'],
            actorRole: $merged['actorRole'],
            isClaimedIdentity: $merged['isClaimedIdentity'],
            isAttributed: $merged['isAttributed'],
            isAmbiguousDevice: $merged['isAmbiguousDevice'],
            isBot: $merged['isBot'],
            isCancelled: $merged['isCancelled'],
            isValid: $merged['isValid'],
            fraudStatus: $merged['fraudStatus'],
            correctionStatus: $merged['correctionStatus'],
            correctionId: $merged['correctionId'],
        );
    }

    public static function staffId(): string
    {
        return (string) Str::uuid();
    }

    /**
     * Coherent frozen snapshots for a factory-built ordinary award, mirroring
     * the exact shapes live evaluation freezes. Lets transition tests isolate
     * one broken dimension (amount, expiry) while the qualification gates see
     * coherent provenance.
     *
     * @return array{policy: array<string, mixed>, consent: array<string, mixed>, source: array<string, mixed>}
     */
    public static function frozenAwardSnapshots(
        RewardProgramVersion $version,
        RewardEnrollmentAcceptance $acceptance,
        RewardSourceFact $fact,
    ): array {
        $rules = $version->rules;

        return [
            'policy' => [
                'policy_version_id' => (string) $version->getKey(),
                'policy_version' => (int) $version->version,
                'effective_from' => CarbonImmutable::parse($version->effective_from)->setTimezone('UTC')->toIso8601String(),
                'material_terms_hash' => (string) $version->material_terms_hash,
                'eligibility' => $rules['eligibility'] ?? [],
                'windows' => $rules['windows'] ?? [],
                'stacking' => $rules['stacking'] ?? [],
                'components' => $rules['components'] ?? [],
            ],
            'consent' => [
                'acceptance_id' => (string) $acceptance->getKey(),
                'accepted_at' => CarbonImmutable::parse($acceptance->accepted_at)->setTimezone('UTC')->toIso8601String(),
                'material_terms_hash' => (string) $acceptance->material_terms_hash,
                'terms_label' => (string) $acceptance->terms_label,
            ],
            'source' => $fact->snapshot(),
        ];
    }

    /**
     * Test-only operational setup: marks a money/benefit program active so
     * held-candidate evaluation paths can be exercised. Production activation
     * of such programs stays blocked until the WP6 finance scope lands; this
     * bypasses only the lifecycle gate, never the evaluation gates.
     */
    public static function forceActive(RewardProgram $program): RewardProgram
    {
        $now = CarbonImmutable::parse('2026-06-05T00:00:00Z');

        RewardProgram::query()->whereKey($program->getKey())->update([
            'status' => RewardProgramStatus::Active->value,
            'ready_at' => $now,
            'active_at' => $now,
        ]);

        return $program->refresh();
    }

    public static function componentFor(RewardProgramMode $mode): RewardComponent
    {
        return match ($mode) {
            RewardProgramMode::Recognition => RewardComponent::Recognition,
            RewardProgramMode::Points => RewardComponent::Points,
            RewardProgramMode::Badges => RewardComponent::Badge,
            RewardProgramMode::Cash => RewardComponent::Cash,
            RewardProgramMode::Benefit => RewardComponent::Benefit,
            RewardProgramMode::Combined => RewardComponent::Points,
        };
    }
}
