<?php

namespace App\Http\Controllers\Api\Frontend;

use App\Actions\Events\CreateAdvancedParentProgramAction;
use App\Actions\Events\PrepareAdvancedParentProgramSubmissionAction;
use App\Enums\EventFormat;
use App\Enums\EventType;
use App\Enums\EventVisibility;
use App\Enums\RegistrationMode;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group(
    'Advanced Event',
    'Authenticated parent-program creation flow. '
    .'Use this when you need to create a reusable parent event and then attach child event submissions to it.',
    weight: 31,
)]
class AdvancedEventController extends FrontendController
{
    #[Endpoint(
        title: 'Create an advanced parent program',
        description: 'Creates an authenticated parent program and returns the next submit-event endpoint to use for child sessions. '
            .'Fetch `GET /forms/advanced-events` first to discover the exact required fields and option catalogs.',
    )]
    public function store(
        Request $request,
        PrepareAdvancedParentProgramSubmissionAction $prepareAdvancedParentProgramSubmissionAction,
        CreateAdvancedParentProgramAction $createAdvancedParentProgramAction,
    ): JsonResponse {
        $user = $this->requireUser($request);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'timezone' => ['required', 'timezone'],
            'program_starts_at' => ['required', 'date'],
            'program_ends_at' => ['required', 'date'],
            'organizer_type' => ['required', Rule::in(['institution', 'speaker'])],
            'organizer_id' => ['required', 'uuid'],
            'location_institution_id' => ['nullable', 'uuid'],
            'default_event_type' => ['required', Rule::in(array_column(EventType::cases(), 'value'))],
            'default_event_format' => ['required', Rule::in(array_column(EventFormat::cases(), 'value'))],
            'visibility' => ['required', Rule::in(array_column(EventVisibility::cases(), 'value'))],
            'registration_required' => ['required', 'boolean'],
            'registration_mode' => ['required', Rule::in(array_column(RegistrationMode::cases(), 'value'))],
        ]);

        $preparedSubmission = $prepareAdvancedParentProgramSubmissionAction->handle($user, $validated);
        $parentEvent = $createAdvancedParentProgramAction->handle(
            $user,
            $validated,
            $preparedSubmission['program_starts_at'],
            $preparedSubmission['program_ends_at'],
            $preparedSubmission['timezone'],
            $preparedSubmission['organizer_type'],
            $preparedSubmission['organizer_id'],
            $preparedSubmission['location_institution_id'],
        );

        return response()->json([
            'data' => [
                'parent_event' => [
                    'id' => $parentEvent->getKey(),
                    'slug' => $parentEvent->slug,
                    'title' => $parentEvent->title,
                    'status' => (string) $parentEvent->status,
                ],
                'next_submit_event_endpoint' => route('api.client.forms.submit-event', ['parent_event_id' => $parentEvent->getKey()]),
            ],
            'meta' => [
                'request_id' => $this->requestId($request),
            ],
        ], 201);
    }
}
