<?php

namespace App\Http\Controllers\Api\Frontend;

use App\Actions\Events\CreateAdvancedEventAction;
use App\Actions\Events\PrepareAdvancedParentProgramSubmissionAction;
use App\Enums\EventFormat;
use App\Enums\EventType;
use App\Enums\EventVisibility;
use App\Enums\RegistrationScope;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group(
    'Advanced Event',
    'Authenticated event, occurrence, and session creation flow. '
    .'Create an event container with its first occurrence, then add sessions beneath that occurrence.',
    weight: 31,
)]
class AdvancedEventController extends FrontendController
{
    #[Endpoint(
        title: 'Create an advanced event',
        description: 'Creates an authenticated event with its first occurrence and returns the session-submission endpoint. '
            .'Fetch `GET /forms/advanced-events` first to discover the exact required fields and option catalogs.',
    )]
    public function store(
        Request $request,
        PrepareAdvancedParentProgramSubmissionAction $prepareAdvancedParentProgramSubmissionAction,
        CreateAdvancedEventAction $createAdvancedEventAction,
    ): JsonResponse {
        $user = $this->requireUser($request);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'timezone' => ['required', 'timezone'],
            'program_starts_at' => ['required', 'date'],
            'program_ends_at' => ['required', 'date'],
            'primary_organizer_id' => ['required', 'uuid'],
            'location_institution_id' => ['nullable', 'uuid'],
            'default_event_type' => ['required', Rule::in(array_column(EventType::cases(), 'value'))],
            'default_event_format' => ['required', Rule::in(array_column(EventFormat::cases(), 'value'))],
            'visibility' => ['required', Rule::in(array_column(EventVisibility::cases(), 'value'))],
            'registration_required' => ['required', 'boolean'],
            'registration_mode' => ['required', Rule::in(array_column(RegistrationScope::cases(), 'value'))],
        ]);

        $preparedSubmission = $prepareAdvancedParentProgramSubmissionAction->handle($user, $validated);
        $event = $createAdvancedEventAction->handle(
            $user,
            $validated,
            $preparedSubmission['program_starts_at'],
            $preparedSubmission['program_ends_at'],
            $preparedSubmission['timezone'],
            $preparedSubmission['primary_organizer'],
            $preparedSubmission['location_institution_id'],
        );

        return response()->json([
            'data' => [
                'event' => [
                    'id' => $event->getKey(),
                    'slug' => $event->slug,
                    'title' => $event->title,
                    'status' => (string) $event->status,
                ],
                'next_submit_event_endpoint' => route('api.client.forms.submit-event', ['event_id' => $event->getKey()]),
            ],
            'meta' => [
                'request_id' => $this->requestId($request),
            ],
        ], 201);
    }
}
