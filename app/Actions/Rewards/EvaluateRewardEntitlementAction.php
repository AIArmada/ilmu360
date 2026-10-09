<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Contracts\Rewards\RewardSourceFactReader;
use App\Enums\Rewards\RewardAwardStatus;
use App\Enums\Rewards\RewardBeneficiaryRole;
use App\Enums\Rewards\RewardBudgetMovementKind;
use App\Enums\Rewards\RewardComponent;
use App\Enums\Rewards\RewardEvaluationOutcome;
use App\Enums\Rewards\RewardPreviewDecision;
use App\Enums\Rewards\RewardPreviewReason;
use App\Models\RewardAward;
use App\Models\RewardCapCounter;
use App\Models\RewardEnrollment;
use App\Models\RewardEnrollmentAcceptance;
use App\Models\RewardEnrollmentPeriod;
use App\Models\RewardProgram;
use App\Models\RewardProgramVersion;
use App\Models\RewardSourceComponentClaim;
use App\Models\RewardSourceCoordinator;
use App\Support\Rewards\NullRewardSourceFactReader;
use App\Support\Rewards\RewardBudgetLedger;
use App\Support\Rewards\RewardCapWindows;
use App\Support\Rewards\RewardEvaluationResult;
use App\Support\Rewards\RewardLedgerEntries;
use App\Support\Rewards\RewardLedgerLockOrder;
use App\Support\Rewards\RewardPreviewContext;
use App\Support\Rewards\RewardPreviewEngine;
use App\Support\Rewards\RewardPreviewFixture;
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
 * Live single-fact entitlement evaluation.
 *
 * System/job-driven (no member endpoint): reads the current source fact
 * through the reader boundary, resolves the applicable policy version at the
 * server decision time, and reuses the accepted pure preview engine for
 * policy gates over locked enrollment/acceptance/period snapshots. Live-only
 * checks — source validity/fraud/correction, beneficiary binding, bilateral
 * stacking, cap counters, canonical uniqueness — run under the global ledger
 * lock order (source coordinator -> claims -> programs -> enrollments ->
 * awards -> caps).
 *
 * Outcome contract:
 * - Awarded/held/rejected-on-merits freeze an award row: retries, version
 *   switches, and concurrent duplicates return the first award (duplicate
 *   flag) instead of minting a second one. Policy version is evidence only.
 * - Not-yet-authorized attempts (paused program, missing version/component,
 *   missing/left enrollment, stale consent, unclaimed guest, exhausted cap,
 *   unknown source, evidence after the decision) throw WITHOUT writing a
 *   row, so a later legitimate decision is never blocked by an early freeze.
 * - Cash/benefit components record held-prerequisite candidates only; actual
 *   financial fulfillment is WP6 scope and is never marked here.
 */
final readonly class EvaluateRewardEntitlementAction
{
    use AsAction;

    /**
     * @var list<string>
     */
    public const array RECIPIENT_TYPES = ['user'];

    /**
     * @var list<string>
     */
    private const array TRANSIENT_REASONS = [
        'program_not_active',
        'version_missing',
        'component_not_enabled',
        'enrollment_missing',
        'enrollment_left',
        'acceptance_missing',
        'acceptance_stale_material_change',
        'accepted_after_decision',
        'guest_unclaimed',
        'evidence_after_decision',
        'cap_exceeded',
        'unknown_source',
    ];

    public function __construct(
        private ?RewardSourceFactReader $sourceReader = null,
        private ?RewardPreviewEngine $policyEngine = null,
    ) {}

    public function handle(
        RewardProgram|string $program,
        RewardComponent|string $component,
        string $recipientType,
        string $recipientId,
        RewardBeneficiaryRole|string $beneficiaryRole,
        string $sourceBusinessKey,
        CarbonInterface $decisionTime,
        string $actorType = 'system',
        ?string $actorId = null,
        string $actorRole = 'system',
    ): RewardEvaluationResult {
        $componentEnum = $component instanceof RewardComponent ? $component : RewardComponent::tryFrom($component);

        if (! $componentEnum instanceof RewardComponent) {
            throw new InvalidArgumentException('Unknown reward component.');
        }

        $roleEnum = $beneficiaryRole instanceof RewardBeneficiaryRole
            ? $beneficiaryRole
            : RewardBeneficiaryRole::tryFrom($beneficiaryRole);

        if (! $roleEnum instanceof RewardBeneficiaryRole) {
            throw new InvalidArgumentException('Unknown beneficiary role.');
        }

        if (! in_array($recipientType, self::RECIPIENT_TYPES, true)) {
            throw new InvalidArgumentException('Recipient type must be one of ['.implode(',', self::RECIPIENT_TYPES).']; no unclaimed-guest rewards.');
        }

        if (! Str::isUuid($recipientId)) {
            throw new InvalidArgumentException('Recipient id must be a UUID.');
        }

        if ($sourceBusinessKey === '' || mb_strlen($sourceBusinessKey) > 191) {
            throw new InvalidArgumentException('Source business key must be 1..191 characters.');
        }

        RewardLedgerEntries::assertActor($actorType, $actorId, $actorRole);

        $decision = CarbonImmutable::parse($decisionTime)->setTimezone('UTC');

        if ($decision->greaterThan(CarbonImmutable::now('UTC')->addMinutes(5))) {
            throw new InvalidArgumentException('Decision time cannot be more than 5 minutes in the future; trusted server time only.');
        }

        $programId = $program instanceof RewardProgram ? (string) $program->getKey() : $program;
        $reader = $this->sourceReader ?? new NullRewardSourceFactReader;
        $firstRead = $reader->read($sourceBusinessKey);

        if (! $firstRead instanceof RewardSourceFact) {
            throw new InvalidArgumentException('Refused: unknown_source has no readable source fact.');
        }

        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return DB::transaction(fn (): RewardEvaluationResult => $this->attempt(
                    $programId, $componentEnum, $recipientType, $recipientId, $roleEnum,
                    $sourceBusinessKey, $decision, $firstRead, $reader,
                    $actorType, $actorId, $actorRole,
                ));
            } catch (QueryException $exception) {
                if ($attempt >= 3 || (! CreateRewardProgramAction::isUniqueViolation($exception) && ! self::isDeadlock($exception))) {
                    throw $exception;
                }

                $existing = DB::transaction(fn (): ?RewardAward => RewardAward::query()
                    ->where('recipient_type', $recipientType)
                    ->where('recipient_id', $recipientId)
                    ->where('source_business_key', $sourceBusinessKey)
                    ->where('family_key', $this->familyKeyFor($programId))
                    ->where('component', $componentEnum->value)
                    ->first());

                if ($existing instanceof RewardAward) {
                    return $this->duplicateResult($existing);
                }
            }
        }
    }

    private function attempt(
        string $programId,
        RewardComponent $component,
        string $recipientType,
        string $recipientId,
        RewardBeneficiaryRole $role,
        string $sourceBusinessKey,
        CarbonImmutable $decision,
        RewardSourceFact $firstRead,
        RewardSourceFactReader $reader,
        string $actorType,
        ?string $actorId,
        string $actorRole,
    ): RewardEvaluationResult {
        // Global-first lock: serializes every evaluation for this
        // source/component/role across programs, so cross-family claim checks
        // can never interleave. A concurrent coordinator creation bubbles as
        // a unique violation to the outer retry loop.
        $coordinator = RewardSourceCoordinator::query()
            ->where('source_business_key', $sourceBusinessKey)
            ->where('component', $component->value)
            ->where('beneficiary_role', $role->value)
            ->lockForUpdate()
            ->first();

        if (! $coordinator instanceof RewardSourceCoordinator) {
            RewardSourceCoordinator::query()->create([
                'source_business_key' => $sourceBusinessKey,
                'component' => $component,
                'beneficiary_role' => $role,
            ]);
        }

        $lockedProgram = RewardProgram::query()->whereKey($programId)->lockForUpdate()->first();

        if (! $lockedProgram instanceof RewardProgram) {
            throw new InvalidArgumentException('Unknown reward program.');
        }

        /** @var list<RewardProgramVersion> $versions */
        $versions = RewardProgramVersion::query()
            ->where('program_id', $lockedProgram->getKey())
            ->orderBy('version')
            ->get()
            ->all();

        $timeline = array_map(static fn (RewardProgramVersion $version): array => [
            'id' => (string) $version->getKey(),
            'version' => (int) $version->version,
            'effective_from' => CarbonImmutable::parse($version->effective_from)->setTimezone('UTC'),
        ], $versions);

        $applicable = RewardVersionResolver::resolveAt($timeline, $decision);
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
            throw new InvalidArgumentException('Refused: version_missing has no published version at the decision time.');
        }

        $rules = $version->rules;
        $components = [];

        foreach ((is_array($rules['components'] ?? null) ? $rules['components'] : []) as $entry) {
            if (is_array($entry) && is_string($entry['type'] ?? null)) {
                $components[] = $entry['type'];
            }
        }

        if (! in_array($component->value, $components, true)) {
            throw new InvalidArgumentException('Refused: component_not_enabled by the applicable version.');
        }

        if ($lockedProgram->status->value !== 'active') {
            throw new InvalidArgumentException('Refused: program_not_active stops new qualification; accrued obligations keep frozen terms.');
        }

        $claims = RewardSourceComponentClaim::query()
            ->where('source_business_key', $sourceBusinessKey)
            ->where('component', $component->value)
            ->where('beneficiary_role', $role->value)
            ->orderBy('family_key')
            ->lockForUpdate()
            ->get();

        $compatible = array_values(array_filter($version->compatible_with, is_string(...)));

        foreach ($claims as $claim) {
            if ($claim->family_key === $lockedProgram->family_key) {
                $claimedAward = RewardAward::query()->whereKey($claim->award_id)->first();

                if ($claimedAward instanceof RewardAward
                    && $claimedAward->recipient_type === $recipientType
                    && (string) $claimedAward->recipient_id === $recipientId
                ) {
                    return $this->duplicateResult($claimedAward);
                }

                return $this->recordRejection(
                    $lockedProgram, $version, $rules, $recipientType, $recipientId, $role,
                    $component, $sourceBusinessKey, $decision, $firstRead,
                    null, null, ['stacking_conflict'],
                    $actorType, $actorId, $actorRole,
                );
            }

            if (! in_array($claim->family_key, $compatible, true)
                || ! in_array($lockedProgram->family_key, $claim->compatible_snapshot, true)
            ) {
                return $this->recordRejection(
                    $lockedProgram, $version, $rules, $recipientType, $recipientId, $role,
                    $component, $sourceBusinessKey, $decision, $firstRead,
                    null, null, ['stacking_conflict'],
                    $actorType, $actorId, $actorRole,
                );
            }
        }

        $enrollment = RewardEnrollment::query()
            ->where('program_id', $lockedProgram->getKey())
            ->where('member_type', $recipientType)
            ->where('member_id', $recipientId)
            ->lockForUpdate()
            ->first();

        if (! $enrollment instanceof RewardEnrollment) {
            throw new InvalidArgumentException('Refused: enrollment_missing for this recipient.');
        }

        $activePeriod = RewardEnrollmentPeriod::query()
            ->where('enrollment_id', $enrollment->getKey())
            ->where('joined_at', '<=', $decision)
            ->where(function ($query) use ($decision): void {
                $query->whereNull('left_at')->orWhere('left_at', '>', $decision);
            })
            ->orderByDesc('joined_at')
            ->lockForUpdate()
            ->first();

        if (! $activePeriod instanceof RewardEnrollmentPeriod) {
            throw new InvalidArgumentException('Refused: enrollment_left stops new qualification; accrued obligations keep frozen terms.');
        }

        $relevantJoinedAt = CarbonImmutable::parse($activePeriod->joined_at)->setTimezone('UTC');

        $coveringAcceptance = RewardEnrollmentAcceptance::query()
            ->where('enrollment_id', $enrollment->getKey())
            ->where('accepted_at', '<=', $decision)
            ->where('material_terms_hash', (string) $version->material_terms_hash)
            ->orderByDesc('accepted_at')
            ->orderByDesc('seq')
            ->lockForUpdate()
            ->first();

        if (! $coveringAcceptance instanceof RewardEnrollmentAcceptance) {
            $hasPrior = RewardEnrollmentAcceptance::query()
                ->where('enrollment_id', $enrollment->getKey())
                ->where('accepted_at', '<=', $decision)
                ->exists();

            throw new InvalidArgumentException($hasPrior
                ? 'Refused: acceptance_stale_material_change requires prospective reacceptance first.'
                : 'Refused: acceptance_missing covers no terms at the decision time.');
        }

        $liveVerdict = $this->liveSourceVerdict($firstRead, $role, $recipientId);

        if ($liveVerdict['reject'] !== null) {
            return $this->recordRejection(
                $lockedProgram, $version, $rules, $recipientType, $recipientId, $role,
                $component, $sourceBusinessKey, $decision, $firstRead,
                $enrollment, $coveringAcceptance, [$liveVerdict['reject']],
                $actorType, $actorId, $actorRole,
            );
        }

        $claimedFamilies = $claims->map(fn (RewardSourceComponentClaim $claim): string => $claim->family_key)->all();

        $context = new RewardPreviewContext(
            programFamilyKey: $lockedProgram->family_key,
            programStatus: $lockedProgram->status,
            versionFound: true,
            versionId: (string) $version->getKey(),
            versionNumber: (int) $version->version,
            versionRules: $rules,
            versionComponents: $components,
            versionMaterialHash: (string) $version->material_terms_hash,
            versionEffectiveFrom: CarbonImmutable::parse($version->effective_from)->setTimezone('UTC'),
            compatibleWith: $compatible,
            enrollmentExists: true,
            enrollmentActiveAtDecision: true,
            acceptanceId: (string) $coveringAcceptance->getKey(),
            acceptanceMaterialHash: (string) $coveringAcceptance->material_terms_hash,
            acceptanceAcceptedAt: CarbonImmutable::parse($coveringAcceptance->accepted_at)->setTimezone('UTC'),
            hasPriorAcceptance: true,
            relevantPeriodJoinedAt: $relevantJoinedAt,
            fixture: new RewardPreviewFixture(
                sourceBusinessKey: $firstRead->sourceBusinessKey,
                outcome: $firstRead->outcome,
                category: $firstRead->category,
                occurredAt: $firstRead->occurredAt,
                verification: $firstRead->verification,
                isFirstTime: $firstRead->isFirstTime,
                actorId: $firstRead->actorId,
                sharerId: $firstRead->sharerId,
                isClaimedIdentity: $firstRead->isClaimedIdentity,
                isAttributed: $firstRead->isAttributed,
                isAmbiguousDevice: $firstRead->isAmbiguousDevice,
                claimedFamilyKeys: $claimedFamilies,
                isBot: $firstRead->isBot,
                isCancelled: $firstRead->isCancelled,
                isValidSource: $firstRead->isValid,
            ),
            requestedComponent: $component,
            decisionTime: $decision,
        );

        $policy = ($this->policyEngine ?? new RewardPreviewEngine)->evaluate($context);

        if ($policy->decision === RewardPreviewDecision::Ineligible) {
            $reason = $this->primaryReason($policy->reasons);

            if (in_array($reason, self::TRANSIENT_REASONS, true)) {
                throw new InvalidArgumentException("Refused: {$reason} is not yet authorized; no award row is frozen.");
            }

            return $this->recordRejection(
                $lockedProgram, $version, $rules, $recipientType, $recipientId, $role,
                $component, $sourceBusinessKey, $decision, $firstRead,
                $enrollment, $coveringAcceptance, [$reason],
                $actorType, $actorId, $actorRole,
            );
        }

        if ($liveVerdict['hold'] !== null) {
            return $this->recordHeld(
                $lockedProgram, $version, $rules, $enrollment, $coveringAcceptance,
                $recipientType, $recipientId, $role, $component, $sourceBusinessKey,
                $decision, $firstRead, $reader, [$liveVerdict['hold']], $actorType, $actorId, $actorRole,
            );
        }

        if ($policy->decision === RewardPreviewDecision::Hold) {
            return $this->recordHeld(
                $lockedProgram, $version, $rules, $enrollment, $coveringAcceptance,
                $recipientType, $recipientId, $role, $component, $sourceBusinessKey,
                $decision, $firstRead, $reader, ['hold_required'], $actorType, $actorId, $actorRole,
            );
        }

        if ($component->requiresMoneyOrBenefitFulfillment()) {
            return $this->recordHeld(
                $lockedProgram, $version, $rules, $enrollment, $coveringAcceptance,
                $recipientType, $recipientId, $role, $component, $sourceBusinessKey,
                $decision, $firstRead, $reader, ['financial_readiness_pending'],
                $actorType, $actorId, $actorRole,
                ['note' => 'Cash/benefit components record held-prerequisite candidates only; WP6 finance scope settles fulfillment.'],
            );
        }

        $this->consumeCaps($rules, $lockedProgram->family_key, (string) $lockedProgram->getKey(), $recipientType, $recipientId, $sourceBusinessKey, $component->value, $decision);

        $secondRead = $reader->read($sourceBusinessKey);

        if (! $secondRead instanceof RewardSourceFact || $secondRead->snapshot() !== $firstRead->snapshot()) {
            throw new InvalidArgumentException('Source fact changed during evaluation; retry with a fresh read.');
        }

        $award = $this->insertAward(
            $lockedProgram, $version, $rules, $enrollment, $coveringAcceptance,
            $recipientType, $recipientId, $role, $component, $sourceBusinessKey,
            $decision, $firstRead, RewardAwardStatus::Approved,
            $actorType, $actorId, $actorRole, ['ok'],
        );

        RewardSourceComponentClaim::query()->create([
            'source_business_key' => $sourceBusinessKey,
            'component' => $component,
            'beneficiary_role' => $role,
            'family_key' => $lockedProgram->family_key,
            'program_id' => $lockedProgram->getKey(),
            'award_id' => $award->getKey(),
            'compatible_snapshot' => $compatible,
            'claimed_at' => $decision,
        ]);

        return new RewardEvaluationResult(RewardEvaluationOutcome::Awarded, $award->refresh(), ['ok']);
    }

    /**
     * @return array{reject: ?string, hold: ?string}
     */
    private function liveSourceVerdict(RewardSourceFact $fact, RewardBeneficiaryRole $role, string $recipientId): array
    {
        if (! $fact->isValid) {
            return ['reject' => 'source_invalid', 'hold' => null];
        }

        if ($fact->isBot) {
            return ['reject' => 'source_bot_excluded', 'hold' => null];
        }

        if ($fact->isCancelled) {
            return ['reject' => 'source_cancelled', 'hold' => null];
        }

        if ($fact->correctionStatus->value === 'withdrawn') {
            return ['reject' => 'source_withdrawn', 'hold' => null];
        }

        if ($fact->fraudStatus->value === 'confirmed') {
            return ['reject' => 'fraud_confirmed', 'hold' => null];
        }

        if (! $this->beneficiaryBinds($fact, $role, $recipientId)) {
            return ['reject' => 'beneficiary_mismatch', 'hold' => null];
        }

        if ($fact->fraudStatus->value === 'suspect') {
            return ['reject' => null, 'hold' => 'fraud_suspect_held'];
        }

        return ['reject' => null, 'hold' => null];
    }

    private function beneficiaryBinds(RewardSourceFact $fact, RewardBeneficiaryRole $role, string $recipientId): bool
    {
        return match ($role) {
            RewardBeneficiaryRole::Recipient, RewardBeneficiaryRole::Sharer => $fact->sharerId !== null && $fact->sharerId === $recipientId,
            RewardBeneficiaryRole::Participant, RewardBeneficiaryRole::Purchaser => $fact->actorId !== null && $fact->actorId === $recipientId,
            RewardBeneficiaryRole::Staff => $fact->actorRole === RewardBeneficiaryRole::Staff && $fact->actorId !== null && $fact->actorId === $recipientId,
        };
    }

    /**
     * @param  list<RewardPreviewReason>  $reasons
     */
    private function primaryReason(array $reasons): string
    {
        foreach ($reasons as $reason) {
            if ($reason !== RewardPreviewReason::Ok && $reason !== RewardPreviewReason::LiveChecksDeferred) {
                return $reason->value;
            }
        }

        return RewardPreviewReason::Ok->value;
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    private function consumeCaps(
        array $rules,
        string $familyKey,
        string $programId,
        string $recipientType,
        string $recipientId,
        string $sourceBusinessKey,
        string $component,
        CarbonImmutable $decision,
    ): void {
        $caps = $rules['caps'] ?? [];

        if (! is_array($caps) || $caps === []) {
            return;
        }

        $recipientKey = RewardCapWindows::recipientKey($recipientType, $recipientId);
        $targets = [];

        foreach ($caps as $cap) {
            if (! is_array($cap) || ! is_string($cap['scope'] ?? null) || ! is_int($cap['limit'] ?? null)) {
                continue;
            }

            $keys = RewardCapWindows::keysFor(
                $cap['scope'], $familyKey, $programId, $recipientKey, $sourceBusinessKey, $component, $decision,
            );

            $targets[$keys['cap_key'].'|'.$keys['window_key']] = [
                'scope' => $cap['scope'],
                'limit' => $cap['limit'],
                'cap_key' => $keys['cap_key'],
                'window_key' => $keys['window_key'],
            ];
        }

        foreach (RewardLedgerLockOrder::sorted(array_keys($targets)) as $ordered) {
            $target = $targets[$ordered];

            $counter = RewardCapCounter::query()
                ->where('cap_key', $target['cap_key'])
                ->where('window_key', $target['window_key'])
                ->lockForUpdate()
                ->first();

            if (! $counter instanceof RewardCapCounter) {
                // A concurrent counter creation surfaces as a unique violation;
                // it bubbles to the outer retry loop (PostgreSQL-safe: the
                // catch sits outside the transaction, never inside it).
                $counter = RewardCapCounter::query()->create([
                    'cap_key' => $target['cap_key'],
                    'window_key' => $target['window_key'],
                    'family_key' => $target['scope'] === 'family_source_component' ? '*' : $familyKey,
                    'scope' => $target['scope'],
                    'limit_value' => $target['limit'],
                    'consumed' => 0,
                ]);
            }

            if ($counter->limit_value !== $target['limit']) {
                throw new InvalidArgumentException("Cap [{$target['scope']}] limit changed under a frozen window; refusing to overspend.");
            }

            if ($counter->consumed >= $counter->limit_value) {
                throw new InvalidArgumentException('Refused: cap_exceeded for scope ['.$target['scope'].']; no award row is frozen.');
            }

            $counter->forceFill(['consumed' => $counter->consumed + 1])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $rules
     * @param  list<string>  $reasons
     * @param  array<string, mixed>|null  $extraEvidence
     */
    private function recordHeld(
        RewardProgram $program,
        RewardProgramVersion $version,
        array $rules,
        RewardEnrollment $enrollment,
        RewardEnrollmentAcceptance $acceptance,
        string $recipientType,
        string $recipientId,
        RewardBeneficiaryRole $role,
        RewardComponent $component,
        string $sourceBusinessKey,
        CarbonImmutable $decision,
        RewardSourceFact $fact,
        RewardSourceFactReader $reader,
        array $reasons,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        ?array $extraEvidence = null,
    ): RewardEvaluationResult {
        $this->consumeCaps($rules, $program->family_key, (string) $program->getKey(), $recipientType, $recipientId, $sourceBusinessKey, $component->value, $decision);

        $secondRead = $reader->read($sourceBusinessKey);

        if (! $secondRead instanceof RewardSourceFact || $secondRead->snapshot() !== $fact->snapshot()) {
            throw new InvalidArgumentException('Source fact changed during evaluation; retry with a fresh read.');
        }

        $award = $this->insertAward(
            $program, $version, $rules, $enrollment, $acceptance,
            $recipientType, $recipientId, $role, $component, $sourceBusinessKey,
            $decision, $fact, RewardAwardStatus::Held,
            $actorType, $actorId, $actorRole, $reasons, $extraEvidence,
        );

        RewardSourceComponentClaim::query()->create([
            'source_business_key' => $sourceBusinessKey,
            'component' => $component,
            'beneficiary_role' => $role,
            'family_key' => $program->family_key,
            'program_id' => $program->getKey(),
            'award_id' => $award->getKey(),
            'compatible_snapshot' => array_values(array_filter($version->compatible_with, is_string(...))),
            'claimed_at' => $decision,
        ]);

        $this->obligateHeldCash($award, $program, $component, $decision, $actorType, $actorId, $actorRole);

        return new RewardEvaluationResult(RewardEvaluationOutcome::Held, $award->refresh(), $reasons);
    }

    /**
     * Reserves the earned cash obligation at the hold boundary: a held
     * award is a real debt against confirmed funding, so its value is
     * reserved here — not only at payout request — and the approval gate
     * stays aggregate-correct across concurrent awards. Earning never
     * fails for treasury shortfall: when confirmed funding does not
     * cover the obligation, the reserve still lands (available goes
     * negative, truthfully) and approval waits for funding.
     */
    private function obligateHeldCash(
        RewardAward $award,
        RewardProgram $program,
        RewardComponent $component,
        CarbonImmutable $decision,
        string $actorType,
        ?string $actorId,
        string $actorRole,
    ): void {
        if ($component !== RewardComponent::Cash) {
            return;
        }

        $amount = $award->amount_snapshot ?? [];
        $minor = (int) ($amount['minor_units'] ?? 0);
        $currency = (string) ($amount['currency'] ?? '');

        if ($minor <= 0 || $currency === '') {
            throw new InvalidArgumentException('Cash awards must freeze a positive minor-unit amount and currency.');
        }

        $currency = RewardBudgetLedger::normalizeCurrency($currency);
        $programId = (string) $program->getKey();

        RewardBudgetLedger::lockFunding($programId, $currency);

        RewardBudgetLedger::append(
            RewardBudgetMovementKind::Reserve,
            RewardBudgetLedger::obligateKey((string) $award->getKey(), 0),
            $programId, $currency, $minor, (string) $award->getKey(),
            $actorType, $actorId, $actorRole, $decision,
        );

        $award->forceFill(['obligation_epoch' => 0, 'obligated_minor' => $minor])->save();
    }

    /**
     * @param  array<string, mixed>  $rules
     * @param  list<string>  $reasons
     */
    private function recordRejection(
        RewardProgram $program,
        RewardProgramVersion $version,
        array $rules,
        string $recipientType,
        string $recipientId,
        RewardBeneficiaryRole $role,
        RewardComponent $component,
        string $sourceBusinessKey,
        CarbonImmutable $decision,
        RewardSourceFact $fact,
        ?RewardEnrollment $enrollment,
        ?RewardEnrollmentAcceptance $acceptance,
        array $reasons,
        string $actorType,
        ?string $actorId,
        string $actorRole,
    ): RewardEvaluationResult {
        $award = $this->insertAward(
            $program, $version, $rules, $enrollment, $acceptance,
            $recipientType, $recipientId, $role, $component, $sourceBusinessKey,
            $decision, $fact, RewardAwardStatus::Rejected,
            $actorType, $actorId, $actorRole, $reasons,
        );

        return new RewardEvaluationResult(RewardEvaluationOutcome::Rejected, $award->refresh(), $reasons);
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function snapshots(
        array $rules,
        RewardProgramVersion $version,
        RewardComponent $component,
        ?RewardEnrollmentAcceptance $acceptance,
        RewardSourceFact $fact,
    ): array {
        $componentConfig = ['type' => $component->value];

        foreach ((is_array($rules['components'] ?? null) ? $rules['components'] : []) as $entry) {
            if (is_array($entry) && ($entry['type'] ?? null) === $component->value) {
                $componentConfig = $entry;
                break;
            }
        }

        return [
            'amount' => $componentConfig,
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
            'consent' => $acceptance instanceof RewardEnrollmentAcceptance
                ? [
                    'acceptance_id' => (string) $acceptance->getKey(),
                    'accepted_at' => CarbonImmutable::parse($acceptance->accepted_at)->setTimezone('UTC')->toIso8601String(),
                    'material_terms_hash' => (string) $acceptance->material_terms_hash,
                    'terms_label' => (string) $acceptance->terms_label,
                ]
                : ['status' => 'unresolved'],
            'source' => $fact->snapshot(),
        ];
    }

    /**
     * @param  array<string, mixed>  $rules
     * @param  list<string>  $reasons
     * @param  array<string, mixed>|null  $extraEvidence
     */
    private function insertAward(
        RewardProgram $program,
        RewardProgramVersion $version,
        array $rules,
        ?RewardEnrollment $enrollment,
        ?RewardEnrollmentAcceptance $acceptance,
        string $recipientType,
        string $recipientId,
        RewardBeneficiaryRole $role,
        RewardComponent $component,
        string $sourceBusinessKey,
        CarbonImmutable $decision,
        RewardSourceFact $fact,
        RewardAwardStatus $status,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        array $reasons,
        ?array $extraEvidence = null,
    ): RewardAward {
        $snapshots = $this->snapshots($rules, $version, $component, $acceptance, $fact);

        $award = RewardAward::query()->create([
            'program_id' => $program->getKey(),
            'policy_version_id' => $version->getKey(),
            'enrollment_id' => $enrollment?->getKey(),
            'acceptance_id' => $acceptance?->getKey(),
            'recipient_type' => $recipientType,
            'recipient_id' => $recipientId,
            'beneficiary_role' => $role,
            'source_business_key' => $sourceBusinessKey,
            'family_key' => $program->family_key,
            'component' => $component,
            'status' => $status,
            'amount_snapshot' => $snapshots['amount'],
            'policy_snapshot' => $snapshots['policy'],
            'consent_snapshot' => $snapshots['consent'],
            'source_snapshot' => $snapshots['source'],
            $status->transitionColumn() => $decision,
        ]);

        RewardLedgerEntries::appendAwardEntry(
            $award, 'none', $status->value,
            $actorType, $actorId, $actorRole,
            $reasons[0] ?? 'evaluation-recorded',
            ['reasons' => $reasons, 'evaluation' => 'live', ...($extraEvidence ?? [])],
            null, $decision,
        );

        // Awards born approved plan the recipient's delayed digest in
        // the same atomic unit; held/rejected records thank nothing.
        // Planning is preference-gated — opted-out or non-user
        // recipients simply yield no slot, never a failed evaluation.
        if ($status === RewardAwardStatus::Approved) {
            try {
                PlanRewardGratitudeDigestAction::run($recipientType, $recipientId, ['awards'], $decision);
            } catch (InvalidArgumentException) {
                // No slot for this recipient; the award stands.
            }
        }

        return $award;
    }

    private function duplicateResult(RewardAward $existing): RewardEvaluationResult
    {
        $outcome = match ($existing->status) {
            RewardAwardStatus::Held, RewardAwardStatus::PendingReview => RewardEvaluationOutcome::Held,
            RewardAwardStatus::Rejected => RewardEvaluationOutcome::Rejected,
            default => RewardEvaluationOutcome::Awarded,
        };

        return new RewardEvaluationResult($outcome, $existing, ['duplicate_evaluation'], true);
    }

    private function familyKeyFor(string $programId): string
    {
        $program = RewardProgram::query()->whereKey($programId)->first();

        return $program instanceof RewardProgram ? $program->family_key : '';
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
