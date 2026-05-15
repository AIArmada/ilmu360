<?php

namespace App\Http\Controllers\Api;

use App\Actions\SavedSearches\CreateSavedSearchAction;
use App\Actions\SavedSearches\ExecuteSavedSearchAction;
use App\Actions\SavedSearches\UpdateSavedSearchAction;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventPrayerTime;
use App\Enums\EventType;
use App\Enums\TimingMode;
use App\Exceptions\SavedSearchLimitReachedException;
use App\Http\Controllers\Controller;
use App\Models\SavedSearch;
use App\Models\User;
use App\Support\Api\ApiPagination;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
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
        $savedSearches = SavedSearch::where('user_id', Auth::id())
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => $savedSearches,
        ]);
    }

    /**
     * Create a new saved search.
     */
    #[BodyParameter('name', 'Human-readable label for the saved search.', type: 'string', infer: false, example: 'Kuliah Maghrib Kuala Lumpur')]
    #[BodyParameter('query', 'Free-text event discovery query.', required: false, type: 'string', infer: false, example: 'muamalat')]
    #[BodyParameter('filters', 'Canonical saved-search filters keyed by event discovery fields.', required: false, type: 'object', infer: false, example: [
        'language_codes' => ['ms'],
        'event_type' => ['kuliah_ceramah'],
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
    public function store(Request $request, CreateSavedSearchAction $createSavedSearchAction): JsonResponse
    {
        $validated = $request->validate($this->savedSearchValidationRules());

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
            'data' => $savedSearch,
        ], 201);
    }

    /**
     * Get a specific saved search.
     */
    #[Endpoint(
        title: 'Get a saved search',
        description: 'Returns one saved search definition owned by the current authenticated user.',
    )]
    public function show(SavedSearch $savedSearch): JsonResponse
    {
        $this->authorize('view', $savedSearch);

        return response()->json([
            'data' => $savedSearch,
        ]);
    }

    /**
     * Update a saved search.
     */
    #[BodyParameter('name', 'Updated human-readable label for the saved search.', required: false, type: 'string', infer: false, example: 'Kuliah Maghrib KL')]
    #[BodyParameter('query', 'Updated free-text event discovery query.', required: false, type: 'string', infer: false, example: 'forum')]
    #[BodyParameter('filters', 'Updated canonical saved-search filters keyed by event discovery fields.', required: false, type: 'object', infer: false, example: [
        'language_codes' => ['ms', 'en'],
        'event_type' => ['forum'],
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
    public function update(
        Request $request,
        SavedSearch $savedSearch,
        UpdateSavedSearchAction $updateSavedSearchAction,
    ): JsonResponse {
        $this->authorize('update', $savedSearch);

        $validated = $request->validate($this->savedSearchValidationRules(partial: true));

        $savedSearch = $updateSavedSearchAction->handle($savedSearch, $validated);

        return response()->json([
            'message' => 'Saved search updated.',
            'data' => $savedSearch,
        ]);
    }

    /**
     * Delete a saved search.
     */
    #[Endpoint(
        title: 'Delete a saved search',
        description: 'Deletes one saved search definition owned by the current authenticated user.',
    )]
    public function destroy(SavedSearch $savedSearch): Response
    {
        $this->authorize('delete', $savedSearch);

        $savedSearch->delete();

        return response()->noContent();
    }

    /**
     * Execute a saved search and return results.
     */
    #[Endpoint(
        title: 'Execute a saved search',
        description: 'Executes a stored saved-search definition and returns the current matching event results.',
    )]
    public function execute(SavedSearch $savedSearch, ExecuteSavedSearchAction $executeSavedSearchAction): JsonResponse
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
    private function savedSearchValidationRules(bool $partial = false): array
    {
        return [
            'name' => $partial ? 'sometimes|required|string|max:100' : 'required|string|max:100',
            'query' => 'nullable|string|max:255',
            'filters' => 'nullable|array:'.implode(',', $this->allowedFilterKeys()),
            'filters.country_id' => 'nullable|integer|exists:countries,id',
            'filters.state_id' => 'nullable|integer|exists:states,id',
            'filters.district_id' => 'nullable|integer|exists:districts,id',
            'filters.subdistrict_id' => 'nullable|integer|exists:subdistricts,id',
            'filters.institution_id' => 'nullable|uuid|exists:institutions,id',
            'filters.venue_id' => 'nullable|uuid|exists:venues,id',
            'filters.language_codes' => 'nullable|array',
            'filters.language_codes.*' => 'string|max:12',
            'filters.event_type' => 'nullable|array',
            'filters.event_type.*' => ['string', Rule::in(array_column(EventType::cases(), 'value'))],
            'filters.event_format' => 'nullable|array',
            'filters.event_format.*' => ['string', Rule::in(array_column(EventFormat::cases(), 'value'))],
            'filters.gender' => ['nullable', 'string', Rule::in(array_column(EventGenderRestriction::cases(), 'value'))],
            'filters.age_group' => 'nullable|array',
            'filters.age_group.*' => ['string', Rule::in(array_column(EventAgeGroup::cases(), 'value'))],
            'filters.starts_after' => 'nullable|date_format:Y-m-d',
            'filters.starts_before' => 'nullable|date_format:Y-m-d',
            'filters.starts_on_local_date' => 'nullable|date_format:Y-m-d',
            'filters.time_scope' => ['nullable', 'string', Rule::in(['upcoming', 'past', 'all'])],
            'filters.prayer_time' => ['nullable', 'string', Rule::in(array_column(EventPrayerTime::cases(), 'value'))],
            'filters.timing_mode' => ['nullable', 'string', Rule::in(array_column(TimingMode::cases(), 'value'))],
            'filters.starts_time_from' => ['nullable', 'string', 'max:32', $this->validTimeFilterRule()],
            'filters.starts_time_until' => ['nullable', 'string', 'max:32', $this->validTimeFilterRule()],
            'filters.children_allowed' => ['nullable', $this->validBooleanFilterRule()],
            'filters.is_muslim_only' => ['nullable', $this->validBooleanFilterRule()],
            'filters.has_event_url' => ['nullable', $this->validBooleanFilterRule()],
            'filters.has_live_url' => ['nullable', $this->validBooleanFilterRule()],
            'filters.has_end_time' => ['nullable', $this->validBooleanFilterRule()],
            'filters.speaker_ids' => 'nullable|array',
            'filters.speaker_ids.*' => 'uuid|exists:speakers,id',
            'filters.key_person_roles' => 'nullable|array',
            'filters.key_person_roles.*' => ['string', Rule::in(array_keys(EventKeyPersonRole::nonSpeakerOptions()))],
            'filters.person_in_charge_ids' => 'nullable|array',
            'filters.person_in_charge_ids.*' => 'uuid|exists:speakers,id',
            'filters.person_in_charge_search' => 'nullable|string|max:255',
            'filters.moderator_ids' => 'nullable|array',
            'filters.moderator_ids.*' => 'uuid|exists:speakers,id',
            'filters.imam_ids' => 'nullable|array',
            'filters.imam_ids.*' => 'uuid|exists:speakers,id',
            'filters.khatib_ids' => 'nullable|array',
            'filters.khatib_ids.*' => 'uuid|exists:speakers,id',
            'filters.bilal_ids' => 'nullable|array',
            'filters.bilal_ids.*' => 'uuid|exists:speakers,id',
            'filters.topic_ids' => 'nullable|array',
            'filters.topic_ids.*' => 'uuid|exists:tags,id',
            'filters.domain_tag_ids' => 'nullable|array',
            'filters.domain_tag_ids.*' => 'uuid|exists:tags,id',
            'filters.source_tag_ids' => 'nullable|array',
            'filters.source_tag_ids.*' => 'uuid|exists:tags,id',
            'filters.issue_tag_ids' => 'nullable|array',
            'filters.issue_tag_ids.*' => 'uuid|exists:tags,id',
            'filters.reference_ids' => 'nullable|array',
            'filters.reference_ids.*' => 'uuid|exists:references,id',
            'radius_km' => 'nullable|integer|min:1|max:1000',
            'lat' => $partial ? 'nullable|numeric|between:-90,90' : 'nullable|required_with:radius_km|numeric|between:-90,90',
            'lng' => $partial ? 'nullable|numeric|between:-180,180' : 'nullable|required_with:radius_km|numeric|between:-180,180',
            'notify' => $partial
                ? ['sometimes', 'required', Rule::in(['off', 'instant', 'daily', 'weekly'])]
                : ['required', Rule::in(['off', 'instant', 'daily', 'weekly'])],
        ];
    }

    private function validTimeFilterRule(): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_string($value) || trim($value) === '') {
                return;
            }

            try {
                now()->setTimeFromTimeString(trim($value));
            } catch (\Throwable) {
                $fail('The '.$attribute.' must be a valid time.');
            }
        };
    }

    private function validBooleanFilterRule(): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }

            if (is_bool($value) || in_array($value, [1, '1', 'true', 'on', 'yes', 0, '0', 'false', 'off', 'no'], true)) {
                return;
            }

            $fail('The '.$attribute.' field must be boolean-like.');
        };
    }

    /**
     * @return list<string>
     */
    private function allowedFilterKeys(): array
    {
        return [
            'country_id',
            'state_id',
            'district_id',
            'subdistrict_id',
            'institution_id',
            'venue_id',
            'speaker_ids',
            'key_person_roles',
            'person_in_charge_ids',
            'person_in_charge_search',
            'moderator_ids',
            'imam_ids',
            'khatib_ids',
            'bilal_ids',
            'domain_tag_ids',
            'topic_ids',
            'source_tag_ids',
            'issue_tag_ids',
            'reference_ids',
            'starts_on_local_date',
            'language_codes',
            'event_type',
            'event_format',
            'gender',
            'starts_after',
            'starts_before',
            'time_scope',
            'prayer_time',
            'timing_mode',
            'starts_time_from',
            'starts_time_until',
            'children_allowed',
            'is_muslim_only',
            'has_event_url',
            'has_live_url',
            'has_end_time',
            'age_group',
        ];
    }
}
