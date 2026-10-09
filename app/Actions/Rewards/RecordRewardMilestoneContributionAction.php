<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Contracts\Rewards\RewardSourceFactReader;
use App\Enums\Rewards\RewardAwardStatus;
use App\Enums\Rewards\RewardBeneficiaryRole;
use App\Enums\Rewards\RewardComponent;
use App\Enums\Rewards\RewardEligibleOutcome;
use App\Enums\Rewards\RewardMilestoneStatus;
use App\Enums\Rewards\RewardVerificationLevel;
use App\Models\RewardAward;
use App\Models\RewardEnrollment;
use App\Models\RewardEnrollmentAcceptance;
use App\Models\RewardEnrollmentPeriod;
use App\Models\RewardMilestoneContribution;
use App\Models\RewardMilestoneCycle;
use App\Models\RewardProgram;
use App\Models\RewardProgramVersion;
use App\Support\Rewards\NullRewardSourceFactReader;
use App\Support\Rewards\RewardLedgerEntries;
use App\Support\Rewards\RewardMilestoneCycleIdentity;
use App\Support\Rewards\RewardRuleValidator;
use App\Support\Rewards\RewardSourceFact;
use App\Support\Rewards\RewardVersionResolver;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Milestone contribution recording with deterministic cycle achievement.
 *
 * Every contribution must name an authorized published milestone definition
 * (program version rules at the fact's earning time), a qualified canonical
 * source fact through the reader boundary, and a covering enrollment and
 * acceptance. Unknown programs, unpublished or mismatched definitions,
 * unknown/unclaimed/self/bot/cancelled/invalid/duplicate/incompatible facts,
 * and missing/stale consent are all refused. The cycle identity (recipient,
 * family, milestone_key, fixed window_start) is computable from the fact's
 * earning time. Re-recording the same source returns the existing
 * contribution; reaching the target achieves the cycle and mints exactly one
 * milestone award with frozen policy/enrollment/acceptance provenance (never
 * null aggregates). Milestone rewards are recognition/points/badge only;
 * cash/benefit settlement is WP6 scope.
 *
 * @return array{cycle: RewardMilestoneCycle, contribution: RewardMilestoneContribution, achieved: bool}
 */
final readonly class RecordRewardMilestoneContributionAction
{
    use AsAction;

    public function __construct(
        private ?RewardSourceFactReader $sourceReader = null,
    ) {}

    /**
     * @param  array<string, mixed>  $rewardConfig  Must exactly match the published definition reward.
     * @param  array<string, mixed>|null  $sourceSnapshot  Must match the canonical fact snapshot when supplied.
     * @return array{cycle: RewardMilestoneCycle, contribution: RewardMilestoneContribution, achieved: bool}
     */
    public function handle(
        string $recipientType,
        string $recipientId,
        string $familyKey,
        string $milestoneKey,
        int $targetCount,
        int $windowDays,
        RewardComponent|string $rewardComponent,
        array $rewardConfig,
        string $sourceBusinessKey,
        string $contributorType,
        string $contributorId,
        RewardBeneficiaryRole|string $contributorRole,
        RewardEligibleOutcome|string $outcome,
        CarbonInterface $occurredAt,
        string $programId,
        ?array $sourceSnapshot = null,
        ?CarbonInterface $contributedAt = null,
        string $actorType = 'system',
        ?string $actorId = null,
    ): array {
        CreditRewardPointsAction::assertRecipient($recipientType, $recipientId);
        CreditRewardPointsAction::assertFamily($familyKey);
        CreditRewardPointsAction::assertActor($actorType, $actorId);
        RewardMilestoneCycleIdentity::assertDefinition($milestoneKey, $targetCount, $windowDays);

        $component = $rewardComponent instanceof RewardComponent ? $rewardComponent : RewardComponent::tryFrom($rewardComponent);

        if (! $component instanceof RewardComponent) {
            throw new InvalidArgumentException('Unknown milestone reward component.');
        }

        if ($component->requiresMoneyOrBenefitFulfillment()) {
            throw new InvalidArgumentException('Milestone cash/benefit settlement is WP6 finance scope; use recognition/points/badge here.');
        }

        $reward = $this->normalizeReward($component, $rewardConfig);

        if ($sourceBusinessKey === '' || mb_strlen($sourceBusinessKey) > 191) {
            throw new InvalidArgumentException('Source business key must be 1..191 characters.');
        }

        $role = $contributorRole instanceof RewardBeneficiaryRole ? $contributorRole : RewardBeneficiaryRole::tryFrom($contributorRole);

        if (! $role instanceof RewardBeneficiaryRole) {
            throw new InvalidArgumentException('Unknown contributor role.');
        }

        $outcomeEnum = $outcome instanceof RewardEligibleOutcome ? $outcome : RewardEligibleOutcome::tryFrom($outcome);

        if (! $outcomeEnum instanceof RewardEligibleOutcome) {
            throw new InvalidArgumentException('Contribution outcome must be allowlisted.');
        }

        if (! Str::isUuid($programId)) {
            throw new InvalidArgumentException('Program id must be a UUID.');
        }

        if ($contributorType !== 'user' || ! Str::isUuid($contributorId)) {
            throw new InvalidArgumentException('Contributor must be a user UUID.');
        }

        if ($contributorId !== $recipientId) {
            throw new InvalidArgumentException('Milestone contributions must be the recipient\u2019s own qualifying facts.');
        }

        $occurred = CarbonImmutable::parse($occurredAt)->setTimezone('UTC');
        $at = $contributedAt === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($contributedAt)->setTimezone('UTC');

        if ($occurred->greaterThan($at)) {
            throw new InvalidArgumentException('Contribution occurrence cannot be after its recording time.');
        }

        $reader = $this->sourceReader ?? new NullRewardSourceFactReader;
        $firstRead = $reader->read($sourceBusinessKey);

        if (! $firstRead instanceof RewardSourceFact) {
            throw new InvalidArgumentException('Refused: unknown_source has no readable source fact.');
        }

        $this->assertFactQualifies($firstRead, $recipientId, $outcomeEnum, $occurred, $sourceSnapshot);

        $windowStart = RewardMilestoneCycleIdentity::windowStart(
            $firstRead->occurredAt->setTimezone('UTC'), $windowDays
        );
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return DB::transaction(fn (): array => $this->attempt(
                    $recipientType, $recipientId, $familyKey, $milestoneKey, $targetCount, $windowDays,
                    $component, $reward, $sourceBusinessKey, $contributorType, $contributorId, $role,
                    $outcomeEnum, $occurred, $programId, $firstRead, $reader, $at, $actorType, $actorId, $windowStart,
                ));
            } catch (QueryException $exception) {
                if ($attempt >= 3 || (! CreateRewardProgramAction::isUniqueViolation($exception) && ! self::isDeadlock($exception))) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>|null  $sourceSnapshot
     */
    private function assertFactQualifies(
        RewardSourceFact $fact,
        string $recipientId,
        RewardEligibleOutcome $outcome,
        CarbonImmutable $occurred,
        ?array $sourceSnapshot,
    ): void {
        if ($fact->outcome !== $outcome) {
            throw new InvalidArgumentException('Contribution outcome must match the canonical source fact.');
        }

        if ($fact->occurredAt->setTimezone('UTC')->toIso8601String() !== $occurred->setTimezone('UTC')->toIso8601String()) {
            throw new InvalidArgumentException('Contribution occurrence must match the canonical source fact; no backdated earning authority.');
        }

        if ($sourceSnapshot !== null && $sourceSnapshot !== $fact->snapshot()) {
            throw new InvalidArgumentException('Contribution snapshot must match the canonical source fact.');
        }

        if (! $fact->isValid) {
            throw new InvalidArgumentException('Refused: source_invalid contributions never count.');
        }

        if ($fact->isBot) {
            throw new InvalidArgumentException('Refused: source_bot_excluded contributions never count.');
        }

        if ($fact->isCancelled) {
            throw new InvalidArgumentException('Refused: source_cancelled contributions never count.');
        }

        if ($fact->correctionStatus->value === 'withdrawn') {
            throw new InvalidArgumentException('Refused: source_withdrawn contributions never count.');
        }

        if ($fact->fraudStatus->value === 'confirmed') {
            throw new InvalidArgumentException('Refused: fraud_confirmed contributions never count.');
        }

        if (! $fact->isClaimedIdentity) {
            throw new InvalidArgumentException('Refused: unclaimed guest facts never count.');
        }

        if (! $fact->isAttributed) {
            throw new InvalidArgumentException('Refused: unattributed facts never count.');
        }

        if ($fact->actorId !== null && $fact->sharerId !== null && $fact->actorId === $fact->sharerId) {
            throw new InvalidArgumentException('Refused: self-credited facts never count.');
        }

        if ($fact->sharerId !== $recipientId && $fact->actorId !== $recipientId) {
            throw new InvalidArgumentException('Refused: contributing fact does not involve the recipient.');
        }
    }

    /**
     * @param  array<string, mixed>  $rewardConfig
     * @return array<string, mixed>
     */
    private function normalizeReward(RewardComponent $component, array $rewardConfig): array
    {
        return match ($component) {
            RewardComponent::Points => $this->normalizePoints($rewardConfig),
            RewardComponent::Badge => $this->normalizeBadge($rewardConfig),
            RewardComponent::Recognition => [
                'type' => 'recognition',
                'template' => is_string($rewardConfig['template'] ?? null) ? $rewardConfig['template'] : 'quiet-thanks',
            ],
            default => throw new InvalidArgumentException('Milestone reward component must be recognition, points, or badge.'),
        };
    }

    /**
     * @param  array<string, mixed>  $rewardConfig
     * @return array<string, mixed>
     */
    private function normalizePoints(array $rewardConfig): array
    {
        $points = $rewardConfig['points'] ?? null;
        $unit = $rewardConfig['unit'] ?? null;

        if (! is_int($points) || $points < 1 || $points > RewardRuleValidator::MAX_POINTS) {
            throw new InvalidArgumentException('Milestone points must be an integer 1..'.RewardRuleValidator::MAX_POINTS.'.');
        }

        if (! is_string($unit)) {
            throw new InvalidArgumentException('Milestone points require a unit.');
        }

        CreditRewardPointsAction::assertUnit($unit);

        return ['type' => 'points', 'points' => $points, 'unit' => $unit];
    }

    /**
     * @param  array<string, mixed>  $rewardConfig
     * @return array<string, mixed>
     */
    private function normalizeBadge(array $rewardConfig): array
    {
        $badgeKey = $rewardConfig['badge_key'] ?? null;

        if (! is_string($badgeKey) || preg_match('/^[a-z0-9][a-z0-9_\-]{0,63}$/', $badgeKey) !== 1) {
            throw new InvalidArgumentException('Milestone badge_key must be a 1..64 slug.');
        }

        return ['type' => 'badge', 'badge_key' => $badgeKey];
    }

    /**
     * @param  array<string, mixed>  $reward
     * @return array{cycle: RewardMilestoneCycle, contribution: RewardMilestoneContribution, achieved: bool}
     */
    private function attempt(
        string $recipientType,
        string $recipientId,
        string $familyKey,
        string $milestoneKey,
        int $targetCount,
        int $windowDays,
        RewardComponent $component,
        array $reward,
        string $sourceBusinessKey,
        string $contributorType,
        string $contributorId,
        RewardBeneficiaryRole $role,
        RewardEligibleOutcome $outcomeEnum,
        CarbonImmutable $occurred,
        string $programId,
        RewardSourceFact $firstRead,
        RewardSourceFactReader $reader,
        CarbonImmutable $at,
        string $actorType,
        ?string $actorId,
        CarbonImmutable $windowStart,
    ): array {
        $program = RewardProgram::query()->whereKey($programId)->lockForUpdate()->first();

        if (! $program instanceof RewardProgram) {
            throw new InvalidArgumentException('Unknown reward program.');
        }

        if ($program->family_key !== $familyKey) {
            throw new InvalidArgumentException('Milestone family must match the program family; cross-program definitions never mix.');
        }

        if ($program->status->value !== 'active') {
            throw new InvalidArgumentException('Refused: program_not_active stops new milestone qualification.');
        }

        $factOccurred = $firstRead->occurredAt->setTimezone('UTC');

        /** @var list<RewardProgramVersion> $versions */
        $versions = RewardProgramVersion::query()
            ->where('program_id', $program->getKey())
            ->orderBy('version')
            ->get()
            ->all();

        $timeline = array_map(static fn (RewardProgramVersion $version): array => [
            'id' => (string) $version->getKey(),
            'version' => (int) $version->version,
            'effective_from' => CarbonImmutable::parse($version->effective_from)->setTimezone('UTC'),
        ], $versions);

        $applicable = RewardVersionResolver::resolveAt($timeline, $factOccurred);
        $version = null;

        if ($applicable !== null) {
            foreach ($versions as $candidate) {
                if ((string) $candidate->getKey() === $applicable['id']) {
                    $version = $candidate;
                    break;
                }
            }
        }

        if (! $version instanceof RewardProgramVersion) {
            throw new InvalidArgumentException('Refused: version_missing has no published version at the earning time.');
        }

        $published = $this->publishedDefinition($version, $milestoneKey);

        if ($published === null) {
            throw new InvalidArgumentException('Refused: milestone definition is not published in the applicable version.');
        }

        if ((int) $published['target_count'] !== $targetCount
            || (int) $published['window_days'] !== $windowDays
            || $this->normalizeReward($component, (array) $published['reward']) !== $reward
        ) {
            throw new InvalidArgumentException('Milestone definition mismatches the published definition.');
        }

        $this->assertOutcomeCompatible($version, $outcomeEnum, $firstRead);

        $enrollment = RewardEnrollment::query()
            ->where('program_id', $program->getKey())
            ->where('member_type', $recipientType)
            ->where('member_id', $recipientId)
            ->lockForUpdate()
            ->first();

        if (! $enrollment instanceof RewardEnrollment) {
            throw new InvalidArgumentException('Refused: enrollment_missing for this recipient.');
        }

        $activePeriod = RewardEnrollmentPeriod::query()
            ->where('enrollment_id', $enrollment->getKey())
            ->where('joined_at', '<=', $factOccurred)
            ->where(function ($query) use ($factOccurred): void {
                $query->whereNull('left_at')->orWhere('left_at', '>', $factOccurred);
            })
            ->orderByDesc('joined_at')
            ->lockForUpdate()
            ->first();

        if (! $activePeriod instanceof RewardEnrollmentPeriod) {
            throw new InvalidArgumentException('Refused: enrollment_left stops new milestone qualification.');
        }

        $coveringAcceptance = RewardEnrollmentAcceptance::query()
            ->where('enrollment_id', $enrollment->getKey())
            ->where('accepted_at', '<=', $factOccurred)
            ->where('material_terms_hash', (string) $version->material_terms_hash)
            ->orderByDesc('accepted_at')
            ->orderByDesc('seq')
            ->lockForUpdate()
            ->first();

        if (! $coveringAcceptance instanceof RewardEnrollmentAcceptance) {
            throw new InvalidArgumentException('Refused: acceptance_missing covers no terms at the earning time.');
        }

        $secondRead = $reader->read($sourceBusinessKey);

        if (! $secondRead instanceof RewardSourceFact || $secondRead->snapshot() !== $firstRead->snapshot()) {
            throw new InvalidArgumentException('Source fact changed during recording; retry with a fresh read.');
        }

        $cycle = RewardMilestoneCycle::query()
            ->where('recipient_type', $recipientType)
            ->where('recipient_id', $recipientId)
            ->where('family_key', $familyKey)
            ->where('milestone_key', $milestoneKey)
            ->where('window_start', $windowStart)
            ->lockForUpdate()
            ->first();

        if (! $cycle instanceof RewardMilestoneCycle) {
            $cycle = RewardMilestoneCycle::query()->create([
                'recipient_type' => $recipientType,
                'recipient_id' => $recipientId,
                'family_key' => $familyKey,
                'program_id' => $programId,
                'milestone_key' => $milestoneKey,
                'window_start' => $windowStart,
                'window_days' => $windowDays,
                'target_count' => $targetCount,
                'current_count' => 0,
                'reward_snapshot' => $reward,
                'status' => RewardMilestoneStatus::Open,
                'award_id' => null,
                'achieved_at' => null,
                'invalidated_at' => null,
            ]);
        }

        if ((string) $cycle->program_id !== $programId) {
            throw new InvalidArgumentException('Milestone cycle belongs to a different program; cross-program definitions never mix.');
        }

        if ($cycle->target_count !== $targetCount
            || $cycle->window_days !== $windowDays
            || $cycle->reward_snapshot !== $reward
        ) {
            throw new InvalidArgumentException('Milestone definition mismatches the frozen cycle; a cycle keeps one definition.');
        }

        $existingContribution = RewardMilestoneContribution::query()
            ->where('cycle_id', $cycle->getKey())
            ->where('source_business_key', $sourceBusinessKey)
            ->lockForUpdate()
            ->first();

        if ($existingContribution instanceof RewardMilestoneContribution) {
            return ['cycle' => $cycle->refresh(), 'contribution' => $existingContribution, 'achieved' => false];
        }

        $contribution = RewardMilestoneContribution::query()->create([
            'cycle_id' => $cycle->getKey(),
            'source_business_key' => $sourceBusinessKey,
            'contributor_type' => $contributorType,
            'contributor_id' => $contributorId,
            'contributor_role' => $role->value,
            'outcome' => $outcomeEnum->value,
            'source_snapshot' => $firstRead->snapshot(),
            'occurred_at' => $factOccurred,
            'contributed_at' => $at,
            'revoked_at' => null,
            'revoke_reason' => null,
        ]);

        $count = RewardMilestoneContribution::query()
            ->where('cycle_id', $cycle->getKey())
            ->whereNull('revoked_at')
            ->count();

        $cycle->forceFill(['current_count' => $count])->save();

        if ($count < $targetCount || $cycle->status !== RewardMilestoneStatus::Open) {
            return ['cycle' => $cycle->refresh(), 'contribution' => $contribution, 'achieved' => false];
        }

        $milestoneSource = RewardMilestoneCycleIdentity::milestoneSourceKey($familyKey, $milestoneKey, $windowStart);

        $existingAward = RewardAward::query()
            ->where('recipient_type', $recipientType)
            ->where('recipient_id', $recipientId)
            ->where('source_business_key', $milestoneSource)
            ->where('family_key', $familyKey)
            ->where('component', $component->value)
            ->lockForUpdate()
            ->first();

        if ($existingAward instanceof RewardAward) {
            $cycle->forceFill([
                'status' => RewardMilestoneStatus::Achieved,
                'award_id' => $cycle->award_id ?? $existingAward->getKey(),
                'achieved_at' => $cycle->achieved_at ?? $at,
            ])->save();

            try {
                PlanRewardGratitudeDigestAction::run($recipientType, $recipientId, ['milestones'], $at);
            } catch (InvalidArgumentException) {
                // No slot for this recipient; the achievement stands.
            }

            return ['cycle' => $cycle->refresh(), 'contribution' => $contribution, 'achieved' => true];
        }

        $award = RewardAward::query()->create([
            'program_id' => $programId,
            'policy_version_id' => $version->getKey(),
            'enrollment_id' => $enrollment->getKey(),
            'acceptance_id' => $coveringAcceptance->getKey(),
            'recipient_type' => $recipientType,
            'recipient_id' => $recipientId,
            'beneficiary_role' => RewardBeneficiaryRole::Recipient,
            'source_business_key' => $milestoneSource,
            'family_key' => $familyKey,
            'component' => $component,
            'status' => RewardAwardStatus::Approved,
            'amount_snapshot' => $reward,
            'policy_snapshot' => [
                'policy_version_id' => (string) $version->getKey(),
                'policy_version' => (int) $version->version,
                'milestone_key' => $milestoneKey,
                'target_count' => $targetCount,
                'window_days' => $windowDays,
                'window_start' => $windowStart->toIso8601String(),
            ],
            'consent_snapshot' => [
                'acceptance_id' => (string) $coveringAcceptance->getKey(),
                'accepted_at' => CarbonImmutable::parse($coveringAcceptance->accepted_at)->setTimezone('UTC')->toIso8601String(),
                'material_terms_hash' => (string) $coveringAcceptance->material_terms_hash,
                'terms_label' => (string) $coveringAcceptance->terms_label,
            ],
            'source_snapshot' => [
                'cycle_id' => (string) $cycle->getKey(),
                'contributing_sources' => RewardMilestoneContribution::query()
                    ->where('cycle_id', $cycle->getKey())
                    ->whereNull('revoked_at')
                    ->orderBy('source_business_key')
                    ->pluck('source_business_key')
                    ->all(),
            ],
            'approved_at' => $at,
        ]);

        RewardLedgerEntries::appendAwardEntry(
            $award, 'none', RewardAwardStatus::Approved->value,
            $actorType, $actorId, 'system', 'milestone-achieved',
            ['milestone_key' => $milestoneKey, 'contributions' => $count],
            null, $at,
        );

        $cycle->forceFill([
            'status' => RewardMilestoneStatus::Achieved,
            'award_id' => $award->getKey(),
            'achieved_at' => $at,
        ])->save();

        try {
            PlanRewardGratitudeDigestAction::run($recipientType, $recipientId, ['milestones'], $at);
        } catch (InvalidArgumentException) {
            // No slot for this recipient; the achievement stands.
        }

        return ['cycle' => $cycle->refresh(), 'contribution' => $contribution, 'achieved' => true];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function publishedDefinition(RewardProgramVersion $version, string $milestoneKey): ?array
    {
        $milestones = $version->rules['milestones'] ?? null;

        if (! is_array($milestones)) {
            return null;
        }

        foreach ($milestones as $definition) {
            if (is_array($definition) && ($definition['key'] ?? null) === $milestoneKey) {
                return $definition;
            }
        }

        return null;
    }

    private function assertOutcomeCompatible(
        RewardProgramVersion $version,
        RewardEligibleOutcome $outcome,
        RewardSourceFact $fact,
    ): void {
        $rules = $version->rules;
        $eligibility = is_array($rules['eligibility'] ?? null) ? $rules['eligibility'] : [];
        $outcomes = is_array($eligibility['outcomes'] ?? null) ? $eligibility['outcomes'] : [];

        if ($outcomes !== [] && ! in_array($outcome->value, $outcomes, true)) {
            throw new InvalidArgumentException('Refused: incompatible outcome is not eligible under the published version.');
        }

        $minimum = RewardVerificationLevel::tryFrom((string) ($eligibility['min_verification'] ?? ''));

        if ($minimum instanceof RewardVerificationLevel
            && ! $fact->verification->satisfiesFor($minimum, $outcome)
        ) {
            throw new InvalidArgumentException('Refused: contributing fact verification is below the published minimum.');
        }
    }

    private static function isDeadlock(QueryException $exception): bool
    {
        $code = (string) $exception->getCode();

        if (in_array($code, ['40001', '1213'], true)) {
            return true;
        }

        return stripos($exception->getMessage(), 'deadlock') !== false;
    }
}
