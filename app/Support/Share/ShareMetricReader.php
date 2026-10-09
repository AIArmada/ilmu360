<?php

declare(strict_types=1);

namespace App\Support\Share;

use AIArmada\Affiliates\Models\AffiliateAttribution;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateLink;
use AIArmada\Affiliates\Models\AffiliateTouchpoint;
use App\Models\ShareSourceAttributionReceipt;
use App\Models\User;
use App\Support\ShareTracking\ShareTrackingMeasurement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Bounded aggregate reader over committed share facts.
 *
 * Every count comes from a frozen authority, never a mutable pointer:
 *
 * - recorded shares: committed outbound_share touchpoints;
 * - browsers: the historical union of frozen visitor_key values over
 *   landing attributions and visit touchpoints — a browser's later
 *   landing can never rewrite an earlier count;
 * - outcomes: conversions in member/global affiliate scope, split into
 *   current-valid versus historical (reversed/rejected) versus unknown
 *   (pending/unrecognized) categories;
 * - unattributed outcomes: frozen receipts with no attribution, reported
 *   only in global scope because they belong to no sharer.
 *
 * Member scope counts the sharer's primary member profile plus every
 * guest profile they actively claimed (ShareProfileScope). Global scope
 * counts every marked share profile and never commercial facts. Until
 * the operator declares a canonical reporting start, measurements are
 * withheld as not_configured. Periods are viewer-local: day buckets are
 * resolved per viewer-local day in UTC bounds, so a timezone boundary
 * never misplaces a fact. Reads are database aggregates with explicit
 * bounds — never lifetime collections. No actor ids, raw URLs, or
 * precise sensitive counterpart activity ever leave this reader. The
 * caller proves authorization; null (global) scope is staff-only.
 */
final class ShareMetricReader
{
    /**
     * Stored conversion statuses are short state names (pending,
     * qualified, approved, rejected, reversed, paid).
     *
     * @var list<string>
     */
    public const array VALID_STATUSES = ['qualified', 'approved', 'paid'];

    /**
     * @param  array<int, string>  $outcomes  Optional conversion_type filter; scopes by_outcome/by_category only.
     * @return array{
     *   status: string,
     *   definition_version: string,
     *   reporting_start: ?string,
     *   scope: string,
     *   period_start: string,
     *   period_end: string,
     *   timezone: string,
     *   freshness: array{newest_fact_at: ?string, read_at: string},
     *   totals: array{submitted_shares: int, recorded_shares: int, recorded_visits: int, distinct_sharing_browsers: int, share_intensity: float, accepted_participations: int, verified_participations: int},
     *   by_outcome: array<string, int>,
     *   by_category: array{valid: int, pending_unknown: int, reversed: int, rejected: int, unattributed_observed: ?int},
     *   by_day: array<string, int>,
     * }
     */
    public function summarize(
        string $timezone,
        CarbonImmutable $fromLocal,
        CarbonImmutable $toLocal,
        User|string|null $sharer = null,
        array $outcomes = [],
        int $maxDays = 92,
        bool $withDaily = true,
    ): array {
        try {
            $tz = new \DateTimeZone($timezone);
        } catch (\Throwable) {
            throw new InvalidArgumentException('Viewer timezone must be a valid identifier.');
        }

        $fromUtc = $fromLocal->setTimezone($tz)->startOfDay()->setTimezone('UTC');
        $toUtc = $toLocal->setTimezone($tz)->endOfDay()->setTimezone('UTC');

        if (! $toUtc->greaterThan($fromUtc)) {
            throw new InvalidArgumentException('Period end must be after period start.');
        }

        if ($fromUtc->diffInDays($toUtc) > $maxDays) {
            throw new InvalidArgumentException("Period exceeds the {$maxDays}-day bound.");
        }

        $definitionVersion = ShareTrackingMeasurement::definitionVersion();
        $reportingStart = ShareTrackingMeasurement::reportingStart();
        $startedAt = $reportingStart === null ? null : CarbonImmutable::parse($reportingStart, 'UTC');

        if ($startedAt === null) {
            return $this->blank($definitionVersion, null, $sharer === null ? 'global' : 'member', $fromUtc, $toUtc, $tz->getName());
        }

        $windowStart = $fromUtc->greaterThan($startedAt) ? $fromUtc : $startedAt;
        $windowEnd = $toUtc;

        $affiliateIds = $sharer === null
            ? ShareProfileScope::markedAffiliateIds()
            : $this->memberAffiliateIds($sharer);

        if ($affiliateIds === [] || $windowStart->greaterThan($windowEnd)) {
            return $this->blank($definitionVersion, $startedAt, $sharer === null ? 'global' : 'member', $windowStart, $windowEnd, $tz->getName());
        }

        $touchpointTable = (new AffiliateTouchpoint)->getTable();
        $attributionTable = (new AffiliateAttribution)->getTable();
        $conversionTable = (new AffiliateConversion)->getTable();
        $linkTable = (new AffiliateLink)->getTable();

        $submitted = DB::table($linkTable)
            ->whereIn('affiliate_id', $affiliateIds)
            ->where('created_at', '>=', $windowStart)
            ->where('created_at', '<=', $windowEnd)
            ->count();

        $recorded = DB::table($touchpointTable)
            ->whereIn('affiliate_id', $affiliateIds)
            ->where('touchpoint_type', 'outbound_share')
            ->where('touched_at', '>=', $windowStart)
            ->where('touched_at', '<=', $windowEnd)
            ->count();

        $visits = DB::table($touchpointTable)
            ->whereIn('affiliate_id', $affiliateIds)
            ->where('touchpoint_type', 'visit')
            ->where('touched_at', '>=', $windowStart)
            ->where('touched_at', '<=', $windowEnd)
            ->count();

        $landingKeys = DB::table($attributionTable)
            ->select('visitor_key')
            ->whereIn('affiliate_id', $affiliateIds)
            ->where('attribution_type', 'landing')
            ->where('first_seen_at', '>=', $windowStart)
            ->where('first_seen_at', '<=', $windowEnd)
            ->whereNotNull('visitor_key')
            ->where('visitor_key', '!=', '');

        $visitKeys = DB::table($touchpointTable)
            ->select('visitor_key')
            ->whereIn('affiliate_id', $affiliateIds)
            ->where('touchpoint_type', 'visit')
            ->where('touched_at', '>=', $windowStart)
            ->where('touched_at', '<=', $windowEnd)
            ->whereNotNull('visitor_key')
            ->where('visitor_key', '!=', '');

        $browsers = (int) DB::query()
            ->fromSub($landingKeys->union($visitKeys), 'browser_union')
            ->count();

        $conversionBase = DB::table($conversionTable)
            ->whereIn('affiliate_id', $affiliateIds)
            ->where('occurred_at', '>=', $windowStart)
            ->where('occurred_at', '<=', $windowEnd);

        if ($outcomes !== []) {
            $conversionBase->whereIn('conversion_type', array_values($outcomes));
        }

        $statusCounts = (clone $conversionBase)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();

        $valid = 0;
        $reversed = 0;
        $rejected = 0;

        foreach ($statusCounts as $status => $total) {
            if (in_array($status, self::VALID_STATUSES, true)) {
                $valid += $total;
            } elseif ($status === 'reversed') {
                $reversed += $total;
            } elseif ($status === 'rejected') {
                $rejected += $total;
            }
        }

        $conversionTotal = array_sum($statusCounts);
        $unknown = max(0, $conversionTotal - $valid - $reversed - $rejected);

        $byOutcome = (clone $conversionBase)
            ->whereIn('status', self::VALID_STATUSES)
            ->selectRaw('conversion_type, COUNT(*) as total')
            ->groupBy('conversion_type')
            ->orderBy('conversion_type')
            ->pluck('total', 'conversion_type')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();

        $verified = (int) ($statusCounts['paid'] ?? 0);

        $unattributed = $sharer === null
            ? ShareSourceAttributionReceipt::query()
                ->whereNull('attribution_id')
                ->where('occurred_at', '>=', $windowStart)
                ->where('occurred_at', '<=', $windowEnd)
                ->count()
            : null;

        $byDay = $withDaily
            ? $this->dailyShares($tz, $fromLocal, $toLocal, $touchpointTable, $affiliateIds, $windowStart, $windowEnd)
            : [];

        $newest = $this->newestFactAt($touchpointTable, $conversionTable, $attributionTable, $affiliateIds);

        return [
            'status' => ShareTrackingMeasurement::STATUS_ACTIVE,
            'definition_version' => $definitionVersion,
            'reporting_start' => $startedAt->toIso8601String(),
            'scope' => $sharer === null ? 'global' : 'member',
            'period_start' => $windowStart->toIso8601String(),
            'period_end' => $windowEnd->toIso8601String(),
            'timezone' => $tz->getName(),
            'freshness' => [
                'newest_fact_at' => $newest?->toIso8601String(),
                'read_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            ],
            'totals' => [
                'submitted_shares' => (int) $submitted,
                'recorded_shares' => (int) $recorded,
                'recorded_visits' => (int) $visits,
                'distinct_sharing_browsers' => $browsers,
                'share_intensity' => $browsers > 0 ? round($recorded / $browsers, 3) : 0.0,
                'accepted_participations' => $valid,
                'verified_participations' => $verified,
            ],
            'by_outcome' => $byOutcome,
            'by_category' => [
                'valid' => $valid,
                'pending_unknown' => $unknown,
                'reversed' => $reversed,
                'rejected' => $rejected,
                'unattributed_observed' => $unattributed,
            ],
            'by_day' => $byDay,
        ];
    }

    /**
     * @return list<string>
     */
    private function memberAffiliateIds(User|string $sharer): array
    {
        $user = $sharer instanceof User
            ? $sharer
            : User::query()->whereKey($sharer)->first();

        // Unknown sharer ids resolve to an empty scope, never to another
        // member's facts and never to the global scope.
        if (! $user instanceof User || ! $user->exists) {
            return [];
        }

        return ShareProfileScope::affiliateIdsForUser($user);
    }

    /**
     * Viewer-local day buckets as indexed per-day range aggregates: exact
     * on every driver, with no timezone SQL dialect and no row fetching.
     *
     * @param  list<string>  $affiliateIds
     * @return array<string, int>
     */
    private function dailyShares(
        \DateTimeZone $tz,
        CarbonImmutable $fromLocal,
        CarbonImmutable $toLocal,
        string $touchpointTable,
        array $affiliateIds,
        CarbonImmutable $windowStart,
        CarbonImmutable $windowEnd,
    ): array {
        $cursor = $fromLocal->setTimezone($tz)->startOfDay();
        $lastDay = $toLocal->setTimezone($tz)->startOfDay();
        $byDay = [];

        while (! $cursor->greaterThan($lastDay)) {
            $dayStart = $cursor->setTimezone('UTC');
            $dayEnd = $cursor->endOfDay()->setTimezone('UTC');

            $from = $dayStart->greaterThan($windowStart) ? $dayStart : $windowStart;
            $to = $dayEnd->lessThan($windowEnd) ? $dayEnd : $windowEnd;

            $byDay[$cursor->toDateString()] = $from->greaterThan($to) ? 0 : (int) DB::table($touchpointTable)
                ->whereIn('affiliate_id', $affiliateIds)
                ->where('touchpoint_type', 'outbound_share')
                ->where('touched_at', '>=', $from)
                ->where('touched_at', '<=', $to)
                ->count();

            $cursor = $cursor->addDay();
        }

        return $byDay;
    }

    /**
     * @param  list<string>  $affiliateIds
     */
    private function newestFactAt(
        string $touchpointTable,
        string $conversionTable,
        string $attributionTable,
        array $affiliateIds,
    ): ?CarbonImmutable {
        $candidates = [
            DB::table($touchpointTable)->whereIn('affiliate_id', $affiliateIds)->max('touched_at'),
            DB::table($conversionTable)->whereIn('affiliate_id', $affiliateIds)->max('occurred_at'),
            DB::table($attributionTable)->whereIn('affiliate_id', $affiliateIds)->max('first_seen_at'),
        ];

        $newest = null;

        foreach ($candidates as $candidate) {
            if ($candidate === null) {
                continue;
            }

            $at = CarbonImmutable::parse($candidate, 'UTC');

            if ($newest === null || $at->greaterThan($newest)) {
                $newest = $at;
            }
        }

        return $newest;
    }

    /**
     * @return array{
     *   status: string,
     *   definition_version: string,
     *   reporting_start: ?string,
     *   scope: string,
     *   period_start: string,
     *   period_end: string,
     *   timezone: string,
     *   freshness: array{newest_fact_at: ?string, read_at: string},
     *   totals: array{submitted_shares: int, recorded_shares: int, recorded_visits: int, distinct_sharing_browsers: int, share_intensity: float, accepted_participations: int, verified_participations: int},
     *   by_outcome: array<string, int>,
     *   by_category: array{valid: int, pending_unknown: int, reversed: int, rejected: int, unattributed_observed: ?int},
     *   by_day: array<string, int>,
     * }
     */
    private function blank(
        string $definitionVersion,
        ?CarbonImmutable $reportingStart,
        string $scope,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        string $timezone,
    ): array {
        return [
            'status' => $reportingStart === null ? ShareTrackingMeasurement::STATUS_NOT_CONFIGURED : ShareTrackingMeasurement::STATUS_ACTIVE,
            'definition_version' => $definitionVersion,
            'reporting_start' => $reportingStart?->toIso8601String(),
            'scope' => $scope,
            'period_start' => $periodStart->toIso8601String(),
            'period_end' => $periodEnd->toIso8601String(),
            'timezone' => $timezone,
            'freshness' => [
                'newest_fact_at' => null,
                'read_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            ],
            'totals' => [
                'submitted_shares' => 0,
                'recorded_shares' => 0,
                'recorded_visits' => 0,
                'distinct_sharing_browsers' => 0,
                'share_intensity' => 0.0,
                'accepted_participations' => 0,
                'verified_participations' => 0,
            ],
            'by_outcome' => [],
            'by_category' => [
                'valid' => 0,
                'pending_unknown' => 0,
                'reversed' => 0,
                'rejected' => 0,
                'unattributed_observed' => null,
            ],
            'by_day' => [],
        ];
    }
}
