<?php

declare(strict_types=1);

namespace App\Features\Community\Actions;

use App\Features\Community\Models\CommunityRelease;
use App\Features\ShareTracking\Api\ShareOutcomeEvidence;
use App\Features\ShareTracking\Api\ShareOutcomeEvidenceReader;
use App\Shared\Auth\AccountDirectory;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/** Publish one immutable, delayed, privacy-screened release for a calendar month. */
final readonly class PublishCommunityReleaseAction
{
    use AsAction;

    public const int DELAY_DAYS = 7;

    public const int SHARER_THRESHOLD = 10;

    public const int PARTICIPANT_THRESHOLD = 10;

    public const int LOGIC_VERSION = 3;

    public function __construct(
        private ShareOutcomeEvidenceReader $evidenceReader,
        private AccountDirectory $accounts,
    ) {}

    public function handle(
        string $month,
        string $actorType,
        ?string $actorId,
        ?CarbonInterface $decidedAt = null,
    ): CommunityRelease {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new InvalidArgumentException('Release month must be a fixed calendar month (YYYY-MM).');
        }

        $publisherRole = $this->verifiedPublisherRole($actorType, $actorId);

        $start = CarbonImmutable::parse($month.'-01')->startOfDay()->setTimezone('UTC');
        $nextMonth = $start->addMonth();
        $end = $nextMonth->subSecond();
        $decided = $decidedAt instanceof CarbonInterface
            ? CarbonImmutable::parse($decidedAt)->setTimezone('UTC')
            : CarbonImmutable::now('UTC');

        if ($end->addDays(self::DELAY_DAYS)->greaterThan($decided)) {
            throw new InvalidArgumentException('Community releases publish only for months ended at least '.self::DELAY_DAYS.' days ago.');
        }

        $releaseKey = 'community:'.$month;

        try {
            return DB::transaction(function () use (
                $start,
                $nextMonth,
                $end,
                $releaseKey,
                $actorType,
                $actorId,
                $publisherRole,
                $decided,
            ): CommunityRelease {
                $existing = CommunityRelease::query()
                    ->where('release_key', $releaseKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing instanceof CommunityRelease) {
                    return $existing;
                }

                $evidence = $this->evidenceReader->forWindow($start, $nextMonth);

                if ($evidence->distinctSharers < self::SHARER_THRESHOLD
                    || $evidence->distinctParticipants < self::PARTICIPANT_THRESHOLD) {
                    throw new InvalidArgumentException(
                        'Insufficient data: release needs at least '.self::SHARER_THRESHOLD.' distinct sharers and '
                        .self::PARTICIPANT_THRESHOLD.' distinct evidenced participants.'
                    );
                }

                return CommunityRelease::query()->create([
                    'release_key' => $releaseKey,
                    'period_start' => $start->toDateString(),
                    'period_end' => $end->toDateString(),
                    'cells' => array_merge($this->suppressedCells($evidence), [
                        'definition_version' => $evidence->definitionVersion,
                        'logic_version' => self::LOGIC_VERSION,
                        'thresholds' => [
                            'sharers' => self::SHARER_THRESHOLD,
                            'participants' => self::PARTICIPANT_THRESHOLD,
                            'delay_days' => self::DELAY_DAYS,
                        ],
                    ]),
                    'sharer_count' => $evidence->distinctSharers,
                    'participant_count' => $evidence->distinctParticipants,
                    'published_by_type' => $actorType,
                    'published_by_id' => $actorId,
                    'published_by_role' => $publisherRole,
                    'published_at' => $decided,
                ]);
            });
        } catch (QueryException $exception) {
            // A concurrent publisher won the unique month insert. Resolve the
            // winner after the failed transaction has rolled back.
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            $winner = CommunityRelease::query()->where('release_key', $releaseKey)->first();

            if (! $winner instanceof CommunityRelease) {
                throw $exception;
            }

            return $winner;
        }
    }

    private function verifiedPublisherRole(string $actorType, ?string $actorId): string
    {
        if ($actorType !== 'user' || $actorId === null || $actorId === '') {
            throw new InvalidArgumentException('Community releases require a staff/admin user identity.');
        }

        if (! $this->accounts->exists($actorId)) {
            throw new InvalidArgumentException('Community releases require a staff/admin user identity.');
        }

        $roles = $this->accounts->roles($actorId);

        if (! in_array('super_admin', $roles, true) && ! in_array('admin', $roles, true)) {
            throw new InvalidArgumentException('Community releases require a staff/admin user identity.');
        }

        return in_array('super_admin', $roles, true) ? 'super_admin' : 'admin';
    }

    /**
     * @return array<string, mixed>
     */
    private function suppressedCells(ShareOutcomeEvidence $evidence): array
    {
        $byChannel = $this->dualGatedCut($evidence->byChannel);
        $byOutcome = $this->dualGatedCut($evidence->byOutcome);

        return [
            'total_outcomes' => $evidence->outcomeCount,
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
     * A cut is published only when every cell meets both distinct-person
     * thresholds, preventing a sparse complement from being inferred.
     *
     * @param array<string, array{outcomes: int, sharers: int, participants: int}> $cells
     * @return array<string, int>|null
     */
    private function dualGatedCut(array $cells): ?array
    {
        if ($cells === []) {
            return null;
        }

        $published = [];

        foreach ($cells as $cell => $evidence) {
            if ($evidence['sharers'] < self::SHARER_THRESHOLD
                || $evidence['participants'] < self::PARTICIPANT_THRESHOLD) {
                return null;
            }

            $published[$cell] = $evidence['outcomes'];
        }

        return $published;
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array((string) ($exception->errorInfo[0] ?? $exception->getCode()), ['23000', '23505'], true);
    }
}
