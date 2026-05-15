<?php

namespace App\Http\Controllers\Api\Frontend;

use App\Actions\Events\SubmitFrontendEventAction;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventPrayerTime;
use App\Enums\EventType;
use App\Enums\EventVisibility;
use App\Models\Event;
use App\Models\Institution;
use App\Models\User;
use App\Support\Api\Frontend\FrontendMediaSyncService;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

#[Group(
    'Event Submission',
    'Public event submission flow for guests and authenticated users. '
    .'Use the paired form-contract endpoint to discover required fields, defaults, catalogs, and conditional rules before posting.',
    weight: 30,
)]
class EventSubmissionController extends FrontendController
{
    #[Endpoint(
        title: 'Submit a public event',
        description: 'Creates a new event submission. '
            .'This route is create-only; use the contribution suggestion endpoints for later event updates. '
            .'Clients must provide an explicit submission country using `submission_country_id`. '
            .'Fetch `GET /forms/submit-event` first to resolve required versus optional fields, conditional rules, catalogs, and guest-contact requirements. '
            .'Event media roles are strict: `cover` is the 16:9 website/app image, while `poster` is the 4:5 external flyer image.',
    )]
    public function store(
        Request $request,
        SubmitFrontendEventAction $submitFrontendEventAction,
        FrontendMediaSyncService $frontendMediaSyncService,
    ): JsonResponse {
        $user = $this->currentUser($request);
        $maxUploadSizeKb = (int) ceil(((int) config('media-library.max_file_size', 10 * 1024 * 1024)) / 1024);

        $validated = $request->validate([
            'parent_event_id' => ['nullable', 'uuid'],
            'scoped_institution_id' => ['nullable', 'uuid'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable'],
            'event_type' => ['required', 'array', 'min:1'],
            'event_type.*' => ['string', Rule::in(array_column(EventType::cases(), 'value'))],
            'event_date' => ['required', 'date'],
            'prayer_time' => ['required', 'string', Rule::in(array_column(EventPrayerTime::cases(), 'value'))],
            'custom_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'event_format' => ['required', 'string', Rule::in(array_column(EventFormat::cases(), 'value'))],
            'visibility' => ['required', 'string', Rule::in(array_column(EventVisibility::cases(), 'value'))],
            'event_url' => ['nullable', 'url', 'max:255'],
            'live_url' => ['nullable', 'url', 'max:255'],
            'gender' => ['required', 'string', Rule::in(array_column(EventGenderRestriction::cases(), 'value'))],
            'age_group' => ['required', 'array', 'min:1'],
            'age_group.*' => ['string', Rule::in(array_column(EventAgeGroup::cases(), 'value'))],
            'children_allowed' => ['nullable', 'boolean'],
            'is_muslim_only' => ['nullable', 'boolean'],
            'languages' => ['required', 'array', 'min:1'],
            'languages.*' => ['integer'],
            'domain_tags' => ['nullable', 'array'],
            'domain_tags.*' => ['string', 'max:255'],
            'discipline_tags' => ['nullable', 'array'],
            'discipline_tags.*' => ['string', 'max:255'],
            'source_tags' => ['nullable', 'array'],
            'source_tags.*' => ['string', 'max:255'],
            'issue_tags' => ['nullable', 'array'],
            'issue_tags.*' => ['string', 'max:255'],
            'references' => ['nullable', 'array'],
            'references.*' => ['uuid'],
            'organizer_type' => ['required', 'string', Rule::in(['institution', 'speaker'])],
            'organizer_institution_id' => ['nullable', 'uuid'],
            'organizer_speaker_id' => ['nullable', 'uuid'],
            'location_same_as_institution' => ['nullable', 'boolean'],
            'location_type' => ['nullable', 'string', Rule::in(['institution', 'venue'])],
            'location_institution_id' => ['nullable', 'uuid'],
            'location_venue_id' => ['nullable', 'uuid'],
            'space_id' => ['nullable', 'uuid'],
            'speakers' => ['nullable', 'array'],
            'speakers.*' => ['uuid'],
            'other_key_people' => ['nullable', 'array'],
            'other_key_people.*.role' => ['required_with:other_key_people', 'string', 'max:255'],
            'other_key_people.*.speaker_id' => ['nullable', 'uuid'],
            'other_key_people.*.name' => ['nullable', 'string', 'max:255'],
            'other_key_people.*.is_public' => ['nullable', 'boolean'],
            'other_key_people.*.notes' => ['nullable', 'string', 'max:1000'],
            'submission_country_id' => ['required', 'integer'],
            'submitter_name' => [$user instanceof User ? 'nullable' : 'required', 'string', 'max:255'],
            'submitter_email' => ['nullable', 'email', 'max:255'],
            'submitter_phone' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'captcha_token' => ['nullable', 'string'],
            'cover' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'dimensions:ratio=16/9', "max:{$maxUploadSizeKb}"],
            'poster' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'dimensions:ratio=4/5', "max:{$maxUploadSizeKb}"],
            'gallery' => ['nullable', 'array', 'max:10'],
            'gallery.*' => ['image', 'mimes:jpg,jpeg,png,webp', "max:{$maxUploadSizeKb}"],
        ]);

        $this->assertGuestContactRules($validated, $user);

        $parentEvent = $this->resolveParentEvent($validated['parent_event_id'] ?? null, $validated['scoped_institution_id'] ?? null, $user);
        $scopedInstitution = $this->resolveScopedInstitution($validated['scoped_institution_id'] ?? null, $user);

        $result = $submitFrontendEventAction->handle(
            state: $validated,
            request: $request,
            submitter: $user,
            parentEvent: $parentEvent,
            scopedInstitution: $scopedInstitution,
            persistRelationships: function (Event $event) use ($request, $frontendMediaSyncService): void {
                $frontendMediaSyncService->syncSingle($event, $request->file('cover'), 'cover');
                $frontendMediaSyncService->syncSingle($event, $request->file('poster'), 'poster');
                $frontendMediaSyncService->syncMultiple(
                    $event,
                    is_array($request->file('gallery')) ? $request->file('gallery') : null,
                    'gallery',
                );
            },
        );

        /** @var Event $event */
        $event = $result['event'];

        return response()->json([
            'data' => [
                'event' => [
                    'id' => $event->getKey(),
                    'slug' => $event->slug,
                    'title' => $event->title,
                    'status' => (string) $event->status,
                    'visibility' => $result['visibility'],
                ],
                'submission' => [
                    'id' => $result['submission']->getKey(),
                    'auto_approved' => $result['auto_approved'],
                ],
            ],
            'meta' => [
                'request_id' => $this->requestId($request),
            ],
        ], 201);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function assertGuestContactRules(array $validated, ?User $user): void
    {
        if ($user instanceof User) {
            return;
        }

        $hasEmail = filled($validated['submitter_email'] ?? null);
        $hasPhone = filled($validated['submitter_phone'] ?? null);

        if (! $hasEmail && ! $hasPhone) {
            throw ValidationException::withMessages([
                'submitter_email' => __('Either submitter email or submitter phone is required.'),
                'submitter_phone' => __('Either submitter email or submitter phone is required.'),
            ]);
        }
    }

    private function resolveScopedInstitution(mixed $institutionId, ?User $user): ?Institution
    {
        if (! is_string($institutionId) || ! Str::isUuid($institutionId)) {
            return null;
        }

        abort_unless($user instanceof User, 403);

        $institution = Institution::query()
            ->whereKey($institutionId)
            ->whereHas('members', fn ($query) => $query->whereKey($user->getKey()))
            ->first();

        abort_unless($institution instanceof Institution, 403);

        return $institution;
    }

    private function resolveParentEvent(mixed $parentEventId, mixed $scopedInstitutionId, ?User $user): ?Event
    {
        if (! is_string($parentEventId) || ! Str::isUuid($parentEventId)) {
            return null;
        }

        $parentEvent = Event::query()
            ->with(['institution:id,name', 'settings'])
            ->find($parentEventId);

        abort_unless($parentEvent instanceof Event && $parentEvent->isParentProgram(), 404);

        $scopedInstitution = $this->resolveScopedInstitution($scopedInstitutionId, $user);

        if (
            $scopedInstitution instanceof Institution
            && ! $this->parentEventMatchesScopedInstitution($parentEvent, $scopedInstitution)
        ) {
            abort(403);
        }

        return $parentEvent;
    }

    private function parentEventMatchesScopedInstitution(Event $parentEvent, Institution $institution): bool
    {
        if ($parentEvent->institution_id === $institution->getKey()) {
            return true;
        }

        return $parentEvent->organizer_type === Institution::class
            && $parentEvent->organizer_id === $institution->getKey();
    }
}
