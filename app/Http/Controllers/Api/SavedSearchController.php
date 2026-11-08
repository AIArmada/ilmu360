<?php

namespace App\Http\Controllers\Api;

use AIArmada\Events\Models\EventTaxonomy;
use AIArmada\Events\Models\EventTerm;
use App\Actions\Events\Search\ExecuteSavedSearchAction;
use App\Actions\SavedSearches\CreateSavedSearchAction;
use App\Actions\SavedSearches\DeleteSavedSearchWithAttributionReversalAction;
use App\Enums\EventTaxonomyCode;
use App\Features\SavedSearches\Api\SavedSearchCommands;
use App\Features\SavedSearches\Api\SavedSearchReader;
use App\Features\SavedSearches\Api\SavedSearchSnapshot;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Shared\Discovery\SearchFilterCatalog;
use App\Shared\Integrations\SavedSearchLimitReachedException;
use App\Support\Api\ApiPagination;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

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
    ): JsonResponse {
        $this->authorize('update', $savedSearch);

        $validated = $request->validate($this->savedSearchValidationRules(partial: true));

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
    private function savedSearchValidationRules(bool $partial = false): array
    {
        return [
            'name' => $partial ? 'sometimes|required|string|max:100' : 'required|string|max:100',
            'query' => 'nullable|string|max:255',
            'filters' => 'nullable|array:'.implode(',', $this->allowedFilterKeys()),
            'filters.country_id' => 'nullable|uuid|exists:'.config('addressing.tables.countries', 'countries').',id',
            'filters.state_id' => 'nullable|uuid|exists:states,id',
            'filters.city_id' => 'nullable|uuid|exists:cities,id',
            'filters.area_assignments' => 'nullable|array',
            'filters.area_assignments.*' => 'nullable|uuid|exists:address_areas,id',
            'filters.institution_id' => 'nullable|uuid|exists:institutions,id',
            'filters.venue_id' => 'nullable|uuid|exists:venues,id',
            'filters.language_codes' => 'nullable|array',
            'filters.language_codes.*' => 'string|max:12',
            'filters.event_category_ids' => 'nullable|array',
            'filters.event_category_ids.*' => ['uuid', Rule::in(app(SearchFilterCatalog::class)->validCategoryIds((array) request()->input('filters.event_category_ids', [])))],
            'filters.event_format' => 'nullable|array',
            'filters.event_format.*' => ['string', Rule::in(app(SearchFilterCatalog::class)->allowedValues('event_format'))],
            'filters.gender' => ['nullable', 'string', Rule::in(app(SearchFilterCatalog::class)->allowedValues('gender'))],
            'filters.age_group' => 'nullable|array',
            'filters.age_group.*' => ['string', Rule::in(app(SearchFilterCatalog::class)->allowedValues('age_group'))],
            'filters.starts_after' => 'nullable|date_format:Y-m-d',
            'filters.starts_before' => 'nullable|date_format:Y-m-d',
            'filters.starts_on_local_date' => 'nullable|date_format:Y-m-d',
            'filters.time_scope' => ['nullable', 'string', Rule::in(app(SearchFilterCatalog::class)->allowedValues('time_scope'))],
            'filters.prayer_time' => ['nullable', 'string', Rule::in(app(SearchFilterCatalog::class)->allowedValues('prayer_time'))],
            'filters.timing_mode' => ['nullable', 'string', Rule::in(app(SearchFilterCatalog::class)->allowedValues('timing_mode'))],
            'filters.starts_time_from' => ['nullable', 'string', 'max:32', $this->validTimeFilterRule()],
            'filters.starts_time_until' => ['nullable', 'string', 'max:32', $this->validTimeFilterRule()],
            'filters.children_allowed' => ['nullable', $this->validBooleanFilterRule()],
            'filters.is_muslim_only' => ['nullable', $this->validBooleanFilterRule()],
            'filters.has_event_url' => ['nullable', $this->validBooleanFilterRule()],
            'filters.has_live_url' => ['nullable', $this->validBooleanFilterRule()],
            'filters.has_end_time' => ['nullable', $this->validBooleanFilterRule()],
            'filters.person_ids' => 'nullable|array',
            'filters.person_ids.*' => 'uuid|exists:persons,id',
            'filters.key_person_roles' => 'nullable|array',
            'filters.key_person_roles.*' => ['string', Rule::in(app(SearchFilterCatalog::class)->allowedValues('key_person_roles'))],
            'filters.person_in_charge_ids' => 'nullable|array',
            'filters.person_in_charge_ids.*' => 'uuid|exists:persons,id',
            'filters.person_in_charge_search' => 'nullable|string|max:255',
            'filters.person_name_search' => 'nullable|string|max:255',
            'filters.moderator_ids' => 'nullable|array',
            'filters.moderator_ids.*' => 'uuid|exists:persons,id',
            'filters.imam_ids' => 'nullable|array',
            'filters.imam_ids.*' => 'uuid|exists:persons,id',
            'filters.khatib_ids' => 'nullable|array',
            'filters.khatib_ids.*' => 'uuid|exists:persons,id',
            'filters.bilal_ids' => 'nullable|array',
            'filters.bilal_ids.*' => 'uuid|exists:persons,id',
            'filters.discipline_tag_ids' => 'nullable|array',
            'filters.discipline_tag_ids.*' => ['uuid', $this->taxonomyTermExistsRule(EventTaxonomyCode::Discipline)],
            'filters.domain_tag_ids' => 'nullable|array',
            'filters.domain_tag_ids.*' => ['uuid', $this->taxonomyTermExistsRule(EventTaxonomyCode::Domain)],
            'filters.source_tag_ids' => 'nullable|array',
            'filters.source_tag_ids.*' => ['uuid', $this->taxonomyTermExistsRule(EventTaxonomyCode::Source)],
            'filters.issue_tag_ids' => 'nullable|array',
            'filters.issue_tag_ids.*' => ['uuid', $this->taxonomyTermExistsRule(EventTaxonomyCode::Issue)],
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

    private function taxonomyTermExistsRule(EventTaxonomyCode $taxonomy): Exists
    {
        return Rule::exists(EventTerm::class, 'id')
            ->where(static function (Builder $query) use ($taxonomy): void {
                $query->whereIn(
                    (new EventTerm)->qualifyColumn('event_taxonomy_id'),
                    EventTaxonomy::query()->select('id')->where('code', $taxonomy->value),
                );
            });
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
            'city_id',
            'area_assignments',
            'institution_id',
            'venue_id',
            'person_ids',
            'key_person_roles',
            'person_in_charge_ids',
            'person_in_charge_search',
            'person_name_search',
            'moderator_ids',
            'imam_ids',
            'khatib_ids',
            'bilal_ids',
            'domain_tag_ids',
            'discipline_tag_ids',
            'source_tag_ids',
            'issue_tag_ids',
            'reference_ids',
            'starts_on_local_date',
            'language_codes',
            'event_category_ids',
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
