<?php

namespace App\Http\Controllers\Api;

use App\Actions\Events\ResolveEventCheckInStateAction;
use App\Data\Api\Event\EventMeData;
use App\Data\Api\Event\EventPayloadData;
use App\Data\Api\EventCheckIn\EventCheckInStateData;
use App\Data\Api\EventGoing\EventGoingStateData;
use App\Data\Api\EventRegistration\EventRegistrationData;
use App\Data\Api\EventRegistration\EventRegistrationStatusData;
use App\Data\Api\EventSave\EventSaveStateData;
use App\Data\Api\Frontend\Search\EventListData;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventPrayerTime;
use App\Enums\EventVisibility;
use App\Enums\PrayerReference;
use App\Enums\TimingMode;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventCheckin;
use App\Models\Reference;
use App\Models\Registration;
use App\Models\User;
use App\Services\Signals\ProductSignalsService;
use App\Support\Api\ApiPagination;
use App\Support\Timezone\UserDateTimeFormatter;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class EventController extends Controller
{
    public function __construct(
        private readonly ProductSignalsService $productSignalsService,
    ) {}

    /**
     * @var list<string>
     */
    private const array PUBLIC_STATUSES = Event::PUBLIC_STATUSES;

    /**
     * @var list<string>
     */
    private const array EVENT_LIST_FIELDS = [
        'id',
        'slug',
        'title',
        'starts_at',
        'starts_at_local',
        'starts_on_local_date',
        'ends_at',
        'ends_at_local',
        'timing_display',
        'prayer_display_text',
        'end_time_display',
        'visibility',
        'status',
        'status_label',
        'event_type',
        'event_type_label',
        'event_format',
        'event_format_label',
        'reference_study_subtitle',
        'location',
        'is_remote',
        'is_pending',
        'is_cancelled',
        'has_poster',
        'poster_url',
        'card_image_url',
        'institution',
        'venue',
        'speakers',
    ];

    /**
     * List events with filtering, sorting, and includes.
     *
     * Example API calls:
     * /api/v1/events?filter[status]=approved
     * /api/v1/events?filter[event_format]=online
     * /api/v1/events?filter[starts_after]=2026-02-01
     * /api/v1/events?filter[starts_at_after]=2026-02-01T08:00:00Z
     * /api/v1/events?filter[starts_on_local_date]=2026-02-01
     * /api/v1/events?include=venue,speakers
     * /api/v1/events?sort=-starts_at
     * /api/v1/events?filter[search]=kuliah
     */
    #[QueryParameter('fields', 'Optional comma-separated top-level list fields to return. Supported fields: id, slug, title, starts_at, starts_at_local, starts_on_local_date, ends_at, ends_at_local, timing_display, prayer_display_text, end_time_display, visibility, status, status_label, event_type, event_type_label, event_format, event_format_label, reference_study_subtitle, location, is_remote, is_pending, is_cancelled, has_poster, poster_url, card_image_url, institution, venue, speakers.', required: false, type: 'string', infer: false, example: 'id,title,starts_at,starts_at_local,location,card_image_url')]
    public function index(Request $request): JsonResponse
    {
        $requestedFields = $this->requestedFields($request, self::EVENT_LIST_FIELDS);

        /** @var list<AllowedFilter> $allowedFilters */
        $allowedFilters = [
            AllowedFilter::callback('status', function (Builder $query, mixed $value): void {
                $statuses = array_values(array_intersect($this->normalizeArrayFilter($value), self::PUBLIC_STATUSES));

                if ($statuses === []) {
                    $query->whereRaw('1 = 0');

                    return;
                }

                $query->whereIn('status', $statuses);
            }),
            AllowedFilter::exact('visibility'),
            AllowedFilter::exact('event_format'),
            AllowedFilter::exact('institution_id'),
            AllowedFilter::exact('venue_id'),
            AllowedFilter::callback('event_type', function (Builder $query, mixed $value): void {
                $eventTypes = $this->normalizeArrayFilter($value);

                if ($eventTypes === []) {
                    return;
                }

                $query->where(function (Builder $eventTypeQuery) use ($eventTypes): void {
                    foreach ($eventTypes as $eventType) {
                        $eventTypeQuery->orWhereJsonContains('event_type', $eventType);
                    }
                });
            }),
            AllowedFilter::callback('starts_after', function (Builder $query, mixed $value): void {
                $startsAfter = $this->parseDate($value, false);
                if ($startsAfter instanceof Carbon) {
                    $query->where('starts_at', '>=', $startsAfter);
                }
            }),
            AllowedFilter::callback('starts_before', function (Builder $query, mixed $value): void {
                $startsBefore = $this->parseDate($value, true);
                if ($startsBefore instanceof Carbon) {
                    $query->where('starts_at', '<=', $startsBefore);
                }
            }),
            AllowedFilter::callback('starts_at_after', function (Builder $query, mixed $value) use ($request): void {
                $startsAtAfter = $this->parseDateTime($value, $request);

                if ($startsAtAfter instanceof Carbon) {
                    $query->where('starts_at', '>=', $startsAtAfter);
                }
            }),
            AllowedFilter::callback('starts_at_before', function (Builder $query, mixed $value) use ($request): void {
                $startsAtBefore = $this->parseDateTime($value, $request);

                if ($startsAtBefore instanceof Carbon) {
                    $query->where('starts_at', '<=', $startsAtBefore);
                }
            }),
            AllowedFilter::callback('starts_on_local_date', function (Builder $query, mixed $value): void {
                $startsOnLocalDateStart = $this->parseDate($value, false);
                $startsOnLocalDateEnd = $this->parseDate($value, true);

                if ($startsOnLocalDateStart instanceof Carbon && $startsOnLocalDateEnd instanceof Carbon) {
                    $query->whereBetween('starts_at', [$startsOnLocalDateStart, $startsOnLocalDateEnd]);
                }
            }),
            AllowedFilter::callback('ends_after', function (Builder $query, mixed $value): void {
                $endsAfter = $this->parseDate($value, false);
                if ($endsAfter instanceof Carbon) {
                    $query->where('ends_at', '>=', $endsAfter);
                }
            }),
            AllowedFilter::callback('ends_before', function (Builder $query, mixed $value): void {
                $endsBefore = $this->parseDate($value, true);
                if ($endsBefore instanceof Carbon) {
                    $query->where('ends_at', '<=', $endsBefore);
                }
            }),
            AllowedFilter::callback('state_id', function (Builder $query, mixed $value): void {
                $stateIds = $this->normalizeArrayFilter($value);
                if ($stateIds === []) {
                    return;
                }

                $query->whereHas('venue.address', function (Builder $addressQuery) use ($stateIds): void {
                    $addressQuery->whereIn('state_id', $stateIds);
                });
            }),
            AllowedFilter::callback('district_id', function (Builder $query, mixed $value): void {
                $districtIds = $this->normalizeArrayFilter($value);
                if ($districtIds === []) {
                    return;
                }

                $query->whereHas('venue.address', function (Builder $addressQuery) use ($districtIds): void {
                    $addressQuery->whereIn('district_id', $districtIds);
                });
            }),
            AllowedFilter::callback('subdistrict_id', function (Builder $query, mixed $value): void {
                $subdistrictIds = $this->normalizeArrayFilter($value);
                if ($subdistrictIds === []) {
                    return;
                }

                $query->whereHas('venue.address', function (Builder $addressQuery) use ($subdistrictIds): void {
                    $addressQuery->whereIn('subdistrict_id', $subdistrictIds);
                });
            }),
            AllowedFilter::callback('city_id', function (Builder $query, mixed $value): void {
                $cityIds = $this->normalizeArrayFilter($value);
                if ($cityIds === []) {
                    return;
                }

                $query->whereHas('venue.address', function (Builder $addressQuery) use ($cityIds): void {
                    $addressQuery->whereIn('city_id', $cityIds);
                });
            }),
            AllowedFilter::callback('speaker', function (Builder $query, mixed $value): void {
                $speakerIds = $this->normalizeArrayFilter($value);
                if ($speakerIds === []) {
                    return;
                }

                $query->whereHas('speakers', function (Builder $speakerQuery) use ($speakerIds): void {
                    $speakerQuery->whereIn('speakers.id', $speakerIds);
                });
            }),
            AllowedFilter::callback('key_person_roles', function (Builder $query, mixed $value): void {
                $keyPersonRoles = $this->normalizeKeyPersonRoles($value);

                if ($keyPersonRoles === []) {
                    return;
                }

                $query->whereHas('keyPeople', function (Builder $keyPersonQuery) use ($keyPersonRoles): void {
                    $keyPersonQuery->whereIn('role', $keyPersonRoles);
                });
            }),
            AllowedFilter::callback('moderator_ids', function (Builder $query, mixed $value): void {
                $speakerIds = $this->normalizeArrayFilter($value);

                if ($speakerIds === []) {
                    return;
                }

                $query->whereHas('keyPeople', function (Builder $keyPersonQuery) use ($speakerIds): void {
                    $keyPersonQuery
                        ->where('role', EventKeyPersonRole::Moderator->value)
                        ->whereIn('speaker_id', $speakerIds);
                });
            }),
            AllowedFilter::callback('person_in_charge_ids', function (Builder $query, mixed $value): void {
                $speakerIds = $this->normalizeArrayFilter($value);

                if ($speakerIds === []) {
                    return;
                }

                $query->whereHas('keyPeople', function (Builder $keyPersonQuery) use ($speakerIds): void {
                    $keyPersonQuery
                        ->where('role', EventKeyPersonRole::PersonInCharge->value)
                        ->whereIn('speaker_id', $speakerIds);
                });
            }),
            AllowedFilter::callback('person_in_charge_search', function (Builder $query, mixed $value): void {
                $searchTerm = $this->normalizeTextFilter($value);

                if ($searchTerm === null) {
                    return;
                }

                $operator = $this->databaseLikeOperator();

                $query->whereHas('keyPeople', function (Builder $keyPersonQuery) use ($operator, $searchTerm): void {
                    $keyPersonQuery
                        ->where('role', EventKeyPersonRole::PersonInCharge->value)
                        ->where(function (Builder $personInChargeQuery) use ($operator, $searchTerm): void {
                            $personInChargeQuery
                                ->where('name', $operator, "%{$searchTerm}%")
                                ->orWhereHas('speaker', function (Builder $speakerQuery) use ($operator, $searchTerm): void {
                                    $speakerQuery
                                        ->where('speakers.name', $operator, "%{$searchTerm}%")
                                        ->orWhere('speakers.searchable_name', $operator, "%{$searchTerm}%");
                                });
                        });
                });
            }),
            AllowedFilter::callback('imam_ids', function (Builder $query, mixed $value): void {
                $speakerIds = $this->normalizeArrayFilter($value);

                if ($speakerIds === []) {
                    return;
                }

                $query->whereHas('keyPeople', function (Builder $keyPersonQuery) use ($speakerIds): void {
                    $keyPersonQuery
                        ->where('role', EventKeyPersonRole::Imam->value)
                        ->whereIn('speaker_id', $speakerIds);
                });
            }),
            AllowedFilter::callback('khatib_ids', function (Builder $query, mixed $value): void {
                $speakerIds = $this->normalizeArrayFilter($value);

                if ($speakerIds === []) {
                    return;
                }

                $query->whereHas('keyPeople', function (Builder $keyPersonQuery) use ($speakerIds): void {
                    $keyPersonQuery
                        ->where('role', EventKeyPersonRole::Khatib->value)
                        ->whereIn('speaker_id', $speakerIds);
                });
            }),
            AllowedFilter::callback('bilal_ids', function (Builder $query, mixed $value): void {
                $speakerIds = $this->normalizeArrayFilter($value);

                if ($speakerIds === []) {
                    return;
                }

                $query->whereHas('keyPeople', function (Builder $keyPersonQuery) use ($speakerIds): void {
                    $keyPersonQuery
                        ->where('role', EventKeyPersonRole::Bilal->value)
                        ->whereIn('speaker_id', $speakerIds);
                });
            }),
            AllowedFilter::callback('series', function (Builder $query, mixed $value): void {
                $seriesIds = $this->normalizeArrayFilter($value);
                if ($seriesIds === []) {
                    return;
                }

                $query->whereHas('series', function (Builder $seriesQuery) use ($seriesIds): void {
                    $seriesQuery->whereIn('series.id', $seriesIds);
                });
            }),
            AllowedFilter::callback('reference_ids', function (Builder $query, mixed $value): void {
                $referenceIds = Reference::expandRootReferenceIdsForFiltering(
                    collect($this->normalizeArrayFilter($value))
                        ->map(static fn (mixed $referenceId): string => $referenceId)
                        ->filter(static fn (string $referenceId): bool => $referenceId !== '')
                        ->values()
                        ->all(),
                );

                if ($referenceIds === []) {
                    return;
                }

                $query->whereHas('references', function (Builder $referenceQuery) use ($referenceIds): void {
                    $referenceQuery->whereIn('references.id', $referenceIds);
                });
            }),
            AllowedFilter::callback('search', function (Builder $query, mixed $value): void {
                $searchTerm = is_string($value) ? trim($value) : '';
                if ($searchTerm === '') {
                    return;
                }

                $operator = $this->databaseLikeOperator();
                $query->where(function (Builder $searchQuery) use ($searchTerm, $operator): void {
                    $searchQuery
                        ->where('title', $operator, "%{$searchTerm}%")
                        ->orWhereRaw($this->descriptionSearchSql($operator), ["%{$searchTerm}%"]);
                });
            }),
            AllowedFilter::callback('prayer_time', function (Builder $query, mixed $value) use ($request): void {
                $prayerTime = is_string($value) ? trim($value) : '';

                if ($prayerTime === '') {
                    return;
                }

                $prayerTimeGroup = $this->normalizePrayerTimeGroup($prayerTime);

                if ($prayerTimeGroup !== null) {
                    $this->applyPrayerTimeGroupFilter($query, $prayerTimeGroup, $request);

                    return;
                }

                $enum = EventPrayerTime::tryFrom(Str::lower($prayerTime));

                if ($enum instanceof EventPrayerTime) {
                    $prayerTime = $enum->getLabel();
                }

                $normalized = Str::lower($prayerTime);
                $operator = $this->databaseLikeOperator();

                $query
                    ->where('timing_mode', 'prayer_relative')
                    ->where(function (Builder $prayerQuery) use ($normalized, $operator): void {
                        $prayerQuery->whereRaw('LOWER(prayer_display_text) '.$operator.' ?', ["%{$normalized}%"]);

                        $reference = $this->resolvePrayerReference($normalized);

                        if ($reference !== null) {
                            $prayerQuery->orWhere('prayer_reference', $reference);
                        }
                    });
            }),
        ];
        /** @var list<string> $allowedIncludes */
        $allowedIncludes = [
            'venue',
            'venue.address',
            'venue.address.district',
            'venue.address.subdistrict',
            'institution',
            'keyPeople',
            'keyPeople.speaker',
            'speakers',
            'series',
            'mediaLinks',
            'settings',
            'languages',
            'address',
            'address.state',
            'address.district',
            'address.subdistrict',
            'address.city',
        ];
        /** @var list<string> $allowedSorts */
        $allowedSorts = [
            'title',
            'starts_at',
            'ends_at',
            'created_at',
            'updated_at',
            'views_count',
        ];

        $events = QueryBuilder::for(Event::query()->with([
            'institution.address',
            'institution.media' => fn ($query) => $query->whereIn('collection_name', ['logo', 'cover']),
            'venue.address',
            'speakers.media' => fn ($query) => $query->where('collection_name', 'avatar'),
            'media' => fn ($query) => $query->where('collection_name', 'poster'),
            'references',
        ]))
            ->allowedFilters(...$allowedFilters)
            ->allowedIncludes(...$allowedIncludes)
            ->allowedSorts(...$allowedSorts)
            ->defaultSort('-starts_at')
            ->where('is_active', true)
            ->whereIn('status', self::PUBLIC_STATUSES)
            ->where('visibility', 'public')
            ->paginate(ApiPagination::normalizePerPage($request->integer('per_page', 20), default: 20, max: 50))
            ->appends($request->query());

        /** @var array<string, mixed> $payload */
        $payload = [
            'data' => $events->getCollection()
                ->map(fn (Event $event): array => $this->sparsePayload($this->serializeEventListPayload($event), $requestedFields))
                ->all(),
            'meta' => [
                'pagination' => ApiPagination::paginationMeta($events),
            ],
        ];

        $user = $request->user();

        $this->productSignalsService->recordSearchExecuted(
            user: $user instanceof User ? $user : null,
            request: $request,
            surface: 'api.events.index',
            query: is_string(data_get($request->query(), 'filter.search')) ? data_get($request->query(), 'filter.search') : null,
            filters: is_array($request->query('filter')) ? $request->query('filter') : [],
            resultCount: $events->total(),
        );

        return response()->json($payload);
    }

    /**
     * Show a single event by ID or slug.
     */
    #[Endpoint(
        title: 'Get an event detail payload',
        description: 'Returns the event detail payload by slug or UUID for active public events, plus active unlisted events when the client already has the direct identifier.',
    )]
    public function show(Request $request, Event $event): JsonResponse
    {
        /** @var list<string> $allowedIncludes */
        $allowedIncludes = [
            'venue',
            'venue.address',
            'venue.address.state',
            'venue.address.district',
            'venue.address.subdistrict',
            'venue.address.city',
            'institution',
            'institution.address',
            'keyPeople',
            'keyPeople.speaker',
            'speakers',
            'series',
            'mediaLinks',
            'settings',
            'languages',
            'donationChannels',
            'address',
            'address.state',
            'address.district',
            'address.subdistrict',
            'address.city',
        ];

        $this->abortUnlessShowVisibleEvent($event);

        $event = QueryBuilder::for(Event::query()->with(['keyPeople.speaker', 'institution.media', 'media', 'references.media']))
            ->allowedIncludes(...$allowedIncludes)
            ->whereKey($event->getKey())
            ->firstOrFail();

        return response()->json(['data' => $this->serializeEventPayload($event)]);
    }

    #[Endpoint(
        title: 'Get current user event state',
        description: 'Returns the authenticated user\'s saved, going, registration, and check-in state for the target active public or unlisted event in one response.',
    )]
    public function me(Request $request, Event $event, ResolveEventCheckInStateAction $resolveEventCheckInStateAction): JsonResponse
    {
        $this->abortUnlessStateVisibleEvent($event);

        $user = $this->currentUser($request);

        $registration = Registration::query()
            ->where('event_id', $event->getKey())
            ->where('user_id', $user->getKey())
            ->where('status', '!=', 'cancelled')
            ->latest('created_at')
            ->first();

        $registrationData = $registration instanceof Registration
            ? EventRegistrationData::fromModel($registration)
            : null;

        $checkInState = $resolveEventCheckInStateAction->handle($event->loadMissing('settings'), $user);

        $isCheckedIn = EventCheckin::query()
            ->where('event_id', $event->getKey())
            ->where('user_id', $user->getKey())
            ->exists();

        $savesCount = (int) ($event->saves_count ?? 0);

        $isSaved = DB::table('event_saves')
            ->where('user_id', $user->getKey())
            ->where('event_id', $event->getKey())
            ->exists();

        $goingCount = (int) ($event->going_count ?? 0);

        $isGoing = DB::table('event_attendees')
            ->where('user_id', $user->getKey())
            ->where('event_id', $event->getKey())
            ->exists();

        return response()->json([
            'data' => EventMeData::fromState(
                saved: EventSaveStateData::fromState($isSaved, $savesCount),
                going: EventGoingStateData::fromState($isGoing, $goingCount),
                registration: EventRegistrationStatusData::fromNullableRegistration($registrationData),
                checkIn: EventCheckInStateData::fromState($isCheckedIn, $checkInState),
            )->toArray(),
            'meta' => [
                'request_id' => $request->header('X-Request-ID', (string) Str::uuid()),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeEventPayload(Event $event): array
    {
        return EventPayloadData::fromModel($event)->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeEventListPayload(Event $event): array
    {
        return EventListData::fromModel($event)->toArray();
    }

    private function abortUnlessShowVisibleEvent(Event $event): void
    {
        $status = (string) $event->getRawOriginal('status');
        $visibility = (string) $event->getRawOriginal('visibility');

        abort_unless(
            $event->is_active
                && in_array($status, self::PUBLIC_STATUSES, true)
                && in_array($visibility, [EventVisibility::Public->value, EventVisibility::Unlisted->value], true),
            404,
        );
    }

    private function abortUnlessStateVisibleEvent(Event $event): void
    {
        $status = (string) $event->getRawOriginal('status');
        $visibility = (string) $event->getRawOriginal('visibility');

        abort_unless(
            $event->is_active
                && in_array($status, self::PUBLIC_STATUSES, true)
                && in_array($visibility, [EventVisibility::Public->value, EventVisibility::Unlisted->value], true),
            404,
        );
    }

    private function currentUser(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * @param  list<string>  $allowedFields
     * @return list<string>|null
     */
    private function requestedFields(Request $request, array $allowedFields): ?array
    {
        $fields = $request->query('fields');

        if (! is_string($fields) || trim($fields) === '') {
            return null;
        }

        $requestedFields = collect(explode(',', $fields))
            ->map(static fn (string $field): string => trim($field))
            ->filter(static fn (string $field): bool => $field !== '')
            ->unique()
            ->values()
            ->all();

        if ($requestedFields === []) {
            throw ValidationException::withMessages([
                'fields' => 'Provide at least one valid comma-separated event field name.',
            ]);
        }

        $unsupportedFields = array_values(array_diff($requestedFields, $allowedFields));

        if ($unsupportedFields !== []) {
            throw ValidationException::withMessages([
                'fields' => 'Unsupported event fields: '.implode(', ', $unsupportedFields).'. Supported fields: '.implode(', ', $allowedFields).'.',
            ]);
        }

        return $requestedFields;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>|null  $fields
     * @return array<string, mixed>
     */
    private function sparsePayload(array $payload, ?array $fields): array
    {
        if ($fields === null) {
            return $payload;
        }

        return collect($fields)
            ->mapWithKeys(fn (string $field): array => array_key_exists($field, $payload) ? [$field => $payload[$field]] : [])
            ->all();
    }

    private function parseDate(mixed $value, bool $endOfDay): ?Carbon
    {
        $date = UserDateTimeFormatter::parseUserDateToUtc($value, $endOfDay);

        return $date instanceof Carbon ? $date : null;
    }

    private function parseDateTime(mixed $value, ?Request $request = null): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $normalized = trim($value);
        $hasExplicitTimezone = preg_match('/(?:Z|[+\-]\d{2}:?\d{2})$/', $normalized) === 1;

        try {
            $parsed = $hasExplicitTimezone
                ? Carbon::parse($normalized)
                : Carbon::parse($normalized, UserDateTimeFormatter::resolveTimezone($request));
        } catch (\Throwable) {
            return null;
        }

        return $parsed->utc();
    }

    private function resolvePrayerReference(string $prayerTime): ?string
    {
        if (Str::contains($prayerTime, ['jumaat', 'friday'])) {
            return 'friday_prayer';
        }

        if (Str::contains($prayerTime, ['maghrib'])) {
            return 'maghrib';
        }

        if (Str::contains($prayerTime, ['asar', 'asr'])) {
            return 'asr';
        }

        if (Str::contains($prayerTime, ['subuh', 'fajr'])) {
            return 'fajr';
        }

        if (Str::contains($prayerTime, ['zohor', 'zuhur', 'dhuhr'])) {
            return 'dhuhr';
        }

        if (Str::contains($prayerTime, ['isyak', 'isha'])) {
            return 'isha';
        }

        return null;
    }

    private function normalizePrayerTimeGroup(string $prayerTime): ?string
    {
        $normalized = Str::lower(trim($prayerTime));

        return match (true) {
            in_array($normalized, ['subuh', 'fajr'], true) => 'subuh',
            $normalized === 'dhuha' => 'dhuha',
            in_array($normalized, ['jumaat', 'friday'], true) => 'jumaat',
            in_array($normalized, ['zuhur', 'zohor', 'dhuhr'], true) => 'zuhur',
            in_array($normalized, ['asar', 'asr'], true) => 'asar',
            $normalized === 'maghrib' => 'maghrib',
            in_array($normalized, ['isya', 'isyak', 'isha'], true) => 'isya',
            default => null,
        };
    }

    /**
     * @param  Builder<Event>  $query
     */
    private function applyPrayerTimeGroupFilter(Builder $query, string $group, Request $request): void
    {
        match ($group) {
            'subuh' => $this->applyPrayerReferenceOrLabelFilter($query, PrayerReference::Fajr, ['subuh', 'fajr']),
            'jumaat' => $this->applyPrayerReferenceOrLabelFilter($query, PrayerReference::FridayPrayer, ['jumaat', 'friday']),
            'zuhur' => $this->applyPrayerReferenceOrLabelFilter($query, PrayerReference::Dhuhr, ['zohor', 'zuhur', 'dhuhr']),
            'asar' => $this->applyPrayerReferenceOrLabelFilter($query, PrayerReference::Asr, ['asar', 'asr']),
            'maghrib' => $this->applyPrayerReferenceOrLabelFilter($query, PrayerReference::Maghrib, ['maghrib']),
            'isya' => $this->applyPrayerReferenceOrLabelFilter($query, PrayerReference::Isha, ['isya', 'isyak', 'isha']),
            'dhuha' => $this->applyDhuhaPrayerTimeFilter($query, $request),
            default => null,
        };
    }

    /**
     * @param  list<string>  $terms
     */
    /**
     * @param  Builder<Event>  $query
     * @param  list<string>  $terms
     */
    private function applyPrayerReferenceOrLabelFilter(Builder $query, PrayerReference $reference, array $terms): void
    {
        $operator = $this->databaseLikeOperator();

        $query->where(function (Builder $prayerQuery) use ($reference, $terms, $operator): void {
            $prayerQuery->where('prayer_reference', $reference->value)
                ->orWhere(function (Builder $labelQuery) use ($terms, $operator): void {
                    foreach ($terms as $index => $term) {
                        if ($index === 0) {
                            $labelQuery->whereRaw('LOWER(prayer_display_text) '.$operator.' ?', ["%{$term}%"]);

                            continue;
                        }

                        $labelQuery->orWhereRaw('LOWER(prayer_display_text) '.$operator.' ?', ["%{$term}%"]);
                    }
                });
        });
    }

    /**
     * @param  Builder<Event>  $query
     */
    private function applyDhuhaPrayerTimeFilter(Builder $query, Request $request): void
    {
        $operator = $this->databaseLikeOperator();
        $startsAtUserTimeSql = $this->startsAtUserTimeSqlExpression($this->userUtcOffsetMinutes($request));

        $query->where(function (Builder $dhuhaQuery) use ($operator, $startsAtUserTimeSql): void {
            $dhuhaQuery
                ->where(function (Builder $relativeQuery) use ($operator): void {
                    $relativeQuery
                        ->where('timing_mode', TimingMode::PrayerRelative->value)
                        ->where(function (Builder $labelQuery) use ($operator): void {
                            $labelQuery->whereRaw('LOWER(prayer_display_text) '.$operator.' ?', ['%dhuha%'])
                                ->orWhereRaw('LOWER(prayer_display_text) '.$operator.' ?', ['%pagi%'])
                                ->orWhereRaw('LOWER(prayer_display_text) '.$operator.' ?', ['%morning%']);
                        })
                        ->where(function (Builder $excludeQuery): void {
                            $excludeQuery->whereNull('prayer_reference')
                                ->orWhereNotIn('prayer_reference', [
                                    PrayerReference::Fajr->value,
                                    PrayerReference::Dhuhr->value,
                                    PrayerReference::FridayPrayer->value,
                                ]);
                        });
                })
                ->orWhere(function (Builder $absoluteQuery) use ($startsAtUserTimeSql): void {
                    $absoluteQuery
                        ->where('timing_mode', TimingMode::Absolute->value)
                        ->whereRaw("{$startsAtUserTimeSql} >= ?", ['07:30'])
                        ->whereRaw("{$startsAtUserTimeSql} < ?", ['11:30']);
                });
        });
    }

    /**
     * @return list<string>
     */
    private function normalizeArrayFilter(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value) && str_contains($value, ',')) {
            $value = array_map(trim(...), explode(',', $value));
        }

        $values = is_array($value) ? $value : [$value];

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => (string) $item, $values),
            static fn (string $item): bool => $item !== ''
        ));
    }

    /**
     * @return list<string>
     */
    private function normalizeKeyPersonRoles(mixed $value): array
    {
        return array_values(array_filter(
            array_map(
                static fn (string $role): ?string => EventKeyPersonRole::tryFrom($role)?->value,
                $this->normalizeArrayFilter($value)
            )
        ));
    }

    private function normalizeTextFilter(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function databaseLikeOperator(): string
    {
        return $this->databaseDriver() === 'pgsql' ? 'ILIKE' : 'LIKE';
    }

    private function descriptionSearchSql(string $operator): string
    {
        return match ($this->databaseDriver()) {
            'pgsql' => "COALESCE(description::text, '') {$operator} ?",
            'mysql', 'mariadb' => "COALESCE(CAST(description AS CHAR), '') {$operator} ?",
            default => "COALESCE(CAST(description AS TEXT), '') {$operator} ?",
        };
    }

    private function databaseDriver(): string
    {
        /** @var Connection $connection */
        $connection = Event::query()->getConnection();

        return $connection->getDriverName();
    }

    private function startsAtUserTimeSqlExpression(int $offsetMinutes): string
    {
        $safeOffsetMinutes = $offsetMinutes;

        return match ($this->databaseDriver()) {
            'pgsql' => "to_char(events.starts_at + interval '{$safeOffsetMinutes} minutes', 'HH24:MI')",
            'mysql', 'mariadb' => "DATE_FORMAT(DATE_ADD(events.starts_at, INTERVAL {$safeOffsetMinutes} MINUTE), '%H:%i')",
            default => "strftime('%H:%M', datetime(events.starts_at, '{$safeOffsetMinutes} minutes'))",
        };
    }

    private function userUtcOffsetMinutes(?Request $request = null): int
    {
        return now(UserDateTimeFormatter::resolveTimezone($request))->utcOffset();
    }
}
