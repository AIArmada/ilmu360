<?php

declare(strict_types=1);

namespace App\Support\Share;

use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateTouchpoint;
use AIArmada\Signals\Models\SignalEvent;
use App\Models\ShareCohortProjection;
use App\Models\ShareSignalReaderCursor;
use App\Models\ShareSignalReaderSkip;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * The committed-share Signals reader: the actual reader of the package's
 * durable `dawah.committed_share` signal log.
 *
 * Reads trusted committed-share events strictly after its own durable
 * cursor and applies the final effect — an idempotent cohort-projection
 * upsert after re-verifying the underlying committed touchpoint still
 * exists. Each event's projection + cursor advance commits atomically,
 * so a crash resumes exactly after the last completed event: committed
 * work recovers, nothing double-applies, nothing silently stalls.
 *
 * Late arrivals (an old share ingested after a newer event already
 * advanced the cursor, or a transaction committing out of order) can
 * never be permanently skipped: every selection first sweeps the
 * retained log behind the cursor for matching events with no
 * projection and no poison skip, bounded to one row ordered by the
 * original occurrence time. Backlog completions never move the
 * high-water backwards — the projection (or skip) row itself is the
 * completion marker — while forward events keep the cursor fencing.
 *
 * There are deliberately NO leases: concurrent readers serialize on
 * the cursor row, projection writes are idempotent, and the cursor
 * only ever advances forward — a stale worker cannot overwrite a
 * recovered worker's state because there is no lease state to clobber.
 * Poison events (unparseable keys, vanished sources) are reported and
 * counted, then skipped past: the immutable signal log retains them
 * for operator replay via resetCursor(), and one bad event never
 * wedges the pipeline.
 *
 * This reader owns ONLY its event name and its cursor row. It never
 * touches share effect receipts (the generic dispatcher's domain).
 */
final class CommittedShareSignalReader
{
    public const string READER_NAME = 'committed-share-cohort';

    /**
     * @return array{processed: int, projected: int, failed: int}
     */
    public function drain(int $batch = 25): array
    {
        $batch = max(1, min($batch, 100));
        $processed = 0;
        $projected = 0;
        $failed = 0;

        for ($i = 0; $i < $batch; $i++) {
            $event = $this->nextEvent();

            if (! $event instanceof SignalEvent) {
                break;
            }

            $processed++;

            try {
                if ($this->apply($event)) {
                    $projected++;
                }
            } catch (Throwable $throwable) {
                report($throwable);
                $this->skipPast($event);
                $failed++;
            }
        }

        return ['processed' => $processed, 'projected' => $projected, 'failed' => $failed];
    }

    /**
     * Operator replay: rewinds the cursor so the next drain re-reads
     * the retained signal log from the start. Projections are
     * idempotent, so replaying applies nothing twice. Poison skips
     * are cleared so retained poison is retried once against
     * recovered sources.
     */
    public function resetCursor(): void
    {
        $this->fenced(function (ShareSignalReaderCursor $cursor): void {
            $cursor->forceFill([
                'last_occurred_at' => null,
                'last_signal_event_id' => null,
                'processed_total' => 0,
            ])->save();

            ShareSignalReaderSkip::query()->where('reader_name', self::READER_NAME)->delete();
        });
    }

    private function nextEvent(): ?SignalEvent
    {
        return $this->fenced(function (ShareSignalReaderCursor $cursor): ?SignalEvent {
            $backlog = $this->nextBacklogEvent($cursor);

            if ($backlog instanceof SignalEvent) {
                return $backlog;
            }

            return $this->nextForwardEvent($cursor);
        });
    }

    private function nextForwardEvent(ShareSignalReaderCursor $cursor): ?SignalEvent
    {
        $query = SignalEvent::query()
            ->withoutOwnerScope()
            ->where('event_name', CommittedShareSignalProducer::EVENT_NAME)
            ->where('ingestion_source', SignalEvent::INGESTION_SOURCE_TRUSTED);

        $at = $cursor->last_occurred_at;
        $id = $cursor->last_signal_event_id;

        if ($at !== null && $id !== null) {
            $query->where(function ($position) use ($at, $id): void {
                $position->where('occurred_at', '>', $at)
                    ->orWhere(function ($tie) use ($at, $id): void {
                        $tie->where('occurred_at', '=', $at)
                            ->where('id', '>', $id);
                    });
            });
        }

        return $query->orderBy('occurred_at')->orderBy('id')->first();
    }

    /**
     * Bounded sweep for late arrivals behind the high-water: the
     * earliest matching retained event with no cohort projection and
     * no poison skip. Returns null when caught up or when the cursor
     * is empty (everything is forward). One row, occurrence-ordered,
     * so out-of-order commits and backdated recovery can never hide
     * behind the cursor.
     */
    private function nextBacklogEvent(ShareSignalReaderCursor $cursor): ?SignalEvent
    {
        $at = $cursor->last_occurred_at;
        $id = $cursor->last_signal_event_id;

        if ($at === null || $id === null) {
            return null;
        }

        $events = (new SignalEvent)->getTable();
        $projections = (new ShareCohortProjection)->getTable();
        $skips = (new ShareSignalReaderSkip)->getTable();
        $grammar = DB::getQueryGrammar();
        $projectionKey = $grammar->wrap($projections.'.business_key');
        $idempotencyKey = $grammar->wrap($events.'.idempotency_key');
        $sourceEventId = $grammar->wrap($events.'.source_event_id');

        return SignalEvent::query()
            ->withoutOwnerScope()
            ->where('event_name', CommittedShareSignalProducer::EVENT_NAME)
            ->where('ingestion_source', SignalEvent::INGESTION_SOURCE_TRUSTED)
            ->where(function ($position) use ($at, $id): void {
                $position->where('occurred_at', '<', $at)
                    ->orWhere(function ($tie) use ($at, $id): void {
                        $tie->where('occurred_at', '=', $at)
                            ->where('id', '<', $id);
                    });
            })
            ->whereNotExists(function ($exists) use ($projections, $projectionKey, $idempotencyKey, $sourceEventId): void {
                $exists->select(DB::raw('1'))
                    ->from($projections)
                    ->whereRaw("{$projectionKey} = COALESCE({$idempotencyKey}, {$sourceEventId})");
            })
            ->whereNotExists(function ($exists) use ($skips, $events): void {
                $exists->select(DB::raw('1'))
                    ->from($skips)
                    ->where($skips.'.reader_name', self::READER_NAME)
                    ->whereColumn($skips.'.signal_event_id', $events.'.id');
            })
            ->orderBy('occurred_at')->orderBy('id')->first();
    }

    /**
     * Applies one event and advances the cursor atomically. Returns
     * false when a concurrent reader already completed the event.
     */
    private function apply(SignalEvent $event): bool
    {
        return $this->fenced(function (ShareSignalReaderCursor $cursor) use ($event): bool {
            if ($this->isAfterCursor($cursor, $event)) {
                $touchpointId = $this->touchpointIdFor($event);

                // Recheck: the committed touchpoint must still exist and
                // still be an outbound share; a vanished or repurposed
                // source fails loudly instead of projecting a ghost.
                $source = AffiliateTouchpoint::query()->whereKey($touchpointId)->first();

                if (! $source instanceof AffiliateTouchpoint
                    || (string) $source->touchpoint_type !== CommittedShareSignalProducer::TOUCHPOINT_TYPE
                ) {
                    throw new InvalidArgumentException("Committed-share source [{$touchpointId}] is missing.");
                }

                $this->projectOnce($event, $source);
                $this->advanceCursor($cursor, $event);

                return true;
            }

            // Backlog path: behind the high-water, so the projection (or
            // skip) row is the completion marker — the cursor never moves
            // backwards. A concurrent winner shows up as an existing
            // projection or skip, and the loser returns false.
            if ($this->isSkipped($event)) {
                return false;
            }

            $key = $this->semanticKeyFor($event);

            if ($key !== null && ShareCohortProjection::query()->where('business_key', $key)->exists()) {
                return false;
            }

            $touchpointId = $this->touchpointIdFor($event);

            $source = AffiliateTouchpoint::query()->whereKey($touchpointId)->first();

            if (! $source instanceof AffiliateTouchpoint
                || (string) $source->touchpoint_type !== CommittedShareSignalProducer::TOUCHPOINT_TYPE
            ) {
                throw new InvalidArgumentException("Committed-share source [{$touchpointId}] is missing.");
            }

            $this->projectOnce($event, $source);
            $this->incrementProcessedTotal($cursor);

            return true;
        });
    }

    private function skipPast(SignalEvent $event): void
    {
        $this->fenced(function (ShareSignalReaderCursor $cursor) use ($event): void {
            if ($this->isAfterCursor($cursor, $event)) {
                // Forward poison: remember the skip so a later backlog
                // sweep never resurrects it, then advance past it.
                $this->recordSkip($event);
                $this->advanceCursor($cursor, $event);

                return;
            }

            // Backlog poison: the skip row is the completion marker;
            // the high-water never moves backwards.
            if ($this->recordSkip($event)) {
                $this->incrementProcessedTotal($cursor);
            }
        });
    }

    /**
     * Runs the work fenced on the cursor row. The row is created with
     * insert-or-ignore OUTSIDE the fencing transaction: catching a
     * duplicate-key failure inside the transaction would abort it on
     * PostgreSQL and desynchronize it in the database, so two concurrent
     * drains racing first initialization must never take that path.
     * The loser of the insert race simply locks the winner's row.
     */
    private function fenced(callable $work): mixed
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->ensureCursorRowExists();

            try {
                return DB::transaction(function () use ($work): mixed {
                    $cursor = ShareSignalReaderCursor::query()
                        ->where('reader_name', self::READER_NAME)
                        ->lockForUpdate()
                        ->first();

                    if (! $cursor instanceof ShareSignalReaderCursor) {
                        throw new CommittedShareCursorRowMissingException;
                    }

                    return $work($cursor);
                });
            } catch (CommittedShareCursorRowMissingException) {
                // The row was deleted mid-drain (operator action only:
                // readers never delete cursors). Re-ensure and retry.
                continue;
            }
        }

        throw new RuntimeException('Committed-share reader cursor vanished repeatedly.');
    }

    private function ensureCursorRowExists(): void
    {
        $now = CarbonImmutable::now('UTC');

        ShareSignalReaderCursor::query()->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'reader_name' => self::READER_NAME,
            'processed_total' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function isAfterCursor(ShareSignalReaderCursor $cursor, SignalEvent $event): bool
    {
        $at = $cursor->last_occurred_at;

        if ($at === null || $cursor->last_signal_event_id === null) {
            return true;
        }

        $occurred = CarbonImmutable::parse($event->occurred_at)->setTimezone('UTC');
        $cursorAt = CarbonImmutable::parse($at)->setTimezone('UTC');

        if ($occurred->greaterThan($cursorAt)) {
            return true;
        }

        if ($occurred->lessThan($cursorAt)) {
            return false;
        }

        return strcmp((string) $event->getKey(), (string) $cursor->last_signal_event_id) > 0;
    }

    /**
     * The cursor only ever advances forward: a stale reader holding an
     * older event can never drag the position backwards.
     */
    private function advanceCursor(ShareSignalReaderCursor $cursor, SignalEvent $event): void
    {
        if (! $this->isAfterCursor($cursor, $event)) {
            return;
        }

        $cursor->forceFill([
            'last_occurred_at' => CarbonImmutable::parse($event->occurred_at)->setTimezone('UTC'),
            'last_signal_event_id' => (string) $event->getKey(),
            'processed_total' => ((int) $cursor->processed_total) + 1,
        ])->save();
    }

    private function incrementProcessedTotal(ShareSignalReaderCursor $cursor): void
    {
        $cursor->forceFill([
            'processed_total' => ((int) $cursor->processed_total) + 1,
        ])->save();
    }

    private function isSkipped(SignalEvent $event): bool
    {
        return ShareSignalReaderSkip::query()
            ->where('reader_name', self::READER_NAME)
            ->where('signal_event_id', (string) $event->getKey())
            ->exists();
    }

    /**
     * Records one poison skip. Returns true when this call inserted
     * the row; a concurrent winner's row makes the loser return
     * false so processed totals increment exactly once.
     */
    private function recordSkip(SignalEvent $event): bool
    {
        $now = CarbonImmutable::now('UTC');

        return ShareSignalReaderSkip::query()->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'reader_name' => self::READER_NAME,
            'signal_event_id' => (string) $event->getKey(),
            'created_at' => $now,
            'updated_at' => $now,
        ]) > 0;
    }

    /**
     * The semantic business key for projection matching, or null when
     * the signal carries no parseable key (poison: never projected).
     */
    private function semanticKeyFor(SignalEvent $event): ?string
    {
        $key = $event->idempotency_key ?? $event->source_event_id;

        if (! is_string($key) || ! str_starts_with($key, 'committed-share:')) {
            return null;
        }

        $id = substr($key, strlen('committed-share:'));

        if ($id === '' || mb_strlen($id) > 150) {
            return null;
        }

        return $key;
    }

    private function touchpointIdFor(SignalEvent $event): string
    {
        $key = $event->idempotency_key ?? $event->source_event_id;

        if (! is_string($key) || ! str_starts_with($key, 'committed-share:')) {
            throw new InvalidArgumentException('Committed-share signal carries no semantic key.');
        }

        $id = substr($key, strlen('committed-share:'));

        if ($id === '' || mb_strlen($id) > 150) {
            throw new InvalidArgumentException('Committed-share signal key is malformed.');
        }

        return $id;
    }

    private function projectOnce(SignalEvent $event, AffiliateTouchpoint $source): void
    {
        $key = 'committed-share:'.((string) $source->getKey());

        if (ShareCohortProjection::query()->where('business_key', $key)->exists()) {
            return;
        }

        $properties = $event->properties;
        $properties = is_array($properties) ? $properties : [];
        $occurred = CarbonImmutable::parse($event->occurred_at)->setTimezone('UTC');

        $participated = AffiliateConversion::query()
            ->where('affiliate_link_id', (string) $source->affiliate_link_id)
            ->whereIn('status', ShareMetricReader::VALID_STATUSES)
            ->exists();

        // Insert-or-ignore, never catch-and-continue: a duplicate-key
        // failure caught inside this transaction would abort it on
        // PostgreSQL. The cursor fence above already serializes readers,
        // so a loser here means an operator replayed the log while a
        // drain ran — the winner's row IS the projection either way.
        ShareCohortProjection::query()->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'business_key' => $key,
            'outcome' => 'committed_share',
            'cohort_week' => (string) ($properties['cohort_week'] ?? $occurred->startOfWeek()->toDateString()),
            'stage' => $participated
                ? ShareCohortProjection::STAGE_PARTICIPATED
                : ShareCohortProjection::STAGE_OBSERVED,
            'occurred_at' => $occurred,
            'projected_at' => CarbonImmutable::now('UTC'),
            'created_at' => CarbonImmutable::now('UTC'),
            'updated_at' => CarbonImmutable::now('UTC'),
        ]);
    }
}

/**
 * Internal control signal: the cursor row vanished mid-transaction.
 * Never escapes the reader; the fenced attempt re-ensures and retries.
 *
 * @internal
 */
final class CommittedShareCursorRowMissingException extends RuntimeException {}
