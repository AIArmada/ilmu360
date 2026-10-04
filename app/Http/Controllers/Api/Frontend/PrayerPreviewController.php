<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Frontend;

use App\Actions\Prayer\BuildPrayerPreviewAction;
use App\Http\Requests\Api\PrayerPreviewRequest;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class PrayerPreviewController extends FrontendController
{
    public function __construct(
        private readonly BuildPrayerPreviewAction $preview,
    ) {}

    #[QueryParameter('country', 'ISO2 country code (default MY).', required: false, type: 'string', infer: false)]
    #[QueryParameter('date', 'Event-local date (Y-m-d).', required: true, type: 'string', infer: false)]
    #[QueryParameter('timezone', 'IANA timezone (defaults to Asia/Kuala_Lumpur for MY).', required: false, type: 'string', infer: false)]
    #[QueryParameter('zone', 'Explicit prayer zone code (validated against the country resolver).', required: false, type: 'string', infer: false)]
    #[QueryParameter('district', 'District/area name for offline zone matching.', required: false, type: 'string', infer: false)]
    #[QueryParameter('lat', 'Venue latitude for zone resolution.', required: false, type: 'number', infer: false)]
    #[QueryParameter('lng', 'Venue longitude for zone resolution.', required: false, type: 'number', infer: false)]
    #[QueryParameter('state', 'Package state code for the default zone (e.g. 10 for Selangor).', required: false, type: 'string', infer: false)]
    #[Endpoint(
        title: 'Preview prayer-derived start clocks',
        description: 'Returns estimated start clocks per prayer label for a date and location. Exact when provider data is available, hardcoded estimates otherwise. Never blocks on failure.',
    )]
    public function __invoke(PrayerPreviewRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $district = isset($validated['district']) ? trim((string) $validated['district']) : '';

            $data = $this->preview->handle(
                country: (string) ($validated['country'] ?? 'MY'),
                date: (string) $validated['date'],
                timezone: isset($validated['timezone']) ? (string) $validated['timezone'] : null,
                zone: isset($validated['zone']) ? (string) $validated['zone'] : null,
                latitude: isset($validated['lat']) ? (float) $validated['lat'] : null,
                longitude: isset($validated['lng']) ? (float) $validated['lng'] : null,
                stateCode: isset($validated['state']) ? (string) $validated['state'] : null,
                districtCandidates: $district !== '' ? [$district] : [],
            );
        } catch (InvalidArgumentException) {
            return response()->json([
                'message' => 'Unknown prayer zone code.',
                'errors' => ['zone' => ['Unknown prayer zone code.']],
            ], 422);
        }

        return response()->json(['data' => $data], 200, [
            'X-Prayer-Source' => $data['source'],
            'X-Prayer-Fetched-At' => $data['fetched_at'] ?? '',
        ]);
    }
}
