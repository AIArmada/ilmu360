<?php

declare(strict_types=1);

namespace App\Actions\Signals;

use AIArmada\Signals\Models\TrackedProperty;
use App\Models\User;
use App\Services\Signals\SignalEventRecorder;
use App\Services\Signals\SignalsTracker;
use App\Support\Signals\ProductSignalsClientContext;
use Illuminate\Http\Request;
use Throwable;

final readonly class RecordMobileTelemetryBatchAction
{
    public function __construct(
        private SignalEventRecorder $signalEventRecorder,
        private SignalsTracker $signalsTracker,
        private ProductSignalsClientContext $clientContext,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $events
     * @return array{client: array<string, string>, dropped_events: int, received_events: int, recorded_events: int}
     */
    public function handle(
        Request $request,
        ?User $user,
        ?string $anonymousId,
        ?string $sessionIdentifier,
        ?string $sessionStartedAt,
        array $events,
    ): array {
        $receivedEvents = count($events);
        $clientProperties = $this->clientContext->properties($request);
        $trackedProperty = $this->signalsTracker->defaultTrackedProperty();

        if (! $trackedProperty instanceof TrackedProperty) {
            logger()->warning('Mobile telemetry accepted without a configured tracked property.', [
                'route_name' => $request->route()?->getName(),
                'received_events' => $receivedEvents,
                'user_id' => $user?->getKey(),
            ]);

            return [
                'client' => $clientProperties,
                'dropped_events' => $receivedEvents,
                'received_events' => $receivedEvents,
                'recorded_events' => 0,
            ];
        }

        $recordedEvents = 0;

        foreach ($events as $index => $event) {
            try {
                $this->signalEventRecorder->ingest($trackedProperty, $this->payloadForEvent(
                    request: $request,
                    user: $user,
                    anonymousId: $anonymousId,
                    sessionIdentifier: $sessionIdentifier,
                    sessionStartedAt: $sessionStartedAt,
                    event: $event,
                    clientProperties: $clientProperties,
                    batchIndex: $index + 1,
                ));

                $recordedEvents++;
            } catch (Throwable $exception) {
                report($exception);
                logger()->warning('Native mobile telemetry event dropped after ingestion failure.', [
                    'route_name' => $request->route()?->getName(),
                    'event_name' => $event['event_name'] ?? null,
                    'batch_index' => $index + 1,
                    'user_id' => $user?->getKey(),
                ]);
            }
        }

        return [
            'client' => $clientProperties,
            'dropped_events' => $receivedEvents - $recordedEvents,
            'received_events' => $receivedEvents,
            'recorded_events' => $recordedEvents,
        ];
    }

    /**
     * @param  array<string, string>  $clientProperties
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function payloadForEvent(
        Request $request,
        ?User $user,
        ?string $anonymousId,
        ?string $sessionIdentifier,
        ?string $sessionStartedAt,
        array $event,
        array $clientProperties,
        int $batchIndex,
    ): array {
        return array_filter([
            'event_name' => $event['event_name'],
            'event_category' => $event['event_category'] ?? null,
            'external_id' => $user?->getKey(),
            'anonymous_id' => $anonymousId,
            'session_identifier' => $sessionIdentifier,
            'session_started_at' => $sessionStartedAt,
            'occurred_at' => $event['occurred_at'] ?? null,
            'path' => $event['path'] ?? null,
            'url' => $event['url'] ?? null,
            'referrer' => $event['referrer'] ?? null,
            'source' => $event['utm_source'] ?? null,
            'medium' => $event['utm_medium'] ?? null,
            'campaign' => $event['utm_campaign'] ?? null,
            'content' => $event['utm_content'] ?? null,
            'term' => $event['utm_term'] ?? null,
            'properties' => $this->eventProperties($request, $event, $clientProperties, $batchIndex),
        ], function (mixed $value): bool {
            if ($value === null || $value === '') {
                return false;
            }

            if (is_array($value)) {
                return $value !== [];
            }

            return true;
        });
    }

    /**
     * @param  array<string, string>  $clientProperties
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function eventProperties(Request $request, array $event, array $clientProperties, int $batchIndex): array
    {
        $routeName = $request->route()?->getName();
        $properties = is_array($event['properties'] ?? null)
            ? $event['properties']
            : [];

        return array_filter([
            'route_name' => is_string($routeName) && $routeName !== '' ? $routeName : null,
            'request_method' => $request->getMethod(),
            'telemetry_channel' => 'native_mobile_api',
            'batch_index' => $batchIndex,
            ...$clientProperties,
            ...$properties,
        ], function (mixed $value): bool {
            if ($value === null || $value === '') {
                return false;
            }

            if (is_array($value)) {
                return $value !== [];
            }

            return true;
        });
    }
}
