<?php

declare(strict_types=1);

namespace App\Support\Share;

use AIArmada\Affiliates\Models\AffiliateTouchpoint;
use AIArmada\Signals\Contracts\SignalEventIngestor;
use AIArmada\Signals\Models\SignalEvent;
use AIArmada\Signals\Models\TrackedProperty;
use App\Support\ShareTracking\ShareTrackingMeasurement;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * THE committed-share Signals producer: the one named producer of
 * `dawah.committed_share` trusted Signals.
 *
 * Authority is the committed OUTBOUND share (an outbound_share
 * touchpoint row) — never downstream outcome receipts. Each committed
 * share produces exactly one trusted signal through the installed
 * Signals package ingestor under the semantic key
 * `committed-share:{touchpoint_id}`; the package deduplicates replays
 * to the same event, so retries and recovery apply once. Payloads are
 * coarse (channel, cohort week, occurred day, definition version) — no
 * actor ids, raw URLs, or subject detail.
 *
 * This producer records ONLY outbound shares. The package's own
 * attribution/conversion listeners record different named events and
 * are left untouched: no blind generic duplicate affiliate producer.
 *
 * Production runs through ProduceCommittedShareSignalJob after commit,
 * so a Signals outage retries the job and can never roll back the
 * already-committed share. A missing tracked property fails loudly
 * (job retries after the operator configures it) — signals are never
 * silently dropped.
 */
final readonly class CommittedShareSignalProducer
{
    public const string EVENT_NAME = 'dawah.committed_share';

    public const string EVENT_CATEGORY = 'shares';

    public const string TOUCHPOINT_TYPE = 'outbound_share';

    public function __construct(
        private SignalEventIngestor $ingestor,
    ) {}

    public static function semanticKey(string $touchpointId): string
    {
        if ($touchpointId === '' || mb_strlen($touchpointId) > 150) {
            throw new InvalidArgumentException('Touchpoint id must be 1..150 characters.');
        }

        return 'committed-share:'.$touchpointId;
    }

    public function produce(AffiliateTouchpoint|string $touchpoint): SignalEvent
    {
        $row = $touchpoint instanceof AffiliateTouchpoint
            ? $touchpoint
            : AffiliateTouchpoint::query()->whereKey($touchpoint)->first();

        if (! $row instanceof AffiliateTouchpoint) {
            throw new InvalidArgumentException('Unknown share touchpoint.');
        }

        if ((string) $row->touchpoint_type !== self::TOUCHPOINT_TYPE) {
            throw new InvalidArgumentException('Committed-share signals cover outbound shares only.');
        }

        $property = TrackedProperty::query()
            ->withoutOwnerScope()
            ->whereNull('owner_type')
            ->whereNull('owner_id')
            ->where('slug', (string) config('signals.integrations.browser.tracked_property.slug', 'ilmu360'))
            ->first();

        if (! $property instanceof TrackedProperty) {
            throw new RuntimeException('No tracked property is configured for committed-share signals.');
        }

        $key = self::semanticKey((string) $row->getKey());
        $touched = CarbonImmutable::parse($row->touched_at)->setTimezone('UTC');

        $properties = [
            'cohort_week' => $touched->startOfWeek()->toDateString(),
            'occurred_day' => $touched->toDateString(),
            'definition_version' => ShareTrackingMeasurement::definitionVersion(),
        ];

        $channel = $row->channel;

        if (is_string($channel) && $channel !== '') {
            $properties['channel'] = $channel;
        }

        return $this->ingestor->handle($property, [
            'event_name' => self::EVENT_NAME,
            'event_category' => self::EVENT_CATEGORY,
            'source_event_id' => $key,
            'idempotency_key' => $key,
            'occurred_at' => $touched->toIso8601String(),
            'properties' => $properties,
        ], true);
    }
}
