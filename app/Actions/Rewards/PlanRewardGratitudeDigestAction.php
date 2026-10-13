<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use AIArmada\Rewards\Enums\RewardAwardStatus;
use AIArmada\Rewards\Enums\RewardBadgeStatus;
use AIArmada\Rewards\Enums\RewardMilestoneStatus;
use AIArmada\Rewards\Models\RewardAward;
use AIArmada\Rewards\Models\RewardBadgeGrant;
use AIArmada\Rewards\Models\RewardMilestoneCycle;
use App\Enums\NotificationCadence;
use App\Enums\NotificationTrigger;
use App\Models\RewardDigestSlot;
use App\Models\User;
use App\Services\Notifications\NotificationSettingsManager;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Plan a calm delayed gratitude digest for one recipient.
 *
 * Eligibility is own-slot-aware and preference-gated: the trigger must
 * be enabled with a daily/weekly cadence. The slot is the shared
 * ceiling record — unique per recipient-local day across every digest
 * category and channel — so planning the same day twice merges the new
 * categories into the stored slot (extending its window and recomputing
 * the combined payload) and returns it: one daily send covers every
 * planned section. Terminal or mid-send slots return untouched.
 *
 * Categories are a fixed set, each backed by a real committed-state
 * section: awards (approvals/fulfillments in the window, corrected
 * awards excluded by status), badges (live grants), milestones (live
 * achievements). The slot lands DELAYED — tomorrow at the user's
 * digest delivery time for daily cadence, the next weekly day for
 * weekly — never immediate, never per-visit. Dispatch recomputes and
 * cancels/recalculates when corrections moved the material.
 */
final readonly class PlanRewardGratitudeDigestAction
{
    use AsAction;

    public const array CATEGORIES = ['awards', 'badges', 'milestones'];

    /**
     * @param  list<string>  $categories
     */
    public function handle(
        string $recipientType,
        string $recipientId,
        array $categories = ['awards'],
        ?CarbonInterface $decidedAt = null,
    ): RewardDigestSlot {
        if ($recipientType !== 'user') {
            throw new InvalidArgumentException('Gratitude digests address user recipients only.');
        }

        $merged = array_values(array_unique($categories));

        if ($merged === [] || count($merged) > 3) {
            throw new InvalidArgumentException('Digest categories must be an explicit list of 1..3 entries.');
        }

        foreach ($merged as $category) {
            if (! in_array($category, self::CATEGORIES, true)) {
                throw new InvalidArgumentException('Unknown digest category ['.$category.']; expected awards, badges, or milestones.');
            }
        }

        $decided = $decidedAt === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($decidedAt)->setTimezone('UTC');

        $user = User::query()->whereKey($recipientId)->first();

        if (! $user instanceof User) {
            throw new InvalidArgumentException('Unknown digest recipient.');
        }

        $policy = app(NotificationSettingsManager::class)->resolvePolicy($user, NotificationTrigger::ShareGratitudeDigest);

        if (! $policy->enabled) {
            throw new InvalidArgumentException('Gratitude digests require the recipient to keep the trigger enabled.');
        }

        if (! in_array($policy->cadence, [NotificationCadence::Daily, NotificationCadence::Weekly], true)) {
            throw new InvalidArgumentException('Gratitude digests require a daily or weekly cadence.');
        }

        $tz = new \DateTimeZone($policy->timezone);
        $dueLocal = $policy->cadence === NotificationCadence::Weekly
            ? $decided->setTimezone($tz)->next($policy->digestWeeklyDay)
            : $decided->setTimezone($tz)->addDay();

        [$hour, $minute, $second] = array_map(
            static fn (string $part): int => (int) $part,
            explode(':', ($policy->digestDeliveryTime ?? '08:00:00').':00:00'),
        );

        $dueAt = $dueLocal->setTime($hour, $minute, min($second, 59))->setTimezone('UTC');

        // Calmness floor: gratitude never lands sooner than 20 hours out.
        if ($dueAt->lessThan($decided->addHours(20))) {
            $dueAt = $decided->addHours(20);
        }

        $localDay = $dueAt->setTimezone($tz)->toDateString();
        $planKey = "gratitude:{$recipientId}:{$localDay}";

        if (mb_strlen($planKey) > 191) {
            throw new InvalidArgumentException('Digest plan identity exceeds 191 characters.');
        }

        $windowStart = $decided->subDays($policy->cadence === NotificationCadence::Weekly ? 7 : 1);

        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return DB::transaction(fn (): RewardDigestSlot => $this->attempt(
                    $recipientType, $recipientId, $localDay, $planKey, $merged,
                    $policy->timezone, $dueAt, $decided, $windowStart,
                ));
            } catch (QueryException $exception) {
                if (self::isDeadlock($exception) && $attempt < 3) {
                    continue;
                }

                // A concurrent plan won the slot insert; merge into it
                // instead of duplicating the day. Every catch sits outside
                // the transaction, PostgreSQL-safe.
                if (! CreateRewardProgramAction::isUniqueViolation($exception)) {
                    throw $exception;
                }

                $winner = RewardDigestSlot::query()
                    ->where('recipient_type', $recipientType)
                    ->where('recipient_id', $recipientId)
                    ->where('local_day', $localDay)
                    ->first();

                if (! $winner instanceof RewardDigestSlot) {
                    throw $exception;
                }

                return DB::transaction(function () use ($winner, $merged, $decided): RewardDigestSlot {
                    $locked = RewardDigestSlot::query()->whereKey($winner->getKey())->lockForUpdate()->firstOrFail();

                    return self::mergeInto($locked, $merged, $decided);
                });
            }
        }
    }

    /**
     * @param  list<string>  $categories
     */
    private function attempt(
        string $recipientType,
        string $recipientId,
        string $localDay,
        string $planKey,
        array $categories,
        string $timezone,
        CarbonImmutable $dueAt,
        CarbonImmutable $decided,
        CarbonImmutable $windowStart,
    ): RewardDigestSlot {
        $existing = RewardDigestSlot::query()
            ->where('recipient_type', $recipientType)
            ->where('recipient_id', $recipientId)
            ->where('local_day', $localDay)
            ->lockForUpdate()
            ->first();

        if ($existing instanceof RewardDigestSlot) {
            return self::mergeInto($existing, $categories, $decided);
        }

        return RewardDigestSlot::query()->create([
            'recipient_type' => $recipientType,
            'recipient_id' => $recipientId,
            'local_day' => $localDay,
            'plan_key' => $planKey,
            'status' => RewardDigestSlot::STATUS_PLANNED,
            'categories' => $categories,
            'payload' => self::digestPayload($recipientId, $categories, $windowStart, $decided),
            'timezone' => $timezone,
            'due_at' => $dueAt,
        ]);
    }

    /**
     * Own-slot-aware merge: planned slots absorb new categories, extend
     * their window to the latest event, and recompute the combined
     * payload — the single daily send covers every planned section.
     * Mid-send and terminal slots return untouched: their content is
     * already fixed or finished, never silently rewritten.
     *
     * @param  list<string>  $categories
     */
    private static function mergeInto(RewardDigestSlot $slot, array $categories, CarbonImmutable $decided): RewardDigestSlot
    {
        if ((string) $slot->status !== RewardDigestSlot::STATUS_PLANNED) {
            return $slot;
        }

        $merged = array_values(array_unique([...(array) ($slot->categories ?? []), ...$categories]));

        $window = (array) (($slot->payload ?? [])['window'] ?? []);
        $from = isset($window['from']) ? CarbonImmutable::parse($window['from'])->setTimezone('UTC') : $decided->subDay();
        $to = isset($window['to']) ? CarbonImmutable::parse($window['to'])->setTimezone('UTC') : $decided;

        if ($decided->greaterThan($to)) {
            $to = $decided;
        }

        $payload = self::digestPayload((string) $slot->recipient_id, $merged, $from, $to);
        $delivery = ($slot->payload ?? [])['delivery'] ?? null;

        if ($delivery !== null) {
            $payload['delivery'] = $delivery;
        }

        $slot->forceFill([
            'categories' => $merged,
            'payload' => $payload,
        ])->save();

        return $slot->refresh();
    }

    /**
     * Combined payload over the slot's real categories. Every section
     * reads committed state through real lifecycle columns — awards by
     * approval/fulfillment time with corrected awards excluded by
     * status, badges by live grants, milestones by live achievements —
     * so corrections naturally drop out on recompute.
     *
     * @param  list<string>  $categories
     * @return array<string, mixed>
     */
    public static function digestPayload(string $recipientId, array $categories, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $sections = [];
        $total = 0;

        if (in_array('awards', $categories, true)) {
            $byComponent = RewardAward::query()
                ->where('recipient_type', 'user')
                ->where('recipient_id', $recipientId)
                ->whereIn('status', [RewardAwardStatus::Approved->value, RewardAwardStatus::Fulfilled->value])
                ->where(static function ($query) use ($from, $to): void {
                    $query->whereBetween('approved_at', [$from, $to])
                        ->orWhereBetween('fulfilled_at', [$from, $to]);
                })
                ->selectRaw('component, COUNT(*) as total')
                ->groupBy('component')
                ->pluck('total', 'component')
                ->map(static fn (mixed $count): int => (int) $count)
                ->all();

            $sections['awards'] = ['total' => array_sum($byComponent), 'by_component' => $byComponent];
            $total += $sections['awards']['total'];
        }

        if (in_array('badges', $categories, true)) {
            $byKey = RewardBadgeGrant::query()
                ->where('recipient_type', 'user')
                ->where('recipient_id', $recipientId)
                ->where('status', RewardBadgeStatus::Granted->value)
                ->whereNull('revoked_at')
                ->whereBetween('granted_at', [$from, $to])
                ->selectRaw('badge_key, COUNT(*) as total')
                ->groupBy('badge_key')
                ->pluck('total', 'badge_key')
                ->map(static fn (mixed $count): int => (int) $count)
                ->all();

            $sections['badges'] = ['total' => array_sum($byKey), 'by_key' => $byKey];
            $total += $sections['badges']['total'];
        }

        if (in_array('milestones', $categories, true)) {
            $byKey = RewardMilestoneCycle::query()
                ->where('recipient_type', 'user')
                ->where('recipient_id', $recipientId)
                ->where('status', RewardMilestoneStatus::Achieved->value)
                ->whereNull('invalidated_at')
                ->whereBetween('achieved_at', [$from, $to])
                ->selectRaw('milestone_key, COUNT(*) as total')
                ->groupBy('milestone_key')
                ->pluck('total', 'milestone_key')
                ->map(static fn (mixed $count): int => (int) $count)
                ->all();

            $sections['milestones'] = ['total' => array_sum($byKey), 'by_key' => $byKey];
            $total += $sections['milestones']['total'];
        }

        $parts = [];

        foreach (['awards' => 'award', 'badges' => 'badge', 'milestones' => 'milestone'] as $key => $noun) {
            $count = (int) ($sections[$key]['total'] ?? 0);

            if ($count > 0) {
                $parts[] = "{$count} recent {$noun}".($count === 1 ? '' : 's');
            }
        }

        return [
            'window' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'categories' => array_values($categories),
            'sections' => $sections,
            'counts' => $sections['awards']['by_component'] ?? [],
            'total' => $total,
            'text' => $parts === []
                ? 'Thank you for being part of the community.'
                : 'You earned recognition across '.implode(', ', $parts).'.',
        ];
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
