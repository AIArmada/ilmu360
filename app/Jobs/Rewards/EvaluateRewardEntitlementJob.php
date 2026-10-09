<?php

declare(strict_types=1);

namespace App\Jobs\Rewards;

use App\Actions\Rewards\EvaluateRewardEntitlementAction;
use App\Actions\Rewards\RecordRewardMilestoneContributionAction;
use App\Contracts\Rewards\RewardSourceFactReader;
use App\Enums\Rewards\RewardComponent;
use App\Enums\Rewards\RewardEvaluationOutcome;
use App\Support\Rewards\RewardEvaluationCandidates;
use App\Support\Rewards\RewardSourceFact;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use InvalidArgumentException;

/**
 * Evaluates one committed source fact against every active program.
 *
 * Dispatched after commit by the share receipt/correction/claim writers.
 * Unique-until-processing per business key collapses duplicate dispatches
 * while still allowing genuine re-evaluation after claim or correction.
 * Transient kernel refusals (`Refused: ...` — no enrollment, unclaimed
 * guest, paused program, cap race losers) are skipped per candidate; any
 * other failure throws and retries. Awarded facts additionally record
 * milestone contributions declared by the applicable version.
 */
final class EvaluateRewardEntitlementJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $businessKey,
    ) {}

    public function uniqueId(): string
    {
        return 'reward-eval:'.$this->businessKey;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(RewardSourceFactReader $reader, EvaluateRewardEntitlementAction $evaluate): void
    {
        $fact = $reader->read($this->businessKey);

        if (! $fact instanceof RewardSourceFact) {
            return;
        }

        $now = CarbonImmutable::now('UTC');

        foreach (RewardEvaluationCandidates::for($fact, $now) as $candidate) {
            try {
                $result = $evaluate->handle(
                    $candidate['program'],
                    $candidate['component'],
                    'user',
                    $candidate['recipient_id'],
                    $candidate['role'],
                    $this->businessKey,
                    $now,
                    'system',
                    null,
                    'system',
                );
            } catch (InvalidArgumentException $exception) {
                if (str_starts_with($exception->getMessage(), 'Refused:')) {
                    continue;
                }

                throw $exception;
            }

            if ($result->outcome === RewardEvaluationOutcome::Awarded) {
                $this->recordMilestones($fact, $candidate['version']->rules, $candidate['recipient_id'], $candidate['role']->value, (string) $candidate['program']->getKey(), $candidate['program']->family_key);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    private function recordMilestones(
        RewardSourceFact $fact,
        mixed $rules,
        string $recipientId,
        string $role,
        string $programId,
        string $familyKey,
    ): void {
        $milestones = is_array($rules) && is_array($rules['milestones'] ?? null) ? $rules['milestones'] : [];

        foreach ($milestones as $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $component = RewardComponent::tryFrom((string) ($definition['reward']['type'] ?? ''));
            $key = $definition['key'] ?? null;
            $target = $definition['target_count'] ?? null;
            $window = $definition['window_days'] ?? null;

            if (! $component instanceof RewardComponent || ! is_string($key) || ! is_int($target) || ! is_int($window)) {
                continue;
            }

            try {
                RecordRewardMilestoneContributionAction::run(
                    'user',
                    $recipientId,
                    $familyKey,
                    $key,
                    $target,
                    $window,
                    $component,
                    is_array($definition['reward']) ? $definition['reward'] : [],
                    $this->businessKey,
                    'user',
                    $recipientId,
                    $role,
                    $fact->outcome,
                    $fact->occurredAt,
                    $programId,
                    $fact->snapshot(),
                );
            } catch (InvalidArgumentException $exception) {
                // The fact became unreadable mid-flight; validation errors
                // below indicate version-data bugs and must throw instead.
                if (str_starts_with($exception->getMessage(), 'Refused: unknown_source')) {
                    continue;
                }

                throw $exception;
            }
        }
    }
}
