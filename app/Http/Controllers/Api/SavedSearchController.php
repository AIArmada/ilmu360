<?php

namespace App\Http\Controllers\Api;

use App\Actions\Events\Search\ExecuteSavedSearchAction;
use App\Actions\SavedSearches\CreateSavedSearchAction;
use App\Actions\SavedSearches\DeleteSavedSearchWithAttributionReversalAction;
use App\Features\SavedSearches\Api\SavedSearchCommands;
use App\Features\SavedSearches\Api\SavedSearchReader;
use App\Features\SavedSearches\Api\SavedSearchSnapshot;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Shared\Discovery\SearchFilterRules;
use App\Shared\Integrations\SavedSearchLimitReachedException;
use App\Support\Api\ApiPagination;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

#[Group('Saved Search', 'Authenticated saved-search CRUD and execution endpoints for event discovery workflows.')]
class SavedSearchController extends Controller
{
    /**
     * List all saved searches for the authenticated user.
     */
    #[Endpoint(
        title: 'List saved searches',
        description: 'Returns the authenticated user\'s saved search definitions for event discovery.',
    )]
    public function index(): JsonResponse
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            abort(401);
        }

        $savedSearches = app(SavedSearchReader::class)->forOwner($user->getMorphClass(), (string) $user->getKey());

        return response()->json([
            'data' => array_map(static fn (SavedSearchSnapshot $savedSearch): array => $savedSearch->payload, $savedSearches),
        ]);
    }

    /**
     * Create a new saved search.
     */
    #[BodyParameter('name', 'Human-readable label for the saved search.', type: 'string', infer: false, example: 'Kuliah Maghrib Kuala Lumpur')]
    #[BodyParameter('query', 'Free-text event discovery query.', required: false, type: 'string', infer: false, example: 'muamalat')]
    #[BodyParameter('filters', 'Canonical saved-search filters keyed by event discovery fields.', required: false, type: 'object', infer: false, example: [
        'language_codes' => ['ms'],
        'event_category_ids' => [],
        'age_group' => ['all_ages'],
        'starts_on_local_date' => '2026-02-01',
    ])]
    #[BodyParameter('radius_km', 'Optional search radius in kilometers when latitude and longitude are provided.', required: false, type: 'integer', infer: false, example: 25)]
    #[BodyParameter('lat', 'Latitude anchor used with `radius_km`.', required: false, type: 'number', infer: false, example: 3.139)]
    #[BodyParameter('lng', 'Longitude anchor used with `radius_km`.', required: false, type: 'number', infer: false, example: 101.6869)]
    #[BodyParameter('notify', 'Notification cadence for saved-search alerts.', type: 'string', infer: false, example: 'daily')]
    #[Endpoint(
        title: 'Create a saved search',
        description: 'Creates a new saved search definition from the supplied event-query payload.',
    )]
    public function store(
        Request $request,
        CreateSavedSearchAction $createSavedSearchAction,
        SearchFilterRules $searchFilterRules,
    ): JsonResponse {
        $validated = $request->validate($this->savedSearchValidationRules($searchFilterRules, $request->input('filters')));

        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        try {
            $savedSearch = $createSavedSearchAction->handle($user, $validated, $request);
        } catch (SavedSearchLimitReachedException $exception) {
            return response()->json([
                'message' => 'Maximum saved searches limit reached ('.$exception->maximum.').',
                'error' => [
                    'code' => 'validation_error',
                    'message' => 'Limit reached',
                ],
            ], 422);
        }

        return response()->json([
            'message' => 'Saved search created.',
            'data' => $savedSearch->payload,
        ], 201);
    }

    /**
     * Get a specific saved search.
     */
    #[Endpoint(
        title: 'Get a saved search',
        description: 'Returns one saved search definition owned by the current authenticated user.',
    )]
    #[PathParameter('savedSearch', 'The saved search ID', type: 'string', format: 'uuid', infer: false)]
    public function show(SavedSearchSnapshot $savedSearch): JsonResponse
    {
        $this->authorize('view', $savedSearch);

        return response()->json([
            'data' => $savedSearch->payload,
        ]);
    }

    /**
     * Update a saved search.
     */
    #[BodyParameter('name', 'Updated human-readable label for the saved search.', required: false, type: 'string', infer: false, example: 'Kuliah Maghrib KL')]
    #[BodyParameter('query', 'Updated free-text event discovery query.', required: false, type: 'string', infer: false, example: 'forum')]
    #[BodyParameter('filters', 'Updated canonical saved-search filters keyed by event discovery fields.', required: false, type: 'object', infer: false, example: [
        'language_codes' => ['ms', 'en'],
        'event_category_ids' => [],
        'age_group' => ['youth'],
        'starts_on_local_date' => '2026-02-01',
    ])]
    #[BodyParameter('radius_km', 'Updated search radius in kilometers when latitude and longitude are provided.', required: false, type: 'integer', infer: false, example: 25)]
    #[BodyParameter('lat', 'Updated latitude anchor used with `radius_km`.', required: false, type: 'number', infer: false, example: 3.139)]
    #[BodyParameter('lng', 'Updated longitude anchor used with `radius_km`.', required: false, type: 'number', infer: false, example: 101.6869)]
    #[BodyParameter('notify', 'Updated notification cadence for saved-search alerts.', required: false, type: 'string', infer: false, example: 'instant')]
    #[Endpoint(
        title: 'Update a saved search',
        description: 'Updates one saved search definition owned by the current authenticated user.',
    )]
    #[PathParameter('savedSearch', 'The saved search ID', type: 'string', format: 'uuid', infer: false)]
    public function update(
        Request $request,
        SavedSearchSnapshot $savedSearch,
        SavedSearchCommands $savedSearchCommands,
        SearchFilterRules $searchFilterRules,
    ): JsonResponse {
        $this->authorize('update', $savedSearch);

        $validated = $request->validate($this->savedSearchValidationRules($searchFilterRules, $request->input('filters'), partial: true));

        $savedSearch = $savedSearchCommands->update($savedSearch->userType, $savedSearch->userId, $savedSearch->id, $validated);

        return response()->json([
            'message' => 'Saved search updated.',
            'data' => $savedSearch->payload,
        ]);
    }

    /**
     * Delete a saved search.
     */
    #[Endpoint(
        title: 'Delete a saved search',
        description: 'Deletes one saved search definition owned by the current authenticated user.',
    )]
    #[PathParameter('savedSearch', 'The saved search ID', type: 'string', format: 'uuid', infer: false)]
    public function destroy(SavedSearchSnapshot $savedSearch, DeleteSavedSearchWithAttributionReversalAction $delete): Response
    {
        $this->authorize('delete', $savedSearch);

        $delete->handle($savedSearch, request());

        return response()->noContent();
    }

    /**
     * Execute a saved search and return results.
     */
    #[Endpoint(
        title: 'Execute a saved search',
        description: 'Executes a stored saved-search definition and returns the current matching event results.',
    )]
    #[PathParameter('savedSearch', 'The saved search ID', type: 'string', format: 'uuid', infer: false)]
    public function execute(SavedSearchSnapshot $savedSearch, ExecuteSavedSearchAction $executeSavedSearchAction): JsonResponse
    {
        $this->authorize('view', $savedSearch);

        $events = $executeSavedSearchAction->handle($savedSearch, request());

        return response()->json([
            'data' => $events->items(),
            'meta' => [
                'pagination' => ApiPagination::paginationMeta($events),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function savedSearchValidationRules(
        SearchFilterRules $searchFilterRules,
        mixed $filters,
        bool $partial = false,
    ): array {
        return [
            'name' => $partial ? 'sometimes|required|string|max:100' : 'required|string|max:100',
            'query' => 'nullable|string|max:255',
            ...$searchFilterRules->rules($filters),
            'radius_km' => 'nullable|integer|min:1|max:1000',
            'lat' => $partial ? 'nullable|numeric|between:-90,90' : 'nullable|required_with:radius_km|numeric|between:-90,90',
            'lng' => $partial ? 'nullable|numeric|between:-180,180' : 'nullable|required_with:radius_km|numeric|between:-180,180',
            'notify' => $partial
                ? ['sometimes', 'required', Rule::in(['off', 'instant', 'daily', 'weekly'])]
                : ['required', Rule::in(['off', 'instant', 'daily', 'weekly'])],
        ];
    }
}
