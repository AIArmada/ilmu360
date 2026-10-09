<?php

declare(strict_types=1);

namespace App\Support\Share;

use AIArmada\Affiliates\Enums\FraudSignalStatus;
use AIArmada\Affiliates\Models\AffiliateAttribution;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateFraudSignal;
use AIArmada\Affiliates\Models\AffiliateTouchpoint;
use App\Enums\ShareGuestClaimStatus;
use App\Models\ShareGuestClaim;
use App\Models\User;
use App\Support\ShareTracking\ShareTrackingMeasurement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Canonical cohort authority: landing → signup → first COMMITTED
 * outbound share → accepted participation, with a fixed 30-day
 * per-signup maturation.
 *
 * Cohorts are UTC calendar weeks of user signup. Every funnel event is
 * bounded by the signup's own window [signup_at, signup_at + 30 days]
 * intersected with (-∞, asOf]: post-window and future events never
 * count, and a signup near the week end matures on its own clock. A
 * week is mature only once its last day matured (week_end + 30 days);
 * immature weeks report pending counts, never rates.
 *
 * - denominator: all signups in the week;
 * - attributed: signups with frozen attributed signup evidence
 *   (attributed signup conversion naming them as actor);
 * - first_shares: attributed-sample members with a first committed
 *   outbound_share touchpoint inside their window;
 * - participations: first-sharers with accepted (qualified/approved/
 *   paid) downstream conversions inside their window;
 * - fraud_excluded: members with CONFIRMED fraud signals on their own
 *   funnel facts — excluded from participations and listed, never
 *   silently dropped;
 * - reversed_excluded: members with reversed (corrected) conversions
 *   but no confirmed fraud — corrections are not fraud.
 *
 * Ownership is primary member facts (frozen sharer_user_id) plus
 * actively claimed guest history, matching ShareProfileScope. Until
 * the operator declares a canonical reporting start, cohorts are
 * withheld as not_configured. Reads are bounded per-day aggregates on
 * every driver; no subject-level rows leave.
 */
final class ShareCohortAuthority
{
    public const int MATURITY_DAYS = 30;

    /**
     * @return array{
     *   status: string,
     *   definition_version: string,
     *   reporting_start: ?string,
     *   maturity_days: int,
     *   freshness: array{read_at: string},
     *   cohorts: list<array{
     *     cohort_week: string,
     *     mature: bool,
     *     denominator: int,
     *     attributed_signups: int,
     *     unknown: int,
     *     pending: int,
     *     first_shares: ?int,
     *     first_share_rate: ?float,
     *     participations: ?int,
     *     participation_rate: ?float,
     *     fraud_excluded: int,
     *     reversed_excluded: int,
     *   }>,
     * }
     */
    public function summarize(CarbonImmutable $asOf, int $weeks = 12): array
    {
        if ($weeks < 1 || $weeks > 52) {
            throw new InvalidArgumentException('Cohort window must be 1..52 weeks.');
        }

        $definitionVersion = ShareTrackingMeasurement::definitionVersion();
        $reportingStart = ShareTrackingMeasurement::reportingStart();
        $startedAt = $reportingStart === null ? null : CarbonImmutable::parse($reportingStart, 'UTC');

        if ($startedAt === null) {
            return [
                'status' => ShareTrackingMeasurement::STATUS_NOT_CONFIGURED,
                'definition_version' => $definitionVersion,
                'reporting_start' => null,
                'maturity_days' => self::MATURITY_DAYS,
                'freshness' => ['read_at' => CarbonImmutable::now('UTC')->toIso8601String()],
                'cohorts' => [],
            ];
        }

        $this->pinUtcSession();

        $asOf = $asOf->setTimezone('UTC');
        $anchor = $asOf->startOfWeek()->subWeeks($weeks - 1)->startOfDay();

        $tables = [
            'users' => (new User)->getTable(),
            'touchpoints' => (new AffiliateTouchpoint)->getTable(),
            'attributions' => (new AffiliateAttribution)->getTable(),
            'conversions' => (new AffiliateConversion)->getTable(),
            'claims' => (new ShareGuestClaim)->getTable(),
            'fraud' => (new AffiliateFraudSignal)->getTable(),
        ];

        $windowEnd = $this->windowEndExpression();
        $asOfString = $asOf->format('Y-m-d H:i:s');
        $startString = $startedAt->format('Y-m-d H:i:s');
        $anchorString = $anchor->format('Y-m-d H:i:s');

        $denominators = $this->countsBySignupDay($tables, $anchorString, static function (Builder $query): void {});
        $attributed = $this->countsBySignupDay($tables, $anchorString, static function (Builder $query) use ($tables, $startString, $asOfString): void {
            $conversions = $tables['conversions'];

            $query->whereExists(static function (Builder $exists) use ($conversions, $startString, $asOfString): void {
                $exists->select(DB::raw('1'))
                    ->from("{$conversions} as c")
                    ->whereColumn('c.actor_user_id', 'u.id')
                    ->where('c.conversion_type', 'signup')
                    ->whereNotNull('c.affiliate_attribution_id')
                    ->where('c.status', '!=', 'rejected')
                    ->where('c.occurred_at', '>=', $startString)
                    ->where('c.occurred_at', '<=', $asOfString);
            });
        });

        $firstShares = $this->countsBySignupDay($tables, $anchorString, function (Builder $query) use ($tables, $windowEnd, $startString, $asOfString): void {
            $this->whereHasFirstShare($query, $tables, $windowEnd, $startString, $asOfString);
        });

        $participations = $this->countsBySignupDay($tables, $anchorString, function (Builder $query) use ($tables, $windowEnd, $startString, $asOfString): void {
            $this->whereHasFirstShare($query, $tables, $windowEnd, $startString, $asOfString);
            $this->whereHasAcceptedParticipation($query, $tables, $windowEnd, $startString, $asOfString);
            $this->whereHasNoConfirmedFraud($query, $tables);
        });

        $fraud = $this->countsBySignupDay($tables, $anchorString, function (Builder $query) use ($tables): void {
            $this->whereHasConfirmedFraud($query, $tables);
        });

        $reversed = $this->countsBySignupDay($tables, $anchorString, function (Builder $query) use ($tables): void {
            $this->whereHasReversedConversion($query, $tables);
            $this->whereHasNoConfirmedFraud($query, $tables);
        });

        $cohorts = [];
        $cursor = $anchor;

        for ($i = 0; $i < $weeks; $i++) {
            $weekStart = $cursor->toDateString();
            $weekEnd = $cursor->addDays(6);
            // The week matures only once its LAST signup matured: a
            // signup near the week end is never called mature early.
            $mature = $weekEnd->endOfDay()->addDays(self::MATURITY_DAYS)->lessThanOrEqualTo($asOf);

            $denominator = 0;
            $attributedCount = 0;
            $first = 0;
            $participated = 0;
            $fraudCount = 0;
            $reversedCount = 0;

            for ($d = 0; $d < 7; $d++) {
                $day = $cursor->addDays($d)->toDateString();
                $denominator += $denominators[$day] ?? 0;
                $attributedCount += $attributed[$day] ?? 0;
                $first += $firstShares[$day] ?? 0;
                $participated += $participations[$day] ?? 0;
                $fraudCount += $fraud[$day] ?? 0;
                $reversedCount += $reversed[$day] ?? 0;
            }

            $cohorts[] = [
                'cohort_week' => $weekStart,
                'mature' => $mature,
                'denominator' => $denominator,
                'attributed_signups' => $attributedCount,
                'unknown' => max(0, $denominator - $attributedCount),
                'pending' => $mature ? 0 : $denominator,
                'first_shares' => $mature ? $first : null,
                'first_share_rate' => $mature && $denominator > 0 ? round($first / $denominator, 4) : null,
                'participations' => $mature ? $participated : null,
                'participation_rate' => $mature && $denominator > 0 ? round($participated / $denominator, 4) : null,
                'fraud_excluded' => $fraudCount,
                'reversed_excluded' => $reversedCount,
            ];

            $cursor = $cursor->addWeek();
        }

        return [
            'status' => ShareTrackingMeasurement::STATUS_ACTIVE,
            'definition_version' => $definitionVersion,
            'reporting_start' => $startedAt->toIso8601String(),
            'maturity_days' => self::MATURITY_DAYS,
            'freshness' => ['read_at' => CarbonImmutable::now('UTC')->toIso8601String()],
            'cohorts' => $cohorts,
        ];
    }

    /**
     * UTC calendar weeks need UTC day buckets. PostgreSQL sessions are
     * already pinned by connection config; MySQL/MariaDB sessions are
     * pinned here with an offset (no tz tables needed); SQLite stores
     * UTC walls.
     */
    private function pinUtcSession(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("SET time_zone = '+00:00'");
        }
    }

    private function windowEndExpression(): string
    {
        return match (DB::getDriverName()) {
            'sqlite' => "datetime(u.created_at, '+30 days')",
            'pgsql' => "(u.created_at + INTERVAL '30 days')",
            'mysql', 'mariadb' => 'DATE_ADD(u.created_at, INTERVAL 30 DAY)',
            default => throw new RuntimeException('Cohort maturation supports only sqlite, pgsql, and mysql drivers.'),
        };
    }

    /**
     * @param  array<string, string>  $tables
     * @param  callable(Builder): void  $scope
     * @return array<string, int>
     */
    private function countsBySignupDay(array $tables, string $anchorString, callable $scope): array
    {
        $query = DB::table("{$tables['users']} as u")
            ->selectRaw('DATE(u.created_at) as signup_day, COUNT(DISTINCT u.id) as total')
            ->where('u.created_at', '>=', $anchorString);

        $scope($query);

        return $query
            ->groupBy(DB::raw('DATE(u.created_at)'))
            ->pluck('total', 'signup_day')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();
    }

    /**
     * @param  array<string, string>  $tables
     */
    private function whereHasFirstShare(
        Builder $query,
        array $tables,
        string $windowEnd,
        string $startString,
        string $asOfString,
    ): void {
        $touchpoints = $tables['touchpoints'];
        $attributions = $tables['attributions'];
        $claims = $tables['claims'];

        $query->whereExists(static function (Builder $exists) use (
            $touchpoints, $attributions, $claims, $windowEnd, $startString, $asOfString
        ): void {
            $exists->select(DB::raw('1'))
                ->from("{$touchpoints} as t")
                ->join("{$attributions} as a", 'a.id', '=', 't.affiliate_attribution_id')
                ->where('t.touchpoint_type', 'outbound_share')
                ->where(static function (Builder $owner) use ($claims): void {
                    $owner->whereColumn('a.sharer_user_id', 'u.id')
                        ->orWhereExists(static function (Builder $claim) use ($claims): void {
                            $claim->select(DB::raw('1'))
                                ->from("{$claims} as cl")
                                ->whereColumn('cl.guest_affiliate_id', 't.affiliate_id')
                                ->whereColumn('cl.claimant_user_id', 'u.id')
                                ->where('cl.status', ShareGuestClaimStatus::Active->value)
                                ->whereNull('cl.revoked_at');
                        });
                })
                ->where('t.touched_at', '>=', DB::raw('u.created_at'))
                ->where('t.touched_at', '<=', DB::raw($windowEnd))
                ->where('t.touched_at', '>=', $startString)
                ->where('t.touched_at', '<=', $asOfString);
        });
    }

    /**
     * @param  array<string, string>  $tables
     */
    private function whereHasAcceptedParticipation(
        Builder $query,
        array $tables,
        string $windowEnd,
        string $startString,
        string $asOfString,
    ): void {
        $conversions = $tables['conversions'];
        $claims = $tables['claims'];

        $query->whereExists(static function (Builder $exists) use (
            $conversions, $claims, $windowEnd, $startString, $asOfString
        ): void {
            $exists->select(DB::raw('1'))
                ->from("{$conversions} as c2")
                ->whereIn('c2.status', ShareMetricReader::VALID_STATUSES)
                ->where(static function (Builder $owner) use ($claims): void {
                    $owner->whereColumn('c2.sharer_user_id', 'u.id')
                        ->orWhereExists(static function (Builder $claim) use ($claims): void {
                            $claim->select(DB::raw('1'))
                                ->from("{$claims} as cl2")
                                ->whereColumn('cl2.guest_affiliate_id', 'c2.affiliate_id')
                                ->whereColumn('cl2.claimant_user_id', 'u.id')
                                ->where('cl2.status', ShareGuestClaimStatus::Active->value)
                                ->whereNull('cl2.revoked_at');
                        });
                })
                ->where('c2.occurred_at', '>=', DB::raw('u.created_at'))
                ->where('c2.occurred_at', '<=', DB::raw($windowEnd))
                ->where('c2.occurred_at', '>=', $startString)
                ->where('c2.occurred_at', '<=', $asOfString);
        });
    }

    /**
     * @param  array<string, string>  $tables
     */
    private function whereHasConfirmedFraud(Builder $query, array $tables): void
    {
        $fraud = $tables['fraud'];
        $conversions = $tables['conversions'];
        $touchpoints = $tables['touchpoints'];
        $attributions = $tables['attributions'];
        $claims = $tables['claims'];

        $query->whereExists(static function (Builder $exists) use (
            $fraud, $conversions, $touchpoints, $attributions, $claims
        ): void {
            $exists->select(DB::raw('1'))
                ->from("{$fraud} as f")
                ->where('f.status', FraudSignalStatus::Confirmed->value)
                ->where(static function (Builder $evidence) use (
                    $conversions, $touchpoints, $attributions, $claims
                ): void {
                    $evidence->whereExists(static function (Builder $conversion) use ($conversions, $claims): void {
                        $conversion->select(DB::raw('1'))
                            ->from("{$conversions} as cf")
                            ->whereColumn('cf.id', 'f.conversion_id')
                            ->where(static function (Builder $owner) use ($claims): void {
                                $owner->whereColumn('cf.sharer_user_id', 'u.id')
                                    ->orWhereExists(static function (Builder $claim) use ($claims): void {
                                        $claim->select(DB::raw('1'))
                                            ->from("{$claims} as clf")
                                            ->whereColumn('clf.guest_affiliate_id', 'cf.affiliate_id')
                                            ->whereColumn('clf.claimant_user_id', 'u.id')
                                            ->where('clf.status', ShareGuestClaimStatus::Active->value)
                                            ->whereNull('clf.revoked_at');
                                    });
                            });
                    })->orWhereExists(static function (Builder $touchpoint) use (
                        $touchpoints, $attributions, $claims
                    ): void {
                        $touchpoint->select(DB::raw('1'))
                            ->from("{$touchpoints} as tf")
                            ->join("{$attributions} as af", 'af.id', '=', 'tf.affiliate_attribution_id')
                            ->whereColumn('tf.id', 'f.touchpoint_id')
                            ->where('tf.touchpoint_type', 'outbound_share')
                            ->where(static function (Builder $owner) use ($claims): void {
                                $owner->whereColumn('af.sharer_user_id', 'u.id')
                                    ->orWhereExists(static function (Builder $claim) use ($claims): void {
                                        $claim->select(DB::raw('1'))
                                            ->from("{$claims} as clt")
                                            ->whereColumn('clt.guest_affiliate_id', 'tf.affiliate_id')
                                            ->whereColumn('clt.claimant_user_id', 'u.id')
                                            ->where('clt.status', ShareGuestClaimStatus::Active->value)
                                            ->whereNull('clt.revoked_at');
                                    });
                            });
                    });
                });
        });
    }

    /**
     * @param  array<string, string>  $tables
     */
    private function whereHasReversedConversion(Builder $query, array $tables): void
    {
        $conversions = $tables['conversions'];
        $claims = $tables['claims'];

        $query->whereExists(static function (Builder $exists) use ($conversions, $claims): void {
            $exists->select(DB::raw('1'))
                ->from("{$conversions} as cr")
                ->where('cr.status', 'reversed')
                ->where(static function (Builder $owner) use ($claims): void {
                    $owner->whereColumn('cr.sharer_user_id', 'u.id')
                        ->orWhereExists(static function (Builder $claim) use ($claims): void {
                            $claim->select(DB::raw('1'))
                                ->from("{$claims} as clr")
                                ->whereColumn('clr.guest_affiliate_id', 'cr.affiliate_id')
                                ->whereColumn('clr.claimant_user_id', 'u.id')
                                ->where('clr.status', ShareGuestClaimStatus::Active->value)
                                ->whereNull('clr.revoked_at');
                        });
                });
        });
    }

    /**
     * @param  array<string, string>  $tables
     */
    private function whereHasNoConfirmedFraud(Builder $query, array $tables): void
    {
        $fraud = $tables['fraud'];
        $conversions = $tables['conversions'];
        $touchpoints = $tables['touchpoints'];
        $attributions = $tables['attributions'];
        $claims = $tables['claims'];

        $query->whereNotExists(static function (Builder $exists) use (
            $fraud, $conversions, $touchpoints, $attributions, $claims
        ): void {
            $exists->select(DB::raw('1'))
                ->from("{$fraud} as fn")
                ->where('fn.status', FraudSignalStatus::Confirmed->value)
                ->where(static function (Builder $evidence) use (
                    $conversions, $touchpoints, $attributions, $claims
                ): void {
                    $evidence->whereExists(static function (Builder $conversion) use ($conversions, $claims): void {
                        $conversion->select(DB::raw('1'))
                            ->from("{$conversions} as cfn")
                            ->whereColumn('cfn.id', 'fn.conversion_id')
                            ->where(static function (Builder $owner) use ($claims): void {
                                $owner->whereColumn('cfn.sharer_user_id', 'u.id')
                                    ->orWhereExists(static function (Builder $claim) use ($claims): void {
                                        $claim->select(DB::raw('1'))
                                            ->from("{$claims} as clfn")
                                            ->whereColumn('clfn.guest_affiliate_id', 'cfn.affiliate_id')
                                            ->whereColumn('clfn.claimant_user_id', 'u.id')
                                            ->where('clfn.status', ShareGuestClaimStatus::Active->value)
                                            ->whereNull('clfn.revoked_at');
                                    });
                            });
                    })->orWhereExists(static function (Builder $touchpoint) use (
                        $touchpoints, $attributions, $claims
                    ): void {
                        $touchpoint->select(DB::raw('1'))
                            ->from("{$touchpoints} as tfn")
                            ->join("{$attributions} as afn", 'afn.id', '=', 'tfn.affiliate_attribution_id')
                            ->whereColumn('tfn.id', 'fn.touchpoint_id')
                            ->where('tfn.touchpoint_type', 'outbound_share')
                            ->where(static function (Builder $owner) use ($claims): void {
                                $owner->whereColumn('afn.sharer_user_id', 'u.id')
                                    ->orWhereExists(static function (Builder $claim) use ($claims): void {
                                        $claim->select(DB::raw('1'))
                                            ->from("{$claims} as cltn")
                                            ->whereColumn('cltn.guest_affiliate_id', 'tfn.affiliate_id')
                                            ->whereColumn('cltn.claimant_user_id', 'u.id')
                                            ->where('cltn.status', ShareGuestClaimStatus::Active->value)
                                            ->whereNull('cltn.revoked_at');
                                    });
                            });
                    });
                });
        });
    }
}
