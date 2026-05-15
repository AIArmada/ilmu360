<?php

namespace App\Http\Controllers\Api;

use App\Data\Api\UserRegistration\UserRegistrationItemData;
use App\Http\Controllers\Controller;
use App\Models\Registration;
use App\Support\Api\ApiPagination;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

#[Group('UserRegistration', 'Authenticated user registration-history endpoints for the current bearer-token holder.')]
class UserRegistrationController extends Controller
{
    /**
     * List registrations for the authenticated user.
     */
    #[Endpoint(
        title: 'List the current user registrations',
        description: 'Returns the authenticated user\'s event registrations with pagination metadata and related event context.',
    )]
    public function index(Request $request): JsonResponse
    {
        $registrations = $request->user()
            ->registrations()
            ->with([
                'event' => fn ($query) => $query
                    ->select('id', 'title', 'slug', 'starts_at', 'status', 'visibility', 'institution_id', 'venue_id')
                    ->with([
                        'institution:id,name,slug',
                        'venue:id,name',
                    ]),
            ])
            ->latest()
            ->paginate(ApiPagination::normalizePerPage($request->integer('per_page', 20), default: 20, max: 100));

        return response()->json([
            'data' => collect($registrations->items())
                ->map(fn (Registration $registration): array => UserRegistrationItemData::fromModel($registration)->toArray())
                ->all(),
            'meta' => [
                'request_id' => $request->header('X-Request-ID', (string) Str::uuid()),
                'pagination' => [
                    'page' => $registrations->currentPage(),
                    'per_page' => $registrations->perPage(),
                    'total' => $registrations->total(),
                ],
            ],
        ]);
    }
}
