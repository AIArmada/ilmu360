<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Contracts\Rewards\RewardPreviewEvaluator;
use App\Enums\Rewards\RewardComponent;
use App\Enums\Rewards\RewardEligibleOutcome;
use App\Enums\Rewards\RewardVerificationLevel;
use App\Models\RewardEnrollment;
use App\Models\RewardEnrollmentAcceptance;
use App\Models\RewardEnrollmentPeriod;
use App\Models\RewardProgram;
use App\Models\RewardProgramVersion;
use App\Support\Rewards\RewardPreviewContext;
use App\Support\Rewards\RewardPreviewEngine;
use App\Support\Rewards\RewardPreviewFixture;
use App\Support\Rewards\RewardPreviewResult;
use App\Support\Rewards\RewardVersionResolver;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Point-in-time qualification preview over explicit snapshots.
 *
 * Read-only: loads the program, its effective timeline, and the enrollment
 * snapshot, then runs the pure engine. Supplied fixture evidence is labeled
 * fixture-only and never treated as source integration. Live evaluation must
 * re-resolve under locks and enforce award uniqueness plus cap counters.
 */
final readonly class PreviewRewardQualificationAction
{
    use AsAction;

    public function __construct(
        private ?RewardPreviewEvaluator $evaluator = null,
    ) {}

    /**
     * @param  array<string, mixed>|RewardPreviewFixture  $fixture
     * @param  array{member_type: string, member_id: string}|null  $member
     */
    public function handle(
        RewardProgram|string $program,
        RewardComponent|string $requestedComponent,
        array|RewardPreviewFixture $fixture,
        CarbonInterface $decisionTime,
        ?array $member = null,
    ): RewardPreviewResult {
        $component = $requestedComponent instanceof RewardComponent
            ? $requestedComponent
            : RewardComponent::tryFrom($requestedComponent);

        if (! $component instanceof RewardComponent) {
            throw new InvalidArgumentException('Unknown requested component.');
        }

        $facts = $fixture instanceof RewardPreviewFixture ? $fixture : $this->fixtureFromArray($fixture);
        $decision = CarbonImmutable::parse($decisionTime)->setTimezone('UTC');

        $programId = $program instanceof RewardProgram ? (string) $program->getKey() : $program;
        $loaded = RewardProgram::query()->whereKey($programId)->first();

        if (! $loaded instanceof RewardProgram) {
            throw new InvalidArgumentException('Unknown reward program.');
        }

        /** @var list<RewardProgramVersion> $versions */
        $versions = RewardProgramVersion::query()
            ->where('program_id', $loaded->getKey())
            ->orderBy('version')
            ->get()
            ->all();

        $timeline = array_map(static fn (RewardProgramVersion $version): array => [
            'id' => (string) $version->getKey(),
            'version' => (int) $version->version,
            'effective_from' => CarbonImmutable::parse($version->effective_from)->setTimezone('UTC'),
        ], $versions);

        $applicable = RewardVersionResolver::resolveAt($timeline, $decision);
        $applicableVersion = null;

        if ($applicable !== null) {
            foreach ($versions as $version) {
                if ((string) $version->getKey() === $applicable['id']) {
                    $applicableVersion = $version;
                    break;
                }
            }
        }

        $rules = $applicableVersion instanceof RewardProgramVersion
            ? $applicableVersion->rules
            : [];

        $components = [];
        $rawComponents = $rules['components'] ?? [];

        if (is_array($rawComponents)) {
            foreach ($rawComponents as $entry) {
                if (is_array($entry) && is_string($entry['type'] ?? null)) {
                    $components[] = $entry['type'];
                }
            }
        }

        $compatible = $applicableVersion instanceof RewardProgramVersion
            ? array_values(array_filter($applicableVersion->compatible_with, is_string(...)))
            : [];

        $enrollment = null;

        if ($member !== null) {
            if (! is_string($member['member_type'] ?? null) || ! is_string($member['member_id'] ?? null)) {
                throw new InvalidArgumentException('Member must carry member_type and member_id strings.');
            }

            $enrollment = RewardEnrollment::query()
                ->where('program_id', $loaded->getKey())
                ->where('member_type', $member['member_type'])
                ->where('member_id', $member['member_id'])
                ->first();
        }

        $activeAtDecision = false;
        $relevantPeriodJoinedAt = null;
        $coveringAcceptance = null;
        $hasPriorAcceptance = false;

        if ($enrollment instanceof RewardEnrollment) {
            $activePeriod = RewardEnrollmentPeriod::query()
                ->where('enrollment_id', $enrollment->getKey())
                ->where('joined_at', '<=', $decision)
                ->where(function ($query) use ($decision): void {
                    $query->whereNull('left_at')->orWhere('left_at', '>', $decision);
                })
                ->orderByDesc('joined_at')
                ->first();

            $activeAtDecision = $activePeriod instanceof RewardEnrollmentPeriod;

            if ($activePeriod instanceof RewardEnrollmentPeriod) {
                $relevantPeriodJoinedAt = CarbonImmutable::parse($activePeriod->joined_at)->setTimezone('UTC');
            } else {
                $latestBefore = RewardEnrollmentPeriod::query()
                    ->where('enrollment_id', $enrollment->getKey())
                    ->where('joined_at', '<=', $decision)
                    ->orderByDesc('joined_at')
                    ->first();

                $relevantPeriodJoinedAt = $latestBefore instanceof RewardEnrollmentPeriod
                    ? CarbonImmutable::parse($latestBefore->joined_at)->setTimezone('UTC')
                    : CarbonImmutable::parse($enrollment->enrolled_at)->setTimezone('UTC');
            }

            $hasPriorAcceptance = RewardEnrollmentAcceptance::query()
                ->where('enrollment_id', $enrollment->getKey())
                ->where('accepted_at', '<=', $decision)
                ->exists();

            if ($applicableVersion instanceof RewardProgramVersion) {
                $coveringAcceptance = RewardEnrollmentAcceptance::query()
                    ->where('enrollment_id', $enrollment->getKey())
                    ->where('accepted_at', '<=', $decision)
                    ->where('material_terms_hash', (string) $applicableVersion->material_terms_hash)
                    ->orderByDesc('accepted_at')
                    ->orderByDesc('seq')
                    ->first();
            }
        }

        $context = new RewardPreviewContext(
            programFamilyKey: $loaded->family_key,
            programStatus: $loaded->status,
            versionFound: $applicableVersion instanceof RewardProgramVersion,
            versionId: $applicableVersion instanceof RewardProgramVersion ? (string) $applicableVersion->getKey() : null,
            versionNumber: $applicableVersion instanceof RewardProgramVersion ? (int) $applicableVersion->version : null,
            versionRules: $rules,
            versionComponents: $components,
            versionMaterialHash: $applicableVersion instanceof RewardProgramVersion ? (string) $applicableVersion->material_terms_hash : '',
            versionEffectiveFrom: $applicableVersion instanceof RewardProgramVersion ? CarbonImmutable::parse($applicableVersion->effective_from)->setTimezone('UTC') : null,
            compatibleWith: $compatible,
            enrollmentExists: $enrollment instanceof RewardEnrollment,
            enrollmentActiveAtDecision: $activeAtDecision,
            acceptanceId: $coveringAcceptance instanceof RewardEnrollmentAcceptance ? (string) $coveringAcceptance->getKey() : null,
            acceptanceMaterialHash: $coveringAcceptance instanceof RewardEnrollmentAcceptance ? (string) $coveringAcceptance->material_terms_hash : null,
            acceptanceAcceptedAt: $coveringAcceptance instanceof RewardEnrollmentAcceptance ? CarbonImmutable::parse($coveringAcceptance->accepted_at)->setTimezone('UTC') : null,
            hasPriorAcceptance: $hasPriorAcceptance,
            relevantPeriodJoinedAt: $relevantPeriodJoinedAt,
            fixture: $facts,
            requestedComponent: $component,
            decisionTime: $decision,
        );

        return ($this->evaluator ?? new RewardPreviewEngine)->evaluate($context);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function fixtureFromArray(array $input): RewardPreviewFixture
    {
        foreach (array_keys($input) as $key) {
            if (! in_array($key, [
                'source_business_key', 'outcome', 'category', 'occurred_at', 'verification',
                'is_first_time', 'actor_id', 'sharer_id', 'is_claimed_identity', 'is_attributed',
                'is_ambiguous_device', 'claimed_family_keys', 'is_bot', 'is_cancelled', 'is_valid_source',
            ], true)) {
                throw new InvalidArgumentException("Unknown fixture key [{$key}].");
            }
        }

        $businessKey = $input['source_business_key'] ?? null;

        if (! is_string($businessKey) || $businessKey === '' || mb_strlen($businessKey) > 191) {
            throw new InvalidArgumentException('Fixture source_business_key must be 1..191 characters.');
        }

        $outcome = RewardEligibleOutcome::tryFrom((string) ($input['outcome'] ?? ''));

        if (! $outcome instanceof RewardEligibleOutcome) {
            throw new InvalidArgumentException('Fixture outcome must be allowlisted.');
        }

        $category = $input['category'] ?? null;

        if ($category !== null && (! is_string($category) || preg_match('/^[a-z0-9][a-z0-9_\-]{0,31}$/', $category) !== 1)) {
            throw new InvalidArgumentException('Fixture category must be a 1..32 slug or null.');
        }

        $rawOccurredAt = $input['occurred_at'] ?? null;

        if (! is_string($rawOccurredAt)
            || preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?$/', $rawOccurredAt) !== 1
        ) {
            throw new InvalidArgumentException('Fixture occurred_at must be an explicit absolute timestamp (YYYY-MM-DDTHH:MM...); missing, empty, relative, and malformed dates are rejected without guessing.');
        }

        try {
            $occurredAt = CarbonImmutable::parse($rawOccurredAt, 'UTC')->setTimezone('UTC');
        } catch (\Throwable) {
            throw new InvalidArgumentException('Fixture occurred_at must be a valid absolute timestamp.');
        }

        $verification = RewardVerificationLevel::tryFrom((string) ($input['verification'] ?? ''));

        if (! $verification instanceof RewardVerificationLevel) {
            throw new InvalidArgumentException('Fixture verification must be a known level.');
        }

        foreach (['is_first_time', 'is_claimed_identity', 'is_attributed'] as $flag) {
            if (! is_bool($input[$flag] ?? null)) {
                throw new InvalidArgumentException("Fixture [{$flag}] must be boolean.");
            }
        }

        foreach (['actor_id', 'sharer_id'] as $idField) {
            $value = $input[$idField] ?? null;

            if ($value !== null && (! is_string($value) || $value === '' || mb_strlen($value) > 64)) {
                throw new InvalidArgumentException("Fixture [{$idField}] must be null or 1..64 characters.");
            }
        }

        if (array_key_exists('is_ambiguous_device', $input) && ! is_bool($input['is_ambiguous_device'])) {
            throw new InvalidArgumentException('Fixture is_ambiguous_device must be boolean.');
        }

        foreach (['is_bot', 'is_cancelled', 'is_valid_source'] as $validityFlag) {
            if (array_key_exists($validityFlag, $input) && ! is_bool($input[$validityFlag])) {
                throw new InvalidArgumentException("Fixture [{$validityFlag}] must be boolean.");
            }
        }

        $claimed = $input['claimed_family_keys'] ?? [];

        if (! is_array($claimed) || ($claimed !== [] && ! array_is_list($claimed))) {
            throw new InvalidArgumentException('Fixture claimed_family_keys must be a list.');
        }

        foreach ($claimed as $family) {
            if (! is_string($family) || preg_match('/^[a-z0-9][a-z0-9_\-]{0,63}$/', $family) !== 1) {
                throw new InvalidArgumentException('Fixture claimed_family_keys must be family slugs.');
            }
        }

        return new RewardPreviewFixture(
            sourceBusinessKey: $businessKey,
            outcome: $outcome,
            category: $category,
            occurredAt: $occurredAt,
            verification: $verification,
            isFirstTime: $input['is_first_time'],
            actorId: $input['actor_id'] ?? null,
            sharerId: $input['sharer_id'] ?? null,
            isClaimedIdentity: $input['is_claimed_identity'],
            isAttributed: $input['is_attributed'],
            isAmbiguousDevice: (bool) ($input['is_ambiguous_device'] ?? false),
            claimedFamilyKeys: $claimed,
            isBot: (bool) ($input['is_bot'] ?? false),
            isCancelled: (bool) ($input['is_cancelled'] ?? false),
            isValidSource: (bool) ($input['is_valid_source'] ?? true),
        );
    }
}
