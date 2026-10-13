<?php

declare(strict_types=1);

namespace App\Actions\Share;

use AIArmada\Affiliates\Enums\FraudSignalStatus;
use AIArmada\Affiliates\Models\AffiliateAttribution;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateFraudSignal;
use AIArmada\Affiliates\Models\AffiliateTouchpoint;
use AIArmada\Rewards\Actions\CreateRewardProgramAction;
use App\Enums\DawahShareOutcomeType;
use App\Models\CommunityRelease;
use App\Models\Registration;
use App\Models\User;
use App\Support\Share\ShareMetricDefinitions;
use App\Support\Share\ShareMetricReader;
use App\Support\Share\ShareProfileScope;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Publish an immutable delayed coarse community release for one fixed
 * calendar month.
 *
 * Exactly one canonical qualified/proven participant set feeds every
 * published number: marked share-profile conversions (commercial facts
 * never enter) in the month with a valid status, a dawah participation
 * kind, a proven user sharer, a proven non-staff non-purchaser user
 * actor with the participant role per the source authority, no
 * confirmed fraud on the conversion, no fraud taint on the sharer or
 * actor, and no paid-registration purchaser linkage. Counts, totals,
 * and every cell derive from this same set; invalid, reversed,
 * unknown-status, non-dawah, unproven-sharer, unattributed, corrupt,
 * ghost-actor, staff-acted, purchaser, fraudulent, and commercial rows
 * are invisible everywhere, so complements cannot leak them back.
 * Totals and cuts are explicitly proven-evidence-only with no broader
 * revealing complementary total.
 *
 * Guards, in order: fixed YYYY-MM months only (no arbitrary overlapping
 * windows to difference); the month must have ended at least DELAY_DAYS
 * ago; BOTH the distinct-sharer threshold and the distinct proven
 * participant threshold over the SAME set must hold; every published
 * cell must independently clear the same dual thresholds, and cuts
 * publish all-or-nothing (one sparse cell suppresses the whole cut, so
 * the hidden side cannot be differenced out of the total). Unknown
 * channels form an explicit cell under the same rule. Anything
 * unprovable or sparse yields an honest insufficient-data refusal
 * instead of a thin release. Concurrent publishers converge on one row
 * per month.
 */
final readonly class PublishCommunityReleaseAction
{
    use AsAction;

    public const int DELAY_DAYS = 7;

    public const int SHARER_THRESHOLD = 10;

    public const int PARTICIPANT_THRESHOLD = 10;

    public const int LOGIC_VERSION = 3;

    public const string UNKNOWN_CELL = 'unknown';

    /**
     * Admin-panel roles whose holders act staff-side and are never
     * counted as community participants.
     *
     * @var list<string>
     */
    public const array STAFF_ROLES = ['super_admin', 'admin', 'moderator', 'editor', 'viewer'];

    public function handle(
        string $month,
        string $actorType,
        ?string $actorId,
        ?CarbonInterface $decidedAt = null,
    ): CommunityRelease {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new InvalidArgumentException('Release month must be a fixed calendar month (YYYY-MM).');
        }

        $publisherRole = self::verifiedPublisherRole($actorType, $actorId);

        $start = CarbonImmutable::parse($month.'-01')->startOfDay()->setTimezone('UTC');
        $nextMonth = $start->addMonth();
        $end = $nextMonth->subSecond();

        $decided = $decidedAt === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($decidedAt)->setTimezone('UTC');

        if ($end->addDays(self::DELAY_DAYS)->greaterThan($decided)) {
            throw new InvalidArgumentException('Community releases publish only for months ended at least '.self::DELAY_DAYS.' days ago.');
        }

        $releaseKey = 'community:'.$month;

        try {
            return DB::transaction(function () use ($start, $nextMonth, $end, $releaseKey, $actorType, $actorId, $publisherRole, $decided): CommunityRelease {
                $existing = CommunityRelease::query()->where('release_key', $releaseKey)->lockForUpdate()->first();

                if ($existing instanceof CommunityRelease) {
                    return $existing;
                }

                $evidence = self::evidenceQuery($start, $nextMonth);

                $sharers = self::distinctSharers($evidence);
                $participants = self::distinctParticipants($evidence);

                if ($sharers < self::SHARER_THRESHOLD || $participants < self::PARTICIPANT_THRESHOLD) {
                    throw new InvalidArgumentException(
                        'Insufficient data: release needs at least '.self::SHARER_THRESHOLD.' distinct sharers and '
                        .self::PARTICIPANT_THRESHOLD.' distinct evidenced participants.'
                    );
                }

                $cells = self::suppressedCells($evidence);

                return CommunityRelease::query()->create([
                    'release_key' => $releaseKey,
                    'period_start' => $start->toDateString(),
                    'period_end' => $end->toDateString(),
                    'cells' => array_merge($cells, [
                        'definition_version' => ShareMetricDefinitions::VERSION,
                        'logic_version' => self::LOGIC_VERSION,
                        'thresholds' => [
                            'sharers' => self::SHARER_THRESHOLD,
                            'participants' => self::PARTICIPANT_THRESHOLD,
                            'delay_days' => self::DELAY_DAYS,
                        ],
                    ]),
                    'sharer_count' => $sharers,
                    'participant_count' => $participants,
                    'published_by_type' => $actorType,
                    'published_by_id' => $actorId,
                    'published_by_role' => $publisherRole,
                    'published_at' => $decided,
                ]);
            });
        } catch (QueryException $exception) {
            // A concurrent publisher won the month insert; converge on
            // their row instead of erroring. Every catch sits outside
            // the transaction, PostgreSQL-safe.
            if (! CreateRewardProgramAction::isUniqueViolation($exception)) {
                throw $exception;
            }

            $winner = CommunityRelease::query()->where('release_key', $releaseKey)->first();

            if (! $winner instanceof CommunityRelease) {
                throw $exception;
            }

            return $winner;
        }
    }

    private static function verifiedPublisherRole(string $actorType, ?string $actorId): string
    {
        if ($actorType !== 'user' || $actorId === null || $actorId === '') {
            throw new InvalidArgumentException('Community releases require a staff/admin user identity.');
        }

        $user = User::query()->whereKey($actorId)->first();

        if (! $user instanceof User || ! $user->hasAnyRole(['super_admin', 'admin'])) {
            throw new InvalidArgumentException('Community releases require a staff/admin user identity.');
        }

        return $user->hasRole('super_admin') ? 'super_admin' : 'admin';
    }

    /**
     * The single canonical qualified/proven participant set: marked
     * share-profile conversions (commercial excluded), in-month, valid
     * status, dawah kinds only, proven user sharer, proven non-staff
     * non-purchaser user actor, no confirmed fraud on the conversion,
     * and no fraud taint on sharer or actor. Unattributed, corrupt,
     * ghost, staff, and purchaser rows are excluded here — counts,
     * totals, and every cell derive from this same set, so unknown
     * evidence can never disclose through a broader total.
     */
    private static function evidenceQuery(CarbonImmutable $start, CarbonImmutable $nextMonth): Builder
    {
        $conversions = (new AffiliateConversion)->getTable();
        $users = (new User)->getTable();

        $kinds = array_map(
            static fn (DawahShareOutcomeType $case): string => $case->value,
            DawahShareOutcomeType::cases()
        );

        $query = DB::table("{$conversions} as c")
            ->whereIn('c.affiliate_id', ShareProfileScope::markedAffiliateIds())
            ->where('c.occurred_at', '>=', $start)
            ->where('c.occurred_at', '<', $nextMonth)
            ->whereIn('c.status', ShareMetricReader::VALID_STATUSES)
            ->whereIn('c.conversion_type', $kinds)
            ->whereNotNull('c.sharer_user_id')
            ->whereExists(static function (Builder $exists) use ($users): void {
                $exists->select(DB::raw('1'))
                    ->from("{$users} as u")
                    ->whereColumn('u.id', 'c.sharer_user_id');
            })
            ->whereNotExists(function (Builder $exists): void {
                self::conversionFraudExists($exists, 'c.id');
            });

        self::whereUserFraudClean($query, 'c.sharer_user_id');

        $query->whereNotNull('c.actor_user_id')
            ->whereExists(static function (Builder $exists) use ($users): void {
                $exists->select(DB::raw('1'))
                    ->from("{$users} as au")
                    ->whereColumn('au.id', 'c.actor_user_id');
            });

        self::whereNonStaff($query, 'c.actor_user_id');
        self::whereUserFraudClean($query, 'c.actor_user_id');
        self::whereNonPurchaser($query);

        return $query;
    }

    /**
     * Exclude users with confirmed fraud on their own funnel facts: a
     * conversion they shared or acted on, or an outbound share
     * touchpoint attributed to their shares.
     */
    private static function whereUserFraudClean(Builder $query, string $outerUserColumn): void
    {
        $conversions = (new AffiliateConversion)->getTable();
        $touchpoints = (new AffiliateTouchpoint)->getTable();
        $attributions = (new AffiliateAttribution)->getTable();

        $query->whereNotExists(function (Builder $exists) use (
            $conversions, $touchpoints, $attributions, $outerUserColumn
        ): void {
            $fraud = (new AffiliateFraudSignal)->getTable();

            $exists->select(DB::raw('1'))
                ->from("{$fraud} as f")
                ->where('f.status', FraudSignalStatus::Confirmed->value)
                ->where(static function (Builder $either) use (
                    $conversions, $touchpoints, $attributions, $outerUserColumn
                ): void {
                    $either->whereExists(static function (Builder $linked) use ($conversions, $outerUserColumn): void {
                        $linked->select(DB::raw('1'))
                            ->from("{$conversions} as cf")
                            ->whereColumn('cf.id', 'f.conversion_id')
                            ->where(static function (Builder $owned) use ($outerUserColumn): void {
                                $owned->whereColumn('cf.sharer_user_id', $outerUserColumn)
                                    ->orWhereColumn('cf.actor_user_id', $outerUserColumn);
                            });
                    })->orWhereExists(static function (Builder $linked) use (
                        $touchpoints, $attributions, $outerUserColumn
                    ): void {
                        $linked->select(DB::raw('1'))
                            ->from("{$touchpoints} as tf")
                            ->join("{$attributions} as af", 'af.id', '=', 'tf.affiliate_attribution_id')
                            ->whereColumn('tf.id', 'f.touchpoint_id')
                            ->where('tf.touchpoint_type', 'outbound_share')
                            ->whereColumn('af.sharer_user_id', $outerUserColumn);
                    });
                });
        });
    }

    private static function conversionFraudExists(Builder $exists, string $outerConversionId): void
    {
        $fraud = (new AffiliateFraudSignal)->getTable();

        $exists->select(DB::raw('1'))
            ->from("{$fraud} as f")
            ->where('f.status', FraudSignalStatus::Confirmed->value)
            ->whereColumn('f.conversion_id', $outerConversionId);
    }

    private static function distinctSharers(Builder $evidence): int
    {
        return (int) (clone $evidence)->distinct()->count('c.sharer_user_id');
    }

    /**
     * Distinct proven participants over the SAME canonical evidence set:
     * no additional filtering — the evidence already requires a proven
     * non-staff non-purchaser actor — so counts and every cell share one
     * eligible set and repeats never satisfy the distinct-person
     * threshold.
     */
    private static function distinctParticipants(Builder $evidence): int
    {
        return (int) (clone $evidence)->distinct()->count('c.actor_user_id');
    }

    private static function whereNonStaff(Builder $query, string $outerUserColumn): void
    {
        $userMorph = (new User)->getMorphClass();
        $pivot = config('permission.table_names.model_has_roles') ?: 'model_has_roles';
        $roles = config('permission.table_names.roles') ?: 'roles';
        $morphKey = config('permission.column_names.model_morph_key') ?: 'model_uuid';
        $roleKey = config('permission.column_names.role_pivot_key') ?: 'role_id';

        $query->whereNotExists(static function (Builder $exists) use (
            $outerUserColumn, $userMorph, $pivot, $roles, $morphKey, $roleKey
        ): void {
            $exists->select(DB::raw('1'))
                ->from("{$pivot} as mhr")
                ->join("{$roles} as r", 'r.id', '=', "mhr.{$roleKey}")
                ->where('mhr.model_type', $userMorph)
                ->whereColumn("mhr.{$morphKey}", $outerUserColumn)
                ->whereIn('r.name', self::STAFF_ROLES);
        });
    }

    /**
     * Exclude purchaser-role event registrations per the source
     * authority: a paid source registration carries the purchaser role
     * (see ShareAttributionRewardSourceFactReader), never participant.
     * Other dawah kinds are never purchasers. Linkage is the frozen
     * conversion metadata registration_id written at record time.
     */
    private static function whereNonPurchaser(Builder $query): void
    {
        $registrations = (new Registration)->getTable();

        $query->where(static function (Builder $either) use ($registrations): void {
            $either->where('c.conversion_type', '!=', 'event_registration')
                ->orWhereNotExists(function (Builder $exists) use ($registrations): void {
                    $exists->select(DB::raw('1'))
                        ->from("{$registrations} as r")
                        ->whereRaw('r.id = '.self::metadataRegistrationIdExpression())
                        ->where('r.total_amount', '>', 0)
                        ->where('r.payment_status', 'paid');
                });
        });
    }

    private static function metadataRegistrationIdExpression(): string
    {
        return match (DB::getDriverName()) {
            'pgsql' => "(c.metadata->>'registration_id')",
            'sqlite' => "json_extract(c.metadata, '$.registration_id')",
            'mysql', 'mariadb' => "JSON_UNQUOTE(JSON_EXTRACT(c.metadata, '$.registration_id'))",
            default => throw new InvalidArgumentException('Community releases support only sqlite, pgsql, and mysql drivers.'),
        };
    }

    /**
     * Proven-evidence-only totals and cuts over the SAME canonical set,
     * with no broader revealing complementary total. Every cell count is
     * a row count over qualified/proven participant evidence only.
     *
     * @return array<string, mixed>
     */
    private static function suppressedCells(Builder $evidence): array
    {
        $byChannel = self::dualGatedCut($evidence, self::channelExpression());
        $byOutcome = self::dualGatedCut($evidence, 'c.conversion_type');

        $total = (int) (clone $evidence)->count();

        return [
            'total_outcomes' => $total,
            'evidence_basis' => 'proven-only',
            'by_channel' => $byChannel,
            'by_outcome' => $byOutcome,
            'suppressed' => array_values(array_filter([
                $byChannel === null ? 'by_channel' : null,
                $byOutcome === null ? 'by_outcome' : null,
            ])),
        ];
    }

    /**
     * One cut (channel or outcome kind): every cell publishes its row
     * count only when that cell independently clears BOTH the distinct
     * sharer threshold and the distinct proven-participant threshold.
     * One sparse cell suppresses the whole cut, so differencing the
     * published cells out of the total cannot recover the hidden side.
     *
     * @return array<string, int>|null
     */
    private static function dualGatedCut(Builder $evidence, string $cellExpression): ?array
    {
        $cells = (clone $evidence)
            ->selectRaw($cellExpression.' as cell')
            ->distinct()
            ->orderBy('cell')
            ->pluck('cell')
            ->map(static fn (mixed $cell): string => (string) $cell)
            ->all();

        if ($cells === []) {
            return null;
        }

        $published = [];

        foreach ($cells as $cell) {
            $scoped = clone $evidence;
            $scoped->whereRaw($cellExpression.' = ?', [$cell]);

            $rows = (int) (clone $scoped)->count();

            if (self::distinctSharers($scoped) < self::SHARER_THRESHOLD
                || self::distinctParticipants($scoped) < self::PARTICIPANT_THRESHOLD) {
                return null;
            }

            $published[$cell] = $rows;
        }

        return $published;
    }

    private static function channelExpression(): string
    {
        return "COALESCE(NULLIF(c.channel, ''), '".self::UNKNOWN_CELL."')";
    }
}
