<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Frontend;

use App\Actions\Signals\RecordMobileTelemetryBatchAction;
use App\Http\Requests\Api\StoreMobileTelemetryRequest;
use App\Models\User;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Telemetry', 'Native mobile telemetry endpoints for batching UI interaction events from real iOS, iPadOS, and Android clients. This surface is not for mobile web browsing; browser sessions should continue using the web signals tracker.')]
class MobileTelemetryController extends FrontendController
{
    #[Endpoint(
        title: 'Record native mobile telemetry',
        description: 'Accepts batched UI interaction telemetry from real iOS, iPadOS, and Android app sessions. This endpoint is not for mobile web browsing. Send `X-Ilmu360-Client-Origin` with a native mobile origin, and include `anonymous_id or session_identifier` whenever the request is anonymous.',
    )]
    public function store(
        StoreMobileTelemetryRequest $request,
        RecordMobileTelemetryBatchAction $recordMobileTelemetryBatchAction,
    ): JsonResponse {
        $result = $recordMobileTelemetryBatchAction->handle(
            request: $request,
            user: $request->actor(),
            anonymousId: $request->anonymousId(),
            sessionIdentifier: $request->sessionIdentifier(),
            sessionStartedAt: $request->sessionStartedAt(),
            events: $request->eventsPayload(),
        );

        return response()->json([
            'message' => 'Mobile telemetry accepted.',
            'data' => [
                'received_events' => $result['received_events'],
                'recorded_events' => $result['recorded_events'],
                'dropped_events' => $result['dropped_events'],
                'authenticated' => $request->actor() instanceof User,
                'client' => $result['client'],
            ],
            'meta' => [
                'request_id' => $this->requestId($request),
            ],
        ], 202);
    }
}
