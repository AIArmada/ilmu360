<?php

declare(strict_types=1);

namespace App\Support\Share;

use AIArmada\Affiliates\Enums\FraudSignalStatus;
use AIArmada\Affiliates\Models\AffiliateAttribution;
use AIArmada\Affiliates\Models\AffiliateClaim;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateFraudSignal;
use AIArmada\Affiliates\Models\AffiliateTouchpoint;
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

        $asOf = $asOf->setTimezone('UTC');
        $anchor = $asOf->startOfWeek()->subWeeks($weeks - 1)->startOfDay();

        $tables = [
            'users' => (new User)->getTable(),
            'touchpoints' => (new AffiliateTouchpoint)->getTable(),
            'attributions' => (new AffiliateAttribution)->getTable(),
            'conversions' => (new AffiliateConversion)->getTable(),
            'claims' => (new AffiliateClaim)->getTable(),
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

    private function windowEndExpression(): string
    {
        return match (DB::getDriverName()) {
            'sqlite' => "datetime(u.created_at, '+30 days')",
            'pgsql' => "(u.created_at + INTERVAL '30 days')",
            default => throw new RuntimeException('Cohort maturation supports only sqlite and pgsql drivers.'),
        };
    }

    /**
     * Claimant morph discriminator for package identity claims. Host
     * claimants are always users; the package itself stays polymorphic.
     */
    private static function hostClaimantType(): string
    {
        return (new User)->getMorphClass();
    }

    /**
     * Claimant identity match that survives strict databases: the package
     * stores the claimant id as text while users.id is a native uuid, so
     * PostgreSQL needs an explicit cast that SQLite must not see.
     */
    private static function whereClaimantMatches(Builder $query, string $alias): void
    {
        $query->where("{$alias}.claimant_type", self::hostClaimantType())
            ->whereIn("{$alias}.id", ShareProfileScope::guestClaims()->select((new AffiliateClaim)->getQualifiedKeyName()));

        if (DB::getDriverName() === 'pgsql') {
            $query->whereRaw("\"{$alias}\".\"claimant_id\" = \"u\".\"id\"::text");
        } else {
            $query->whereColumn("{$alias}.claimant_id", 'u.id');
        }
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
                                ->whereColumn('cl.affiliate_id', 't.affiliate_id')
                                ->where(static function (Builder $claimant): void {
                                    self::whereClaimantMatches($claimant, 'cl');
                                })
                                ->where('cl.status', 'active')
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
                                ->whereColumn('cl2.affiliate_id', 'c2.affiliate_id')
                                ->where(static function (Builder $claimant): void {
                                    self::whereClaimantMatches($claimant, 'cl2');
                                })
                                ->where('cl2.status', 'active')
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
                                            ->whereColumn('clf.affiliate_id', 'cf.affiliate_id')
                                            ->where(static function (Builder $claimant): void {
                                                self::whereClaimantMatches($claimant, 'clf');
                                            })
                                            ->where('clf.status', 'active')
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
                                            ->whereColumn('clt.affiliate_id', 'tf.affiliate_id')
                                            ->where(static function (Builder $claimant): void {
                                                self::whereClaimantMatches($claimant, 'clt');
                                            })
                                            ->where('clt.status', 'active')
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
                                ->whereColumn('clr.affiliate_id', 'cr.affiliate_id')
                                ->where(static function (Builder $claimant): void {
                                    self::whereClaimantMatches($claimant, 'clr');
                                })
                                ->where('clr.status', 'active')
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
                                            ->whereColumn('clfn.affiliate_id', 'cfn.affiliate_id')
                                            ->where(static function (Builder $claimant): void {
                                                self::whereClaimantMatches($claimant, 'clfn');
                                            })
                                            ->where('clfn.status', 'active')
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
                                            ->whereColumn('cltn.affiliate_id', 'tfn.affiliate_id')
                                            ->where(static function (Builder $claimant): void {
                                                self::whereClaimantMatches($claimant, 'cltn');
                                            })
                                            ->where('cltn.status', 'active')
                                            ->whereNull('cltn.revoked_at');
                                    });
                            });
                    });
                });
        });
    }
}
