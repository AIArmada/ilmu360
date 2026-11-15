<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Queries;

use AIArmada\Affiliates\Enums\FraudSignalStatus;
use AIArmada\Affiliates\Models\AffiliateAttribution;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateFraudSignal;
use AIArmada\Affiliates\Models\AffiliateTouchpoint;
use App\Features\Registration\Api\PaidRegistrationFacts;
use App\Features\ShareTracking\Api\MarkedShareAffiliateIds;
use App\Features\ShareTracking\Api\ShareOutcomeEvidence;
use App\Features\ShareTracking\Api\ShareOutcomeEvidenceReader;
use App\Features\ShareTracking\Support\ShareMetricDefinitions;
use App\Platform\Models\MorphModelResolver;
use App\Shared\ShareTracking\CommittedShareSignal;
use App\Shared\ShareTracking\ShareOutcomeType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Reads only aggregate evidence from affiliate source facts. Participant
 * identifiers and mutable source records never leave this query boundary.
 */
final readonly class DatabaseShareOutcomeEvidenceReader implements ShareOutcomeEvidenceReader
{
    /**
     * Admin-panel roles whose holders act staff-side and do not count as
     * community participants.
     *
     * @var list<string>
     */
    private const array STAFF_ROLES = ['super_admin', 'admin', 'moderator', 'editor', 'viewer'];

    public function __construct(
        private PaidRegistrationFacts $registrationFacts,
        private MarkedShareAffiliateIds $markedAffiliates,
    ) {}

    public function forWindow(CarbonImmutable $start, CarbonImmutable $endExclusive): ShareOutcomeEvidence
    {
        $query = $this->evidenceQuery($start, $endExclusive);
        $paidRegistrationIds = $this->paidRegistrationIds($query);
        $evidence = $this->excludePaidPurchasers($query, $paidRegistrationIds);
        $totals = (clone $evidence)
            ->selectRaw('COUNT(*) AS outcomes, COUNT(DISTINCT c.sharer_user_id) AS sharers, COUNT(DISTINCT c.actor_user_id) AS participants')
            ->first();

        return new ShareOutcomeEvidence(
            outcomeCount: (int) ($totals->outcomes ?? 0),
            distinctSharers: (int) ($totals->sharers ?? 0),
            distinctParticipants: (int) ($totals->participants ?? 0),
            byChannel: $this->aggregateCells($evidence, $this->channelExpression()),
            byOutcome: $this->aggregateCells($evidence, 'c.conversion_type'),
            definitionVersion: ShareMetricDefinitions::VERSION,
        );
    }

    /**
     * The qualified share-outcome set excludes commercial affiliates, invalid
     * statuses, non-share conversions, ghost identities and fraud-tainted
     * users before any counts or cells are calculated.
     */
    private function evidenceQuery(CarbonImmutable $start, CarbonImmutable $endExclusive): Builder
    {
        $conversions = (new AffiliateConversion)->getTable();
        $userClass = MorphModelResolver::resolve('user');
        $users = (new $userClass)->getTable();
        $kinds = array_map(
            static fn (ShareOutcomeType $case): string => $case->value,
            ShareOutcomeType::cases(),
        );

        $query = DB::table("{$conversions} as c")
            ->whereIn('c.affiliate_id', $this->markedAffiliates->allMarkedAffiliateIds())
            ->where('c.occurred_at', '>=', $start)
            ->where('c.occurred_at', '<', $endExclusive)
            ->whereIn('c.status', CommittedShareSignal::VALID_CONVERSION_STATUSES)
            ->whereIn('c.conversion_type', $kinds)
            ->whereNotNull('c.sharer_user_id')
            ->whereExists(static function (Builder $exists) use ($users): void {
                $exists->select(DB::raw('1'))
                    ->from("{$users} as u")
                    ->whereColumn('u.id', 'c.sharer_user_id');
            })
            ->whereNotExists(function (Builder $exists): void {
                $this->conversionFraudExists($exists, 'c.id');
            });

        $this->whereUserFraudClean($query, 'c.sharer_user_id');

        $query->whereNotNull('c.actor_user_id')
            ->whereExists(static function (Builder $exists) use ($users): void {
                $exists->select(DB::raw('1'))
                    ->from("{$users} as participant")
                    ->whereColumn('participant.id', 'c.actor_user_id');
            });

        $this->whereNonStaff($query, 'c.actor_user_id');
        $this->whereUserFraudClean($query, 'c.actor_user_id');

        return $query;
    }

    /**
     * Resolve the paid-registration exclusions through Registration's public
     * API, using only the registration keys already present in candidate
     * event-registration evidence.
     *
     * @return list<string>
     */
    private function paidRegistrationIds(Builder $evidence): array
    {
        $registrationExpression = self::metadataRegistrationIdExpression();

        $candidateIds = (clone $evidence)
            ->where('c.conversion_type', ShareOutcomeType::EventRegistration->value)
            ->whereRaw("{$registrationExpression} IS NOT NULL")
            ->selectRaw("{$registrationExpression} AS registration_id")
            ->distinct()
            ->pluck('registration_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->filter(static fn (string $id): bool => $id !== '')
            ->values()
            ->all();

        return $this->registrationFacts->paidRegistrationIds($candidateIds);
    }

    /**
     * Apply the purchaser exclusion without excluding conversions that lack
     * a registration link or point to a registration that is not paid.
     *
     * @param  list<string>  $paidRegistrationIds
     */
    private function excludePaidPurchasers(Builder $query, array $paidRegistrationIds): Builder
    {
        if ($paidRegistrationIds === []) {
            return $query;
        }

        $registrationExpression = self::metadataRegistrationIdExpression();
        $placeholders = implode(', ', array_fill(0, count($paidRegistrationIds), '?'));

        return $query->where(static function (Builder $either) use (
            $paidRegistrationIds,
            $placeholders,
            $registrationExpression,
        ): void {
            $either->where('c.conversion_type', '!=', ShareOutcomeType::EventRegistration->value)
                ->orWhere(static function (Builder $registration) use (
                    $paidRegistrationIds,
                    $placeholders,
                    $registrationExpression,
                ): void {
                    $registration->whereRaw("{$registrationExpression} IS NULL")
                        ->orWhereRaw("{$registrationExpression} NOT IN ({$placeholders})", $paidRegistrationIds);
                });
        });
    }

    /**
     * @return array<string, array{outcomes: int, sharers: int, participants: int}>
     */
    private function aggregateCells(Builder $evidence, string $cellExpression): array
    {
        return (clone $evidence)
            ->selectRaw($cellExpression.' AS cell, COUNT(*) AS outcomes, COUNT(DISTINCT c.sharer_user_id) AS sharers, COUNT(DISTINCT c.actor_user_id) AS participants')
            ->groupByRaw($cellExpression)
            ->orderBy('cell')
            ->get()
            ->mapWithKeys(static fn (object $cell): array => [(string) $cell->cell => [
                'outcomes' => (int) $cell->outcomes,
                'sharers' => (int) $cell->sharers,
                'participants' => (int) $cell->participants,
            ]])
            ->all();
    }

    /**
     * Exclude users with confirmed fraud on their own funnel facts: a
     * conversion they shared or acted on, or an outbound share touchpoint
     * attributed to their shares.
     */
    private function whereUserFraudClean(Builder $query, string $outerUserColumn): void
    {
        $conversions = (new AffiliateConversion)->getTable();
        $touchpoints = (new AffiliateTouchpoint)->getTable();
        $attributions = (new AffiliateAttribution)->getTable();

        $query->whereNotExists(function (Builder $exists) use (
            $conversions,
            $touchpoints,
            $attributions,
            $outerUserColumn,
        ): void {
            $fraud = (new AffiliateFraudSignal)->getTable();

            $exists->select(DB::raw('1'))
                ->from("{$fraud} as f")
                ->where('f.status', FraudSignalStatus::Confirmed->value)
                ->where(static function (Builder $either) use (
                    $conversions,
                    $touchpoints,
                    $attributions,
                    $outerUserColumn,
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
                        $touchpoints,
                        $attributions,
                        $outerUserColumn,
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

    private function conversionFraudExists(Builder $exists, string $outerConversionId): void
    {
        $fraud = (new AffiliateFraudSignal)->getTable();

        $exists->select(DB::raw('1'))
            ->from("{$fraud} as f")
            ->where('f.status', FraudSignalStatus::Confirmed->value)
            ->whereColumn('f.conversion_id', $outerConversionId);
    }

    private function whereNonStaff(Builder $query, string $outerUserColumn): void
    {
        $userClass = MorphModelResolver::resolve('user');
        $userMorph = (new $userClass)->getMorphClass();
        $pivot = config('permission.table_names.model_has_roles') ?: 'model_has_roles';
        $roles = config('permission.table_names.roles') ?: 'roles';
        $morphKey = config('permission.column_names.model_morph_key') ?: 'model_uuid';
        $roleKey = config('permission.column_names.role_pivot_key') ?: 'role_id';

        $query->whereNotExists(static function (Builder $exists) use (
            $outerUserColumn,
            $userMorph,
            $pivot,
            $roles,
            $morphKey,
            $roleKey,
        ): void {
            $exists->select(DB::raw('1'))
                ->from("{$pivot} as mhr")
                ->join("{$roles} as r", 'r.id', '=', "mhr.{$roleKey}")
                ->where('mhr.model_type', $userMorph)
                ->whereColumn("mhr.{$morphKey}", $outerUserColumn)
                ->whereIn('r.name', self::STAFF_ROLES);
        });
    }

    private static function metadataRegistrationIdExpression(): string
    {
        return match (DB::getDriverName()) {
            'pgsql' => "(c.metadata->>'registration_id')",
            'sqlite' => "json_extract(c.metadata, '$.registration_id')",
            default => throw new InvalidArgumentException('Community releases support only sqlite and pgsql drivers.'),
        };
    }

    private function channelExpression(): string
    {
        return "COALESCE(NULLIF(c.channel, ''), '".ShareOutcomeEvidence::UNKNOWN_CHANNEL."')";
    }
}
